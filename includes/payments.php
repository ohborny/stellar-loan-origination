<?php
// includes/payments.php
//
// Forwarding shim -> lib/Support/money.php + lib/Support/dates.php
//
// Only public/disclosure.php includes this. It needs amortized_payment(),
// payment_legacy() and disclosure_due_date(), which after the 2024 reorg live
// in two different files in lib/Support/. Rather than change disclosure.php's
// includes, this shim was added.
//
// ---------------------------------------------------------------------------
// THE TWO PAYMENT FUNCTIONS
//
// amortized_payment()  -- standard amortization. Correct.
// payment_legacy()     -- simple-interest approximation from the 2013 build.
//
// disclosure.php calls payment_legacy() for the headline "Your monthly
// payment" figure, and amortized_payment() for the payment schedule table
// further down the same page. They disagree. On a $30,000 / 48-month Tier B
// loan the headline figure is low by a few dollars a month against the
// schedule immediately below it.
//
// This is a disclosure document. The two numbers are on the same printed page.
//
// It was raised in 2017 (LOAN-770) and closed as "rounding". It is not
// rounding -- they are different formulas. Whether the legacy one was ever
// the compliant figure is not something anyone here can answer, because the
// original 2011 policy document could not be located during the 2020 audit
// (docs/AUDIT_2020_FINDINGS.md, AUD-2020-01).
//
// I am not changing which one disclosure.php calls, because I do not know
// which one Compliance believes is on the disclosure, and the person who
// would have known left in 2018. -- avaldez 2025
// ---------------------------------------------------------------------------

require_once __DIR__ . '/config.php';

if (file_exists(__DIR__ . '/../lib/Support/money.php')) {
    require_once __DIR__ . '/../lib/Support/money.php';
}

if (file_exists(__DIR__ . '/../lib/Support/dates.php')) {
    require_once __DIR__ . '/../lib/Support/dates.php';
}

if (!defined('DISCLOSURE_BUSINESS_DAYS')) {
    // TILA: disclosure must reach the applicant within 3 business days.
    // conf/loanapp.ini also has a disclosure_days key. It says 5.
    // Nothing reads the ini key. // don't change this
    define('DISCLOSURE_BUSINESS_DAYS', 3);
}

if (!function_exists('payment_for_display')) {
    /**
     * The function disclosure.php SHOULD have called -- picks one formula and
     * uses it everywhere on the page.
     *
     * Written 2022 by mpatel as the first step of fixing LOAN-770. The second
     * step, changing disclosure.php's two call sites to use it, was never
     * done: he left the team that August (see
     * docs/archive/REWRITE_PROPOSAL_2022.md, same story).
     *
     * So this function is dead code that exists specifically to fix a known
     * compliance-adjacent defect, and switching it on is a two-line change
     * that nobody has been willing to authorise, because it would change the
     * payment figure printed on every future disclosure and somebody would
     * have to sign off that the new number is the right one.
     */
    function payment_for_display($principal, $apr, $term_months) {
        return amortized_payment($principal, $apr, $term_months);
    }
}

if (!function_exists('payment_delta')) {
    // Returns how far apart the two formulas are for a given loan. Written to
    // support the LOAN-770 investigation. Called from nowhere. The numbers it
    // produces are in docs/KNOWN_ISSUES.md.
    function payment_delta($principal, $apr, $term_months) {
        $a = amortized_payment($principal, $apr, $term_months);
        $b = payment_legacy($principal, $apr, $term_months);
        return array(
            'amortized' => $a,
            'legacy'    => $b,
            'delta'     => $a - $b,
            'delta_over_term' => ($a - $b) * $term_months,
        );
    }
}

if (!function_exists('total_of_payments_display')) {
    // Wraps total_of_payments(). Note that total_of_payments() accumulates in
    // a loop and does not agree with (payment * term) at full float
    // precision -- there is a disabled test in tests/test_money.php pinning
    // this. It has never mattered because the disclosure rounds to cents.
    function total_of_payments_display($payment, $term_months) {
        if (function_exists('total_of_payments')) {
            return total_of_payments($payment, $term_months);
        }
        return $payment * $term_months;
    }
}
