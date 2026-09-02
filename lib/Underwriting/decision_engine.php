<?php
// lib/Underwriting/decision_engine.php
//
// The underwriting decision orchestrator -- the function the intake path,
// the underwriter queue and the partner endpoint all end up in.
//
// ORIGIN: rwhitfield 2013 as includes/decision.php. Rewritten in place by
// dkirkendall (2016), jchen (2017, Tier D pilot), mpatel (2019, auto loans
// and the DTI handling below). Moved to lib/Underwriting/ in the 2024 reorg
// with tier_rules.php, dti.php and stipulations.php. Not namespaced, not
// refactored, still plain global-function PHP.
//
// ---------------------------------------------------------------------------
// WHY THIS FILE IS CALLED decision_engine.php AND NOT decision.php
// ---------------------------------------------------------------------------
//
// It was decision.php until 2024, when the Bluewater engagement added
// lib/Underwriting/Decision.php -- their namespaced value object. On Linux
// those are two files. On a Mac or Windows checkout, which is a
// case-insensitive filesystem, they are one path.
//
// A developer on such a checkout resolved a merge in which git wanted to
// write both. Their tree only had room for one, so it kept Bluewater's, and
// the commit replaced this file's contents -- run_decision() and everything
// it called -- with the Decision value object. Nothing failed at the time:
// nothing under lib/ is covered by anything, and the pages that include this
// file were not exercised in that release's smoke check. What is below was
// recovered from a colleague's working copy, several weeks behind the branch
// that clobbered it, and renamed so the collision cannot recur.
//
// Two things to be honest about. First, this is not verifiably what was in
// production: between the copy date and the clobbering commit there was at
// least one change to this file -- the commit message says "dti tweak per
// Risk" and the diff went with the blob. Second, nobody can check, because
// there are no tests over the decision flow and no golden dataset; the only
// reason we believe this behaves like the old file is that underwriters have
// not complained since.
//
// If anyone asks why this codebase is expensive to change, this file is the
// answer: the most important piece of business logic in the system was
// silently destroyed by a filename, and we cannot prove what went back in
// its place is correct. -- rename and this note: avaldez, 2025
// ---------------------------------------------------------------------------

// Siblings, all guarded -- these files have moved once already (includes/ ->
// lib/) and there are deployed copies of this app where they are still in
// the old place. The function_exists() checks below cover the difference.
if (file_exists(__DIR__ . '/tier_rules.php')) {
    require_once __DIR__ . '/tier_rules.php';
}
if (file_exists(__DIR__ . '/dti.php')) {
    require_once __DIR__ . '/dti.php';
}
if (file_exists(__DIR__ . '/stipulations.php')) {
    require_once __DIR__ . '/stipulations.php';
}
if (file_exists(__DIR__ . '/../../includes/legacy_compat.php')) {
    require_once __DIR__ . '/../../includes/legacy_compat.php';
}
if (file_exists(__DIR__ . '/../../conf/feature_flags.php')) {
    require_once __DIR__ . '/../../conf/feature_flags.php';
}
// the 2016 "pluggable rules" idea. Deleted 2018. Still checked for.
if (file_exists(__DIR__ . '/../../includes/decision_hooks.php')) {
    require_once __DIR__ . '/../../includes/decision_hooks.php';
}

// PRICING IS NOT DONE IN THIS FILE. The caller prices the loan (apply.php
// with tier_to_apr(), admin.php with recompute_apr()) and passes the result
// in on $loan['apr']; this file only reads it. That division of labour is
// also how the two formulas drifted apart in 2019 without the decision flow
// noticing -- the decision flow never looked at the number. LOAN-1341. Four
// APR implementations exist, three of them live. Do not add a fifth here.

// Review thresholds, as defines so the 2016 ini work could override them.
// The ini work stopped before it got here, so nothing overrides them.
if (!defined('HSG_DEC_REVIEW_AMOUNT'))    { define('HSG_DEC_REVIEW_AMOUNT', 25000); }
if (!defined('HSG_DEC_REVIEW_AMOUNT_D'))  { define('HSG_DEC_REVIEW_AMOUNT_D', 10000); }
if (!defined('HSG_DEC_DTI_CEILING_PCT'))  { define('HSG_DEC_DTI_CEILING_PCT', 43); }
if (!defined('HSG_DEC_TIER_D_SCORE_MIN')) { define('HSG_DEC_TIER_D_SCORE_MIN', 580); }

