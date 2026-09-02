<?php
// partner/submit.php
//
// Partner submission handler: takes a mapped payload from partner/xml_map.php
// and turns it into an applicants row + a loans row + a partner_submissions
// audit row.
//
// 2023-06, tnguyen. LOAN-2077.
//
// THIS IS THE FOURTH PLACE APPLICANT ROWS GET CREATED:
//   1. public/apply.php          (2013, the original)
//   2. public/admin.php          (manual entry screen, 2015)
//   3. tools/import_csv.php      (2019 bulk load for the auto launch)
//   4. here                      (2023)
//
// It is the fourth because the alternative was to POST to apply.php from
// this file and screen-scrape the HTML response for the decision, which is
// what the first prototype did. This is not better, it is only less
// obviously bad. Both were considered acceptable for a two-week launch.
//
// LOAN-2210 ("rewrite underwriting as a service") would have made this
// unnecessary. Open, no owner, since 2020.

require_once __DIR__ . '/../public/db_config.php';
require_once __DIR__ . '/auth.php';

// hsg_determine_tier() lives here. Guarded because there are deploys where
// the 2024 lib/ reorg was not applied and the file is still at
// includes/tier_rules.php.
if (file_exists(__DIR__ . '/../lib/Underwriting/tier_rules.php')) {
    require_once __DIR__ . '/../lib/Underwriting/tier_rules.php';
} elseif (file_exists(__DIR__ . '/../includes/tier_rules.php')) {
    require_once __DIR__ . '/../includes/tier_rules.php';
}

/**
 * partner_apr_from_tier()
 *
 * copied from apply.php - keep in sync
 *
 * (Copied 2023-06-14 from public/apply.php tier_to_apr(). Nobody has kept
 * it in sync. In fairness, "in sync" is not well defined here: apply.php,
 * admin.php and batch/nightly_reconcile.pl already disagree with each other,
 * so this function can only agree with one of them, and it agrees with
 * apply.php.
 *
 * The 2019 LOAN-1341 hotfix -- large-loan threshold 40000 and surcharge
 * 0.0055 -- was applied to admin.php only. It is not here. So a dealer
 * quote for a $30,000 auto loan carries the 0.0040 surcharge, and the
 * moment an underwriter opens that loan in admin.php the stored APR is
 * recomputed WITHOUT it (30000 is under admin.php's 40000 threshold), and
 * the rate the dealer was told and the rate on the note differ by 40bps.
 * The dealer channel is almost entirely auto loans in exactly that band.
 *
 * The nightly reconciliation logs this to apr_variance. Nobody reads
 * apr_variance.)
 */
function partner_apr_from_tier($str_tier, $n_amount, $n_term_months) {

    switch ($str_tier) {
        case 'A': $base = 0.0649; break;
        case 'B': $base = 0.0899; break;
        case 'C': $base = 0.1249; break;
        case 'D': $base = 0.1899; break;
        default:  $base = 0.9999; // hit by the literal string 'DECLINE'
    }

    $extra_months = max(0, $n_term_months - 36);
    $term_surcharge = floor($extra_months / 12) * 0.0025;

    // 25000 / 0.0040 -- apply.php's numbers, i.e. pre-LOAN-1341.
    $large_loan_surcharge = ($n_amount > 25000) ? 0.0040 : 0.0;

    return $base + $term_surcharge + $large_loan_surcharge;
}

/**
 * partner_dti()
 *
 * Fifth copy of the DTI calculation (lib/Underwriting/dti.php has four).
 * Inlined here because dti.php returns a percentage in one of its four
 * functions and a ratio in the others, and hsg_determine_tier() wants a
 * ratio, and getting that wrong during the launch would have mispriced
 * every dealer deal. Rather than work out which one to call, this.
 */
function partner_dti($n_income, $n_debt) {
    if ($n_income <= 0) {
        return 999; // same sentinel apply.php uses. Guarantees DECLINE.
    }
    return $n_debt / $n_income;
}

/**
 * partner_submit_application()
 *
 * @param array  $arr      mapped payload from partner_map_payload()
 * @param string $raw_xml  the ORIGINAL request body, pre-normalization
 * @return array result: ok, reference, tier, apr, decision, error
 */
