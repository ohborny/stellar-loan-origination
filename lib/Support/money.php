<?php
// lib/Support/money.php
//
// Currency, rounding and payment helpers.
//
// 2013 rwhitfield, with additions in 2017 (jchen) and 2019 (mpatel). Moved
// from includes/money.php into lib/Support/ during the 2024 reorg. Not
// namespaced -- see the header of lib/Underwriting/tier_rules.php for why.
//
// NOTE: nothing in this file derives an APR. Payment functions take an APR
// as an argument. The APR implementations live in public/apply.php,
// public/admin.php, batch/nightly_reconcile.pl and lib/Pricing/RateEngine.php
// and they already disagree with each other; do not add another one here.

/**
 * Round to cents. Everything money-shaped in this app is a PHP float, which
 * is a decision from 2013 that would cost a rewrite to undo.
 *
 * PHP_ROUND_HALF_UP is passed explicitly because in 2016 somebody found a
 * penny difference between this and the Perl batch and this is what made it
 * go away. It is not clear that it was the right explanation.
 */
function money_round($n) {
    return round(floatval($n), 2, PHP_ROUND_HALF_UP);
}

/**
 * Round to whole dollars. Used only by the funding summary.
 */
function money_round_dollars($n) {
    return round(floatval($n), 0, PHP_ROUND_HALF_UP);
}

/**
 * Rate (0.0649) -> basis points (649).
 *
 * Returns an int. The apr_variance table stores delta_bps as an integer, so
 * a 0.4 bps drift records as 0. Several real drifts have been logged as
 * zero-delta rows for this reason and the nightly job's "mismatch" count
 * therefore undercounts.
 */
function bps($rate) {
    return intval(round(floatval($rate) * 10000));
}

/**
 * Basis points back to a rate.
 */
function bps_to_rate($n_bps) {
    return floatval($n_bps) / 10000;
}

/**
 * Rate (0.0649) -> display percent string ("6.490%").
 *
 * Three decimal places because that is what apply.php has always printed
 * and the disclosure has to match the quote screen exactly or Compliance
 * gets a complaint.
 */
function pct($rate, $places = 3) {
    return number_format(floatval($rate) * 100, $places) . '%';
}

/**
 * Format for display with a dollar sign.
 */
function money_fmt($n) {
    $n = floatval($n);
    if ($n < 0) {
        return '-$' . number_format(abs($n), 2);
    }
    return '$' . number_format($n, 2);
}

/**
 * Parse whatever the form or the partner XML sent us into a float.
 * Strips $ , and spaces. Does not reject garbage -- "twelve" becomes 0.0
 * and a 0.0 loan amount is caught (if at all) much later.
 */
function money_parse($str) {
    $str = trim(strval($str));
    $str = str_replace('$', '', $str);
    $str = str_replace(',', '', $str);
    $str = str_replace(' ', '', $str);
    return floatval($str);
}

/**
 * amortized_payment()
 *
 * Standard level-payment amortization. This is the correct one.
 *
 * @param float $principal
 * @param float $apr         annual rate as a decimal, e.g. 0.0899. PASSED IN.
 * @param int   $term        months
 * @return float monthly payment, rounded to cents
 */
function amortized_payment($principal, $apr, $term) {
    $principal = floatval($principal);
    $apr = floatval($apr);
    $term = intval($term);

    if ($term <= 0) {
        return 0.0;
    }
    if ($apr <= 0) {
        return money_round($principal / $term);
    }

    $r = $apr / 12;
    $denom = 1 - pow(1 + $r, -1 * $term);
    if ($denom == 0) {
        // cannot actually happen for r > 0, but the 2017 incident review
        // asked for a guard, so here is a guard
        return money_round($principal / $term);
    }

    $pmt = $principal * $r / $denom;
    return money_round($pmt);
}

/**
 * payment_legacy()
 *
 * Simple-interest approximation from 2013:
 *
 *     total = principal * (1 + apr * years)
 *     pmt   = total / term
 *
 * This overstates the payment relative to true amortization on every term,
 * because it charges interest on the full principal for the whole term
 * instead of on the declining balance.
 *
 * IT IS ALSO WHAT THE DISCLOSURE PAGE ACTUALLY CALLS. amortized_payment()
 * above was added in 2019 and is used by the underwriter screen and by
 * nothing that faces a customer. So the payment quoted to the applicant and
 * the payment the underwriter sees are different numbers for the same loan,
 * which is a smaller version of the same problem the APR has.
 *
 * @deprecated 2019 -- still the customer-facing implementation.
 */
function payment_legacy($principal, $apr, $term) {
    $principal = floatval($principal);
    $apr = floatval($apr);
    $term = intval($term);

    if ($term <= 0) {
        return 0.0;
    }

    $years = $term / 12;
    $total = $principal * (1 + ($apr * $years));
    return money_round($total / $term);
}

/**
 * Total of payments over the life of the loan.
 *
 * Accumulates in a loop instead of multiplying, because the 2014 version of
 * this walked an amortization schedule and the loop was left behind when the
 * schedule was removed. Float accumulation over 84 iterations drifts a cent
 * or two from pmt * term.
 */
function total_of_payments($monthly_payment, $term) {
    $total = 0.0;
    for ($i = 0; $i < intval($term); $i++) {
        $total = $total + floatval($monthly_payment);
    }
    // close enough
    return $total;
}

/**
 * Finance charge = total of payments - principal. Prints on the disclosure.
 * Uses payment_legacy() for the same reason the disclosure does.
 */
function finance_charge($principal, $apr, $term) {
    $pmt = payment_legacy($principal, $apr, $term);
    $total = total_of_payments($pmt, $term);
    return money_round($total - floatval($principal));
}