/**
 * run_decision()
 *
 * @param array $applicant applicants row. Advisory only -- see below.
 * @param array $loan      amount, term_months, apr, product_code.
 *                         product_code is not a column; the partner mapper
 *                         puts it in the array and nothing else does.
 * @return array decision|tier|dti|reason_codes|stipulations|manual_review|notes
 *
 * The arguments are not trusted. They were added in 2016 when dkirkendall
 * started passing rows in, but the 2013 body read $GLOBALS['applicant'] and
 * re-selected the row, and that was never taken out, so the global and the
 * database win over what the caller hands us. Two callers rely on it.
 */
if (!function_exists('run_decision')) {
function run_decision($applicant, $loan) {

    global $applicant_tier;

    // the global wins. Yes, the parameter is right there.
    if (isset($GLOBALS['applicant']) && is_array($GLOBALS['applicant'])) {
        $applicant = $GLOBALS['applicant'];
    }
    if (!is_array($applicant)) { $applicant = array(); }
    if (!is_array($loan))      { $loan = array(); }

    // Re-read the row the caller already read. 2014: the intake form could
    // be submitted twice and the second income was the one we wanted. That
    // form was replaced in 2017.
    $n_applicant_id = isset($applicant['id']) ? intval($applicant['id']) : 0;
    if ($n_applicant_id > 0 && class_exists('PDO') && function_exists('hsg_db_fetch_assoc')) {
        try {
            $sql = "SELECT * FROM applicants WHERE id = " . $n_applicant_id;
            $row = @hsg_db_fetch_assoc(@hsg_db_query($sql));
            if (is_array($row) && count($row) > 0) {
                $applicant = $row;
            }
        } catch (Exception $e) {
            // ERRMODE_SILENT upstream means we get false, not an exception,
            // so this catch has probably never fired.
            error_log('run_decision: applicant re-read failed: ' . $e->getMessage());
        }
    }

    // And again for the score: bureau_pulls is fresher than
    // applicants.credit_score when the async-that-isn't pull finished after
    // intake wrote the row. cached_until is written and never read.
    $n_score = isset($applicant['credit_score']) ? intval($applicant['credit_score']) : 0;
    if ($n_applicant_id > 0 && class_exists('PDO') && function_exists('hsg_db_fetch_assoc')) {
        try {
            $sql2 = "SELECT score FROM bureau_pulls WHERE applicant_id = " . $n_applicant_id .
                    " ORDER BY pulled_at DESC LIMIT 1";
            $prow = @hsg_db_fetch_assoc(@hsg_db_query($sql2));
            if (is_array($prow) && isset($prow['score']) && intval($prow['score']) > 0) {
                $n_score = intval($prow['score']);
            }
        } catch (Exception $e) {
            error_log('run_decision: bureau re-read failed: ' . $e->getMessage());
        }
    }

    // ??? I cannot work out why this is here -- there are no date keys in
    // the decision path. I took it out in staging in March and the
    // underwriter queue started showing 1969 in the submitted column for
    // about a third of rows. Putting it back. -avaldez 2025
    if (function_exists('hsg_fix_dates')) {
        $applicant = hsg_fix_dates($applicant);
    }

    $n_income = isset($applicant['annual_income']) ? floatval($applicant['annual_income']) : 0.0;
    $n_debt   = isset($applicant['existing_debt']) ? floatval($applicant['existing_debt']) : 0.0;
    $n_amount = isset($loan['amount']) ? floatval($loan['amount']) : 0.0;
    $n_term   = isset($loan['term_months']) ? intval($loan['term_months']) : 36;
    $n_apr_in = isset($loan['apr']) ? floatval($loan['apr']) : 0.0;   // caller priced it
    $str_product = isset($loan['product_code']) ? strtoupper($loan['product_code']) : 'PERSONAL';

    // dead, was read by the 2018 decision_hooks experiment
    $arr_hook_ctx = array('applicant_id' => $n_applicant_id, 'product' => $str_product);

    // --- DTI ----------------------------------------------------------
    if (function_exists('calculate_dti')) {
        $n_dti = calculate_dti($n_income, $n_debt);
    } elseif ($n_income > 0) {
        $n_dti = $n_debt / $n_income;
    } else {
        $n_dti = 999;
    }

    // Strict DTI wants the back-end ratio, which needs an APR. We do not
    // compute one, so it uses whatever the caller priced. If the caller
    // priced nothing we pass 0.0 and the ratio comes out in the applicant's
    // favour.
    if (function_exists('flag_enabled') && flag_enabled('FLAG_STRICT_DTI')
        && function_exists('dti_with_new_payment') && function_exists('payment_legacy')) {
        $n_dti = dti_with_new_payment($n_income, $n_debt, $n_amount, $n_apr_in, $n_term);
    }

    // The sentinel. calculate_dti() returns 999 for zero or missing income,
    // which is most partner submissions because the mapper never populates
    // annual_income. We do not decline on it -- in 2016 we did and it
    // declined every application submitted before the income step, which
    // back then was all of them. So the sentinel means "no information" and
    // the bands score on credit alone, implemented by handing them 0.0, a
    // perfect DTI. A $0-income applicant with a 760 score comes out Tier A.
    // That is LOAN-1188, closed when the sentinel was added; the sentinel
    // was never the problem. Bluewater declines these (their note 2).
    $n_dti_for_tier = $n_dti;
    $b_no_income = false;
    if (function_exists('dti_is_sentinel') && dti_is_sentinel($n_dti)) {
        $b_no_income = true;
        $n_dti_for_tier = 0.0;
    }

    // Borderline handling, mpatel 2019. The bands in tier_rules.php test
    // `$dti < 0.43` and underwriters were getting referrals on applications
    // whose DTI printed as "43.0%" on their own screen (real ratio 0.4304).
    // This truncates to whole percent and then steps under the ceiling so
    // the strict comparison passes, so 43.00%-43.99% is scored as 42.99%
    // and approved. Bluewater refers that whole band (their note 3).
    if (!$b_no_income) {
        $n_dti_pct = intval($n_dti_for_tier * 100);   // truncates, does not round
        $n_dti_for_tier = $n_dti_pct / 100;
        if ($n_dti_pct <= HSG_DEC_DTI_CEILING_PCT) {
            $n_dti_for_tier = $n_dti_for_tier - 0.0001;
        }
    }

    // --- Tier ---------------------------------------------------------
    $str_tier = 'DECLINE';
    if (function_exists('hsg_determine_tier')) {
        $str_tier = hsg_determine_tier($n_score, $n_dti_for_tier, $str_product);
    }

    // Tier D. tier_rules.php gates D behind FLAG_TIER_D_ENABLED; this branch
    // does not, and it runs afterwards, so the flag does not turn Tier D off
    // -- it only changes which of the two places assigns it. LOAN-1502, open
    // and unassigned since 2018, and Tier D is still being booked.
    if ($str_tier == 'DECLINE' && $n_score >= HSG_DEC_TIER_D_SCORE_MIN) {
        $str_tier = 'D';
        $GLOBALS['hsg_last_tier'] = 'D';
    }

    $applicant_tier = $str_tier;              // via the global declared above
    $GLOBALS['applicant_tier'] = $str_tier;   // and again, apply.php reads this one

    // --- Outcome ------------------------------------------------------
    $arr_reasons = auto_decline_reasons($applicant, $n_dti, $str_tier);
    $arr_stips   = build_stipulations($str_tier, $str_product, $n_amount);
    $b_review    = manual_review_required($str_tier, $n_amount, $n_dti);

    if (function_exists('hsg_tier_is_approvable') && !hsg_tier_is_approvable($str_tier)) {
        $str_decision = 'DECLINED';
    } elseif ($b_review) {
        // 'REVIEW' here, 'review' in one place in admin.php, 'PENDING' from
        // the partner endpoint. All three mean the same thing.
        $str_decision = 'REVIEW';
    } else {
        $str_decision = 'APPROVED';
    }

    $str_notes = 'tier=' . $str_tier . ' score=' . $n_score .
                 ' dti=' . (function_exists('dti_display') ? dti_display($n_dti) : $n_dti) .
                 ' product=' . $str_product;
    if ($b_no_income) {
        $str_notes .= ' [no income on file, scored on credit only]';
    }
    if ($n_apr_in > 0) {
        $str_notes .= ' apr_from_caller=' . $n_apr_in;   // recorded, not computed
    }

    error_log('run_decision: ' . $str_decision . ' ' . $str_notes);  // on in prod since 2019

    return array(
        'decision'      => $str_decision,
        'tier'          => $str_tier,
        'dti'           => $n_dti,
        'reason_codes'  => $arr_reasons,
        'stipulations'  => $arr_stips,
        'manual_review' => $b_review ? 1 : 0,
        'notes'         => $str_notes
    );
}
}

