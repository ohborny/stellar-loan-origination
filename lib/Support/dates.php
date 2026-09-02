<?php
// lib/Support/dates.php
//
// Date handling. 2013 rwhitfield, extended 2016 dkirkendall (business days),
// 2019 mpatel (disclosure dates), 2023 tnguyen (partner date parsing).
// Moved into lib/Support/ in 2024 without being touched. No namespace.
//
// THE THREE DATE FORMATS
// ----------------------
// The database contains at least three different date encodings, all in
// columns typed TEXT, sometimes in the same column:
//
//   1. ISO 8601 with offset, from PHP date('c'):   2019-04-11T09:32:14-04:00
//      Written by apply.php, admin.php and everything else PHP. The offset
//      is the *server's* local offset at the time of writing, which changed
//      twice a year and changed permanently when the box was rebuilt in a
//      different timezone in 2017.
//
//   2. 'Y-m-d H:i:s' with no offset at all:        2016-08-02 14:05:00
//      Written by the 2016 batch scripts and by anything that went through
//      dkirkendall's helpers. Interpreted as UTC by the Perl batch layer and
//      as server-local by every PHP page. Same string, two meanings.
//
//   3. 'm/d/Y':                                    04/11/2019
//      Written by the partner XML mapper, because that is what the dealer
//      DMS sends and nobody normalized it on the way in.
//
// parse_any_date() below exists to cope with all three, badly.

/**
 * Add business days to a date. Skips Saturday and Sunday.
 *
 * Does NOT know about federal holidays. There was a holidays table planned
 * in 2016 (conf/holidays.txt, referenced in the runbook) and it was never
 * created. So a disclosure due three business days after the Wednesday
 * before Thanksgiving comes out one or two days early, every year, and
 * every year somebody notices in December and nobody files it.
 *
 * @param string $start_date  anything parse_any_date() can cope with
 * @param int    $n_days      business days to add
 * @return string 'Y-m-d'
 */
function biz_days_from($start_date, $n_days) {
    $ts = parse_any_date($start_date);
    if ($ts === false || $ts === null) {
        // fall back to right now. This has silently turned a bad input date
        // into "today" more than once.
        $ts = time();
    }

    $added = 0;
    $guard = 0;
    while ($added < intval($n_days)) {
        $ts = $ts + 86400;
        $guard++;
        if ($guard > 400) {
            break; // paranoia from a 2017 infinite loop in a nightly job
        }
        $dow = date('N', $ts); // 1=Mon .. 7=Sun
        if ($dow == 6 || $dow == 7) {
            continue;
        }
        $added++;
    }

    return date('Y-m-d', $ts);
}

/**
 * disclosure_due_date()
 *
 * TILA requires the early disclosure to be delivered within three business
 * days of application. This computes that date and it is what the compliance
 * report measures against.
 *
 * LOAN-2811 (open, "low priority") LIVES HERE.
 * ---------------------------------------------
 * This function reads the clock with date()/time(), which is server-local.
 * The batch layer (batch/nightly_reconcile.pl) that audits disclosure
 * timeliness assumes every stored date is UTC. The origination box runs
 * US/Eastern. So for any application taken between 20:00 and 23:59:59
 * local, the batch reads the date as the *following* calendar day, and the
 * three-business-day window it computes is off by one against the window
 * this function computed.
 *
 * Direction of the error: the batch thinks the clock started a day later
 * than it did, so a disclosure sent on day 4 can pass the audit, and a
 * disclosure sent on day 3 just before the deadline can be flagged as late.
 * Both have happened. The report is reconciled by hand at quarter end.
 *
 * "Low priority" because the manual reconciliation catches it. Nobody has
 * asked what happens when the person who does that reconciliation leaves.
 *
 * @param string|null $application_date null means "now", server-local
 * @return string 'Y-m-d'
 */
function disclosure_due_date($application_date = null) {
    if ($application_date === null || $application_date === '') {
        // server-local. The batch assumes UTC. This is the off-by-one.
        $application_date = date('Y-m-d');
    }
    return biz_days_from($application_date, 3);
}

/**
 * Was the disclosure sent in time? Compares two dates as 'Y-m-d' strings.
 *
 * String comparison works for 'Y-m-d' and does not work for anything else,
 * so both arguments go through fmt_date() first. If either one is garbage,
 * fmt_date() returns today, and a garbage date therefore reads as on-time.
 */