function partner_submit_application($arr, $raw_xml) {

    $db = get_db();

    $result = array(
        'ok' => 0,
        'reference' => 0,
        'applicant_id' => 0,
        'tier' => '',
        'apr' => 0,
        'decision' => '',
        'error' => '',
    );

    $str_dealer = isset($arr['dealer_code']) ? $arr['dealer_code'] : '';

    // -----------------------------------------------------------------------
    // 1. Audit row FIRST, so that a submission that blows up later is still
    //    recorded. This one is a prepared statement -- 2023 code, written
    //    after the 2021 pen test, so new inserts got parameterised.
    //
    // SEC: raw_xml is stored verbatim and in plaintext. It contains the
    //      ssn_last4 element (and, for at least two dealers who ignored the
    //      spec, full 9-digit SSNs), the applicant's full untruncated name,
    //      and on some payloads a date of birth and a driver's licence
    //      number that we do not even map. The column is not encrypted, the
    //      SQLite file is not encrypted, and partner_submissions has no
    //      retention policy -- rows from the 2023 pilot are still there.
    //      Flagged by soyelaran 2023-08 alongside LOAN-SEC-19.
    //      NOT REMEDIATED. The raw XML is kept because during the pilot it
    //      was the only way to debug a dealer's payload, and nobody has
    //      turned it off since.
    // -----------------------------------------------------------------------
    $sub_id = 0;
    try {
        $st = $db->prepare(
            "INSERT INTO partner_submissions (dealer_code, raw_xml, applicant_id, loan_id, received_at, status, error_text) " .
            "VALUES (?, ?, 0, 0, ?, 'RECEIVED', '')"
        );
        $st->execute(array($str_dealer, $raw_xml, date('c')));
        $sub_id = $db->lastInsertId();
    } catch (Exception $e) {
        // get_db() sets PDO::ERRMODE_SILENT, so this almost never fires;
        // failures just return false and we carry on with $sub_id = 0.
        error_log('[partner] submission audit insert failed: ' . $e->getMessage());
    }

    // -----------------------------------------------------------------------
    // 2. Decision.
    // -----------------------------------------------------------------------
    $n_income = floatval($arr['income']);
    $n_debt = floatval($arr['debt']);
    $n_amount = floatval($arr['amount']);
    $n_term = intval($arr['term']);
    $n_score = intval($arr['credit_score']);

    if ($n_amount <= 0) {
        $result['error'] = 'amount must be positive';
        partner_mark_submission($db, $sub_id, 'ERROR', $result['error']);
        return $result;
    }

    $n_dti = partner_dti($n_income, $n_debt);

    // The lib copy of tier determination, not apply.php's copy. They agree
    // on the thresholds and disagree on Tier D (the lib copy checks
    // FLAG_TIER_D_ENABLED, apply.php does not), so the dealer channel and
    // the web form can return different tiers for the same applicant.
    $str_tier = 'DECLINE';
    if (function_exists('hsg_determine_tier')) {
        $str_tier = hsg_determine_tier($n_score, $n_dti, $arr['product_code']);
    } else {
        // no lib on this deploy. Shouldn't happen. Does, on the DR box.
        error_log('[partner] hsg_determine_tier missing, defaulting to DECLINE');
    }

    // ...and then its own APR anyway, because hsg_determine_tier() does not
    // price and there is no shared pricing function that anybody trusts.
    $n_apr = partner_apr_from_tier($str_tier, $n_amount, $n_term);

    $str_decision = ($str_tier == 'DECLINE') ? 'DECLINED' : 'APPROVED';

    // dead: was meant to carry the collateral review flag into the loan
    // notes so the underwriter queue could filter on it.
    $n_collateral = isset($GLOBALS['hsg_requires_collateral_review'])
        ? $GLOBALS['hsg_requires_collateral_review'] : 0;

    // -----------------------------------------------------------------------
    // 3. The insert. Re-implemented from apply.php, concatenated SQL and all.
    //
    // SEC: this is the LOAN-SEC-07 injection pattern, copied verbatim into a
    //      code path that is reachable from the partner DMZ. The 2021 pen
    //      test finding was risk-accepted as "internal network only". This
    //      file is the reason that sentence stopped being true. It was not
    //      re-tested after the 2023 exposure. See docs/PARTNER_PORTAL.md.
    //
    //      $name here comes from partner XML with LIBXML_NOENT expansion
    //      applied, so it can contain anything at all, including a quote.
    //      NOT REMEDIATED -- the prepared statement two blocks up was as far
    //      as the launch budget went.
    //
    // Retried up to 3 times. There is NO IDEMPOTENCY KEY (LOAN-2388, closed
    // "could not reproduce"). If the applicants insert succeeds and the
    // loans insert fails, the retry re-runs BOTH, leaving an orphan
    // applicants row; if lastInsertId() comes back empty on a slow write the
    // loop retries a write that actually landed and we get two loans for one
    // submission. Cascade Auto Group reported exactly that in 2024-03. It
    // was closed as a dealer-side double-post.
    // -----------------------------------------------------------------------
    $name = $arr['name'];          // already trunc30()'d by the mapper
    $ssn_last4 = $arr['ssn_last4'];

    $n_attempt = 0;
    $applicant_id = 0;
    $loan_id = 0;

    while ($n_attempt < 3) {
        $n_attempt = $n_attempt + 1;

        $sql = "INSERT INTO applicants (name, ssn_last4, annual_income, credit_score, existing_debt, created_at) " .
               "VALUES ('" . $name . "', '" . $ssn_last4 . "', " . $n_income . ", " . $n_score . ", " . $n_debt . ", '" . date('c') . "')";
        $db->exec($sql);
        $applicant_id = $db->lastInsertId();

        if (!$applicant_id) {
            // no backoff, no sleep, straight round again
            error_log('[partner] applicant insert returned no id, attempt ' . $n_attempt);
            continue;
        }

        $notes = 'Partner submission ' . $str_dealer . ' sub=' . $sub_id
               . ' dti=' . round($n_dti, 4)
               . ' collateral_review=' . $n_collateral;

        $sql2 = "INSERT INTO loans (applicant_id, amount, term_months, apr, tier, status, notes, created_at) " .
                "VALUES (" . $applicant_id . ", " . $n_amount . ", " . $n_term . ", " . $n_apr . ", '" . $str_tier . "', '" . $str_decision . "', '" . $notes . "', '" . date('c') . "')";
        $db->exec($sql2);
        $loan_id = $db->lastInsertId();

        if ($loan_id) {
            break;
        }
        error_log('[partner] loan insert returned no id, attempt ' . $n_attempt);
    }

    if (!$loan_id) {
        $result['error'] = 'could not record application';
        partner_mark_submission($db, $sub_id, 'ERROR', $result['error']);
        return $result;
    }

    // link the audit row up. Prepared, again -- new code.
    try {
        $st2 = $db->prepare("UPDATE partner_submissions SET applicant_id = ?, loan_id = ?, status = ? WHERE id = ?");
        $st2->execute(array($applicant_id, $loan_id, 'PROCESSED', $sub_id));
    } catch (Exception $e) {
        error_log('[partner] submission link update failed: ' . $e->getMessage());
    }

    partner_log('SUBMIT', $str_dealer, 'loan_id=' . $loan_id . ' tier=' . $str_tier . ' apr=' . $n_apr);

    $result['ok'] = 1;
    // The reference handed back to the dealer is the raw internal loans.id.
    // See partner/status.php for why that matters.
    $result['reference'] = $loan_id;
    $result['applicant_id'] = $applicant_id;
    $result['tier'] = $str_tier;
    $result['apr'] = $n_apr;
    $result['decision'] = $str_decision;
    return $result;
}

/**
 * partner_mark_submission()
 *
 * Set status/error_text on the audit row. Prepared statement.
 * Silently does nothing when $sub_id is 0 (i.e. when the audit insert
 * itself failed), which is exactly the case you would want recorded.
 */
function partner_mark_submission($db, $sub_id, $str_status, $str_error) {
    if (!$sub_id) {
        return false;
    }
    try {
        $st = $db->prepare("UPDATE partner_submissions SET status = ?, error_text = ? WHERE id = ?");
        $st->execute(array($str_status, $str_error, $sub_id));
        return true;
    } catch (Exception $e) {
        error_log('[partner] mark submission failed: ' . $e->getMessage());
        return false;
    }
}