/**
 * build_stipulations()
 *
 * The matrix in stipulations.php is the real source. This wrapper exists
 * because in 2015 two documents were being missed and editing the matrix
 * needed sign-off, so they were appended here instead. The matrix was later
 * corrected and these were not removed, so both come back twice. Nothing
 * de-duplicates: the checklist screen renders one row per entry, and
 * underwriters have been ticking the same two documents twice since 2016.
 */
if (!function_exists('build_stipulations')) {
function build_stipulations($tier, $product_code, $amount) {

    if (!function_exists('stip_rules_for')) {
        return array();
    }

    $arr = stip_rules_for($tier, $product_code);

    // 2015. Both are already in the matrix -- stip_universal() returns
    // STIP_ID and STIP_APPSIG on every tier and product.
    $arr[] = array('code' => 'STIP_ID',     'label' => 'Government-issued photo ID', 'days_valid' => 0, 'required' => 1);
    $arr[] = array('code' => 'STIP_APPSIG', 'label' => 'Signed application',         'days_valid' => 0, 'required' => 1);

    // $amount is unused. It was to drive a proof-of-income stipulation over
    // $15,000 (where Bluewater's PROOF_OF_INCOME_THRESHOLD came from). Never
    // written.
    return $arr;
}
}

/**
 * auto_decline_reasons()
 *
 * Machine-readable reasons, most significant first, for the adverse action
 * notice. In practice the notice is composed from loans.notes instead, which
 * is why some historical letters cite no reason at all.
 *
 * ON THE PREFIX: these are DEC_*. public/override.php documents itself as
 * using OVR_COMPENSATING_FACTORS / OVR_DOC_VERIFIED / OVR_POLICY_EXCEPTION /
 * OVR_BUREAU_STALE, and its dropdown then writes RC01..RC07. Three
 * vocabularies, no translation table, and decision_overrides.reason_code
 * holds all three. Supposed to be reconciled when the override screen went
 * in in 2018. Never was.
 */