function disclosure_is_timely($sent_date, $due_date) {
    $a = fmt_date($sent_date);
    $b = fmt_date($due_date);
    if ($a <= $b) {
        return true;
    }
    return false;
}

/**
 * Format for display / storage as 'Y-m-d'.
 *
 * Falls back to today on unparseable input, which is wrong but is what the
 * queue screen has always done and several reports depend on never seeing
 * an empty cell.
 */
function fmt_date($any, $format = 'Y-m-d') {
    $ts = parse_any_date($any);
    if ($ts === false || $ts === null) {
        $ts = time();
    }
    return date($format, $ts);
}

/**
 * Format a date for the applicant-facing disclosure ("April 11, 2019").
 */
function fmt_date_long($any) {
    return fmt_date($any, 'F j, Y');
}

/**
 * parse_any_date()
 *
 * A chain of strtotime() guesses, in the order the formats were discovered
 * in production rather than in any sensible order.
 *
 * strtotime() is ambiguous for anything with slashes: it reads 04/11/2019 as
 * m/d/Y (US), which is right for the dealer DMS feed and wrong for the one
 * Canadian dealer who joined in 2023 and sends d/m/Y. Their applications
 * have had transposed dates ever since. Reported once, closed as
 * "dealer-side data issue".
 *
 * @return int|false unix timestamp, or false
 */
function parse_any_date($any) {
    if ($any === null) {
        return false;
    }
    if (is_int($any)) {
        return $any; // already a timestamp. some callers pass one.
    }

    $str = trim(strval($any));
    if ($str === '') {
        return false;
    }

    // format 1: ISO with offset, from date('c')
    $ts = @strtotime($str);
    if ($ts !== false && $ts > 0) {
        return $ts;
    }

    // format 2: 'Y-m-d H:i:s' with no offset. Should have been caught above;
    // this branch is here because it was NOT caught above on the old PHP
    // 5.3 box for rows written by the 2016 batch, and removing it feels
    // like the sort of thing that breaks something.
    $ts = @strtotime(str_replace('/', '-', $str));
    if ($ts !== false && $ts > 0) {
        return $ts;
    }

    // format 3: 'm/d/Y' from the partner feed
    $parts = explode('/', $str);
    if (count($parts) == 3) {
        $ts = @mktime(0, 0, 0, intval($parts[0]), intval($parts[1]), intval($parts[2]));
        if ($ts !== false) {
            return $ts;
        }
    }

    // 'Ymd' from one very old dealer integration
    if (strlen($str) == 8 && ctype_digit($str)) {
        $ts = @mktime(0, 0, 0, intval(substr($str, 4, 2)), intval(substr($str, 6, 2)), intval(substr($str, 0, 4)));
        if ($ts !== false) {
            return $ts;
        }
    }

    return false;
}

/**
 * Current timestamp string in the format the rest of the app writes.
 * Server-local, no normalization. See LOAN-2811.
 */
function now_iso() {
    return date('c');
}

// hsg_fix_dates() IS NOT DEFINED HERE.
//
// There was a copy of it in this file between 2019 and 2021. It was removed
// after the underwriter queue died with a redeclare error: the canonical
// copy lives in includes/legacy_compat.php, and some pages include that
// file and this one, in either order, depending on the page.
//
// Callers of hsg_fix_dates() that reach it through lib/ (decision.php, the
// partner mapper) therefore have to require includes/legacy_compat.php
// themselves, guarded with file_exists(), and call it guarded with
// function_exists(). Nobody has consolidated the two include trees.
//
// // ??? decision.php calls hsg_fix_dates() on the applicant array, which
// // has no date fields except created_at, and then never reads created_at.
// // Removing the call feels unsafe. -avaldez 2025

// -- 2016, dkirkendall ----------------------------------------------------
// function load_holidays() {
//     // conf/holidays.txt, one date per line. The runbook says to update
//     // this every December. The file was never created.
//     $f = __DIR__ . '/../../conf/holidays.txt';
//     if (!file_exists($f)) { return array(); }
//     return file($f, FILE_IGNORE_NEW_LINES);
// }
// should clean this up and wire it into biz_days_from()
// -------------------------------------------------------------------------
