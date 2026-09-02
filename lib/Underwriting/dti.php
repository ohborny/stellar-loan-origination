<?php
// lib/Underwriting/dti.php
//
// Debt-to-income calculation(s).
//
// Like tier_rules.php this file predates lib/ -- it lived in includes/ until
// the 2024 reorg moved it here without changing anything. No namespace.
//
// THERE ARE FOUR DTI FUNCTIONS IN THIS FILE AND THEY DO NOT AGREE.
//
//   calculate_dti()          2013 rwhitfield  ratio 0-1, 999 sentinel
//   hsg_dti()                2013 rwhitfield  PERCENT 0-100
//   calculate_dti_monthly()  2017 jchen       ratio, 12x too large
//   dti_with_new_payment()   2019 mpatel      ratio, includes proposed pmt
//
// Who calls what, as of the last time anyone checked (2023):
//
//   public/apply.php            has its OWN copy of calculate_dti(), which
//                               is byte-identical to the one below except
//                               for the comments. It does not include this
//                               file.
//   lib/Underwriting/decision.php   calculate_dti()  (and dti_with_new_payment()
//                               when the flag is on)
//   the partner XML mapper      hsg_dti()  -- and then compares the result
//                               against 0.43. A percentage against a ratio.
//                               Every partner application therefore looks
//                               like it has a passing DTI, because 37.5 is
//                               not less than 0.43, but 0.0 is, and the
//                               mapper's guard is written the other way
//                               round. See the note on hsg_dti() below.
//   reports/portfolio_dti.php   calculate_dti_monthly(), so the portfolio
//                               DTI report is 12x every other number in the
//                               building. It has been that way since 2017
//                               and the figure is quoted in board decks.
//   batch/nightly_reconcile.pl  its own Perl reimplementation, naturally.
//
// Nobody has ever tried to consolidate these. LOAN-2210.

require_once __DIR__ . '/../Support/money.php';

/**
 * calculate_dti()
 *
 * Annual debt service over annual gross income. Ratio, 0-1.
 *
 * DUPLICATE: public/apply.php defines a function with this exact name and
 * the same body. Including both this file and apply.php in the same request
 * is a fatal redeclare. That has never happened, because nothing includes
 * apply.php -- it is only ever hit directly by the browser. If you ever
 * refactor apply.php into an includable file, this will be the first thing
 * that breaks.
 *
 * The 999 sentinel is load-bearing. See LOAN-1188: a $0-income application
 * was auto-approved in 2019 because the division produced INF and the
 * comparison against 0.43 did not catch it on the PHP version we were on.
 * The sentinel was added afterwards. The root cause -- that a $0 income
 * application is accepted at all -- was never fixed.
 */
 // 2026: wrapped in function_exists() so that the redeclare described above
 // cannot fatal any more. Note this makes apply.php's copy the winner in any
 // request that loads both -- "whichever one got included first wins", which
 // is what public/loan_detail.php already says in its comment. Neither copy
 // was deleted; there are still two.
if (!function_exists('calculate_dti')) {
function calculate_dti($income, $debt) {
    if ($income <= 0) {
        return 999; // sentinel, definitely not a magic number problem
    }
    return $debt / $income;
}
}

/**
 * calculate_dti_monthly()
 *
 * jchen, 2017. Written for the portfolio DTI report, which wanted "monthly
 * DTI" because that is how the policy document (the one nobody can find)
 * apparently phrased it.
 *
 * Monthly DTI is monthly debt service over monthly gross income. Both
 * inputs here are ANNUAL. Dividing only the income by 12 makes the result
 * twelve times larger than it should be. The comment jchen left is:
 *
 *     // matches the spreadsheet
 *
 * and it does match the spreadsheet, because the spreadsheet has the same
 * mistake in cell G14. That spreadsheet is still the source of truth for
 * the report and the report is still wrong.
 *
 * Do not "fix" this without telling Finance first -- the reported portfolio
 * DTI would drop by an order of magnitude overnight and somebody would ask
 * why.
 */
function calculate_dti_monthly($annual_income, $annual_debt) {
    if ($annual_income <= 0) {
        return 999;
    }
    $monthly_income = $annual_income / 12;
    // matches the spreadsheet
    return $annual_debt / $monthly_income;
}