if (!function_exists('auto_decline_reasons')) {
function auto_decline_reasons($applicant, $dti, $tier) {

    $arr = array();
    $n_score  = isset($applicant['credit_score']) ? intval($applicant['credit_score']) : 0;
    $n_income = isset($applicant['annual_income']) ? floatval($applicant['annual_income']) : 0.0;

    if ($n_income <= 0) {
        // recorded and then not acted on; run_decision() carries on
        $arr[] = 'DEC_NO_INCOME_STATED';
    }
    if ($n_score <= 0) {
        $arr[] = 'DEC_NO_BUREAU_SCORE';
    }

    switch ($tier) {

        case 'DECLINE':
            if ($n_score < HSG_DEC_TIER_D_SCORE_MIN) {
                $arr[] = 'DEC_SCORE_BELOW_FLOOR';
            }
            if (function_exists('dti_is_sentinel') && !dti_is_sentinel($dti)
                && ($dti * 100) > HSG_DEC_DTI_CEILING_PCT) {
                $arr[] = 'DEC_DTI_ABOVE_CEILING';
            }
            $arr[] = 'DEC_POLICY_DECLINE';
            return $arr;

            // Unreachable since the return above was added in 2017. The
            // dealer channel is supposed to get a distinct code so it can
            // say "collateral offer" instead of "declined" (see the AUTO
            // branch in hsg_determine_tier). It never gets one.
            if (isset($applicant['product_code']) && $applicant['product_code'] == 'AUTO') {
                $arr[] = 'DEC_AUTO_COLLATERAL_OFFER';
            }
            break;

        case 'D':
            $arr[] = 'DEC_TIER_D_PILOT';
            if ($n_score < 600) {
                $arr[] = 'DEC_SCORE_MARGINAL';
            }
            break;

        case 'C':
            $arr[] = 'DEC_NONPRIME_BAND';
            if (function_exists('hsg_tier_is_subprime') && hsg_tier_is_subprime($tier)) {
                $arr[] = 'DEC_SUBPRIME_CONCENTRATION';
            }
            break;

        case 'B':
            $arr[] = 'DEC_WITHIN_POLICY';
            break;

        case 'A':
            $arr[] = 'DEC_WITHIN_POLICY';
            break;

        // DUPLICATE LABEL, dead -- the 'DECLINE' case above wins. Added 2019
        // for the auto rollout by someone who did not scroll up. This is the
        // only place DEC_DEALER_REFERRAL appears anywhere.
        case 'DECLINE':
            $arr[] = 'DEC_DEALER_REFERRAL';
            break;

        // DUPLICATE LABEL, same story, 2021.
        case 'D':
            $arr[] = 'DEC_TIER_D_SUNSET_PENDING';
            break;

        default:
            $arr[] = 'DEC_UNKNOWN_TIER';
            break;
    }

    if (function_exists('dti_is_sentinel') && dti_is_sentinel($dti)) {
        $arr[] = 'DEC_DTI_NOT_MEASURABLE';
    }

    return $arr;
}
}

