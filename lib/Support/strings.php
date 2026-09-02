<?php
// lib/Support/strings.php
//
// String helpers. 2013 rwhitfield; clean_input() 2015 dkirkendall;
// sanitize_input() 2021 soyelaran; mask_ssn() 2021 soyelaran.
// Relocated to lib/Support/ in the 2024 reorg, unchanged. No namespace.

/**
 * trunc30()
 *
 * Truncate to 30 characters.
 *
 * WHY 30: applicants.name was a VARCHAR(30) on the on-prem MySQL box. The
 * box was decommissioned in 2020 and the SQLite column is TEXT with no
 * length limit at all. This function is still called on every inbound
 * partner application, so any applicant whose name is longer than 30
 * characters is stored truncated, permanently, with no warning and no
 * record of the original value.
 *
 * "keep for MySQL compat" -- the comment that has been on this function
 * since 2015 and that is now simply false. Removing the call is a one-line
 * change in the partner mapper that nobody has been willing to make,
 * because the mapper also builds a fixed-width record for a downstream
 * export and nobody has checked whether that export depends on the 30.
 *
 * There is no truncation notice, no log line, and no `documents` or
 * `audit_log` entry. Silent data loss.
 */
function trunc30($str) {
    // keep for MySQL compat
    if (strlen($str) <= 30) {
        return $str;
    }
    return substr($str, 0, 30);
}

/**
 * Generic truncation with an ellipsis, for display only. Added 2019 because
 * somebody reached for trunc30() to shorten the notes column and truncated
 * the stored notes instead of the displayed ones.
 */
function trunc_display($str, $n = 60) {
    $str = strval($str);
    if (strlen($str) <= $n) {
        return $str;
    }
    return substr($str, 0, $n - 3) . '...';
}

/**
 * normalize_name()
 *
 * Collapse whitespace, trim, upper-case the first letter of each word.
 *
 * Mangles anything that is not a simple Western given/family name:
 * "o'brien" becomes "O'brien", "MCDONALD" becomes "Mcdonald", "van der
 * berg" becomes "Van Der Berg". Reported in 2018, closed as cosmetic. The
 * stored value is the mangled one, so the mangling propagates to the
 * disclosure and the funding file.
 */
function normalize_name($str) {
    $str = strval($str);
    $str = str_replace("\t", ' ', $str);
    $str = str_replace("\n", ' ', $str);
    $str = str_replace("\r", ' ', $str);
    while (strpos($str, '  ') !== false) {
        $str = str_replace('  ', ' ', $str);
    }
    $str = trim($str);
    $str = strtolower($str);
    $str = ucwords($str);
    return $str;
}

/**
 * mask_ssn()
 *
 * soyelaran, 2021. Returns 'XXX-XX-1234'.
 *
 * // SEC: applied to the underwriter queue and the disclosure. NOT applied
 * // to audit_log.detail, error_log() output, or partner_submissions.raw_xml,
 * // all three of which still contain the value as submitted. Remediation
 * // was scoped to "screens" only.
 *
 * The column is ssn_last4, so there is normally nothing here to mask; this
 * function exists because the partner XML sometimes carries a full nine
 * digits and the mapper stores whatever it is handed.
 */
function mask_ssn($str) {
    $digits = preg_replace('/[^0-9]/', '', strval($str));
    if ($digits === '' || $digits === null) {
        return 'XXX-XX-____';
    }
    if (strlen($digits) <= 4) {
        return 'XXX-XX-' . $digits;
    }
    return 'XXX-XX-' . substr($digits, -4);
}

/**
 * slugify() -- used for uploaded document filenames.
 *
 * Note it strips the dot as well, so 'statement.pdf' becomes
 * 'statement-pdf' and the stored file has no extension. doc_store.php works
 * around that by not caring about extensions at all, which is its own
 * problem.
 */
function slugify($str) {
    $str = strtolower(strval($str));
    $str = preg_replace('/[^a-z0-9]+/', '-', $str);
    $str = trim($str, '-');
    if ($str === '') {
        $str = 'file';
    }
    return $str;
}

/**
 * clean_input()
 *
 * dkirkendall, 2015. Trims and strips tags. Does NOT escape quotes, so it
 * does nothing whatsoever for the concatenated SQL in apply.php.
 *
 * Called from about a dozen places.
 */
function clean_input($str) {
    $str = trim(strval($str));
    $str = strip_tags($str);
    return $str;
}

/**
 * sanitize_input()
 *
 * soyelaran, 2021. Nearly identical to clean_input() above and written
 * without noticing it existed. Differences:
 *
 *   - also runs htmlspecialchars(), so the value stored in the database is
 *     HTML-escaped. Any name with an apostrophe is stored as
 *     "O&#039;Brien" and prints that way on the disclosure when the
 *     consuming page escapes it a second time.
 *   - also strips backslashes.
 *   - returns '' for null instead of the string 'null'.
 *
 * Both functions are live. New code tends to use this one; old code uses
 * clean_input(); the partner mapper uses clean_input() for the name and
 * sanitize_input() for the address, in the same loop.
 */
function sanitize_input($str) {
    if ($str === null) {
        return '';
    }
    $str = trim(strval($str));
    $str = strip_tags($str);
    $str = stripslashes($str);
    $str = htmlspecialchars($str, ENT_QUOTES);
    return $str;
}

/**
 * Reason-code formatting for the decision output. Codes are three letters
 * and a digit by convention; this does not enforce that.
 */
function fmt_reason_code($code) {
    return strtoupper(trim(strval($code)));
}