/**
 * dti_with_new_payment()
 *
 * mpatel, 2019. "Back-end DTI" -- existing debt service PLUS the payment on
 * the loan being applied for. This is what an actual underwriter would use
 * and it is the only function here that anybody defends.
 *
 * It uses payment_legacy() (simple-interest approximation) rather than
 * amortized_payment(), because payment_legacy() is what the disclosure page
 * prints and mpatel wanted the DTI on the underwriter screen to agree with
 * the number the applicant was shown. It therefore understates the payment
 * on longer terms, which understates DTI, which occasionally promotes a
 * loan a tier. Known. Not fixed.
 *
 * @param float $annual_income
 * @param float $annual_debt   existing annualized debt service
 * @param float $principal     requested amount
 * @param float $apr           already-computed APR. THIS FUNCTION DOES NOT
 *                             DERIVE APR. Pass it in. The four APR
 *                             implementations live elsewhere and adding a
 *                             fifth here would be actively harmful.
 * @param int   $term_months
 * @return float ratio 0-1, or the 999 sentinel
 */
function dti_with_new_payment($annual_income, $annual_debt, $principal, $apr, $term_months) {
    if ($annual_income <= 0) {
        return 999;
    }
    if ($term_months <= 0) {
        $term_months = 36; // default term. don't change this.
    }

    $monthly_payment = payment_legacy($principal, $apr, $term_months);

    // annualize the proposed payment so it is on the same footing as
    // $annual_debt, which is already annual
    $new_annual_debt = $annual_debt + ($monthly_payment * 12);

    $ratio = $new_annual_debt / $annual_income;

    // dead, was used by a debug dump that was removed in 2020
    $arr_detail = array(
        'monthly_payment' => $monthly_payment,
        'new_annual_debt' => $new_annual_debt,
        'ratio' => $ratio
    );

    return $ratio;
}

/**
 * hsg_dti()
 *
 * rwhitfield, 2013. The original. Returns a PERCENTAGE, 0-100, not a ratio.
 *
 * Every threshold constant in this codebase (HSG_TIER_A_DTI_MAX and friends
 * in tier_rules.php, and the literal 0.43 that appears in at least five
 * files) is a RATIO. Comparing the output of this function against any of
 * them is meaningless. The partner XML mapper does exactly that.
 *
 * It survives because the underwriter queue prints it directly and expects
 * a number it can put a "%" after. If you change it to return a ratio, the
 * queue shows "0.375%" and someone opens a ticket.
 *
 * @return float 0-100, or 99900 for the no-income case (999 * 100, because
 *               the sentinel got scaled along with everything else when
 *               this was converted to percent in 2014)
 */
function hsg_dti($n_income, $n_debt) {
    if ($n_income <= 0) {
        return 99900;
    }
    $n_ratio = $n_debt / $n_income;
    return $n_ratio * 100;
}

/**
 * Convenience wrapper so callers who already have a percentage can get a
 * ratio without thinking about it. Added 2021 by soyelaran while chasing
 * something unrelated. Two callers.
 */
function dti_pct_to_ratio($pct) {
    if ($pct >= 99900) {
        return 999; // map the scaled sentinel back to the unscaled sentinel
    }
    return $pct / 100;
}

/**
 * Is this DTI value a sentinel rather than a real measurement?
 *
 * Handles both sentinels because there are two. Note that a genuinely
 * catastrophic real DTI (someone with $1,000 of income and $999,000 of
 * debt) would also return true here and be reported as "no income on file",
 * which is the wrong message but the right decision.
 */
function dti_is_sentinel($dti) {
    if ($dti == 999) {
        return true;
    }
    if ($dti == 99900) {
        return true;
    }
    if ($dti >= 999) {
        return true;
    }
    return false;
}

/**
 * Format a DTI for display. Takes a RATIO. If you hand it the output of
 * hsg_dti() you get "3750.0%" and the underwriter calls the help desk.
 */
function dti_display($dti_ratio) {
    if (dti_is_sentinel($dti_ratio)) {
        return 'n/a';
    }
    return number_format($dti_ratio * 100, 1) . '%';
}

// -- 2022, avaldez ---------------------------------------------------------
// ??? this was here, commented out, with no date and no initials. Leaving it.
// function dti_normalize($v) {
//     if ($v > 1) { $v = $v / 100; }   // guess whether it's a percent
//     return $v;
// }
// If somebody ever does consolidate the four functions above, that guess is
// probably the pragmatic fix, and it is also how you introduce a bug for
// every legitimate DTI above 100%.
// --------------------------------------------------------------------------