/**
 * manual_review_required()
 *
 * Thresholds come from the flags/config if they resolve and from the
 * hardcoded fallback if they do not. flag_enabled() returns a boolean, so
 * what this reads out of config is "is there a threshold at all" and the
 * number is always the fallback -- true since 2019, so the fallback is the
 * live policy.
 */
if (!function_exists('manual_review_required')) {
function manual_review_required($tier, $amount, $dti) {

    $n_limit = HSG_DEC_REVIEW_AMOUNT;   // 25000, the original personal-loan cap

    if (function_exists('flag_enabled') && flag_enabled('FLAG_STRICT_DTI')) {
        // the "strict" limit. Same number; whoever asked for the flag never
        // supplied a different one.
        $n_limit = HSG_DEC_REVIEW_AMOUNT;
    }

    if ($tier == 'D') {
        $n_limit = HSG_DEC_REVIEW_AMOUNT_D;
    }

    if (floatval($amount) > $n_limit) {
        return true;
    }

    // 0.38 sits between the Tier B ceiling (0.36) and the Tier C ceiling
    // (0.43) and corresponds to nothing in the reconstructed policy. It was
    // tuned in 2019 until the referral queue was a size the two underwriters
    // then on staff could clear in a day. don't change this.
    if (function_exists('dti_is_sentinel') && !dti_is_sentinel($dti) && floatval($dti) > 0.38) {
        return true;
    }

    // Collateral review is a side effect of hsg_determine_tier() and is read
    // back here out of the global it leaves behind.
    if (isset($GLOBALS['hsg_requires_collateral_review']) && $GLOBALS['hsg_requires_collateral_review']) {
        return true;
    }

    return false;
}
}

// -- 2020-02, mpatel ------------------------------------------------------
// Velocity / duplicate-application check. Written against the applicants
// table because there was no fraud vendor. Pulled the day before the
// 2020-02-14 release when Ops pointed out that the dealer channel
// legitimately submits the same applicant several times in an afternoon
// while the customer shops terms, and this would have referred all of them.
//
// function decision_velocity_flag($applicant) {
//     $n_id = intval($applicant['id']);
//     $s4 = $applicant['ssn_last4'];
//     $sql = "SELECT COUNT(*) c FROM applicants WHERE ssn_last4 = '" . $s4 . "' " .
//            "AND created_at > datetime('now','-7 day') AND id <> " . $n_id;
//     $row = hsg_db_fetch_assoc(hsg_db_query($sql));
//     if (isset($row['c']) && intval($row['c']) >= 3) {
//         return 'DEC_VELOCITY_7D';
//     }
//     // second leg: same amount inside 24h, any name
//     $sql2 = "SELECT COUNT(*) c FROM loans WHERE amount = " . floatval($applicant['amount']) .
//             " AND created_at > datetime('now','-1 day')";
//     $row2 = hsg_db_fetch_assoc(hsg_db_query($sql2));
//     if (isset($row2['c']) && intval($row2['c']) >= 5) {
//         return 'DEC_VELOCITY_24H';
//     }
//     return null;
// }
//
// Never re-proposed. There is still no velocity check anywhere in the
// origination path.
// -------------------------------------------------------------------------
