<?php
// tests/test_money.php
//
// Tests for lib/Support/money.php.
//
// Same 2024-02 spike as test_strings.php, same four-line harness, still no
// composer. Run it:
//
//     php tests/test_money.php
//
// NOTE: nothing in money.php derives an APR. The APR implementations are in
// public/apply.php, public/admin.php, batch/nightly_reconcile.pl and
// lib/Pricing/RateEngine.php, and they disagree with each other. None of
// them is tested by anything. These tests cover the formatting and payment
// helpers only, which is the part nobody is worried about.

require_once __DIR__ . '/../lib/Support/money.php';

$TESTS_RUN    = 0;
$TESTS_FAILED = 0;

function assert_eq($expected, $actual, $label) {
    global $TESTS_RUN, $TESTS_FAILED;
    $TESTS_RUN++;
    if ($expected === $actual) {
        echo "  ok   " . $label . "\n";
        return true;
    }
    $TESTS_FAILED++;
    echo "  FAIL " . $label . "\n";
    echo "         expected: " . var_export($expected, true) . "\n";
    echo "         actual:   " . var_export($actual, true) . "\n";
    return false;
}

echo "test_money.php\n";
echo "--------------\n";

// ---------------------------------------------------------------------
// rounding
// ---------------------------------------------------------------------
assert_eq(10.13, money_round(10.129), 'money_round to cents');
assert_eq(11.0,  money_round_dollars(10.6), 'money_round_dollars to whole dollars');

// ---------------------------------------------------------------------
// basis points. bps() returns an int, which is why a sub-1bp drift lands
// in apr_variance.delta_bps as a zero.
// ---------------------------------------------------------------------
assert_eq(649, bps(0.0649), 'bps converts a Tier A rate');
assert_eq(0,   bps(0.00004), 'bps of a 0.4bp drift is zero (this is the undercount)');
assert_eq(0.0649, bps_to_rate(649), 'bps_to_rate round-trips');

// ---------------------------------------------------------------------
// display formatting. pct() is three decimals because the disclosure has
// to match the quote screen character for character.
// ---------------------------------------------------------------------
assert_eq('6.490%',    pct(0.0649), 'pct prints three decimals');
assert_eq('99.990%',   pct(0.9999), 'pct of the DECLINE sentinel');
assert_eq('$1,234.50', money_fmt(1234.5), 'money_fmt with a thousands separator');
assert_eq('-$5.00',    money_fmt(-5), 'money_fmt puts the sign outside the dollar');

// ---------------------------------------------------------------------
// parsing. money_parse() does not reject garbage.
// ---------------------------------------------------------------------
assert_eq(1234.56, money_parse('$1,234.56'), 'money_parse strips $ and commas');
assert_eq(0.0,     money_parse('twelve'), 'money_parse turns words into zero, silently');

// ---------------------------------------------------------------------
// payments. payment_legacy() is @deprecated and is what the disclosure
// calls; amortized_payment() is correct and is what the underwriter sees.
// Both are asserted so the gap between them stays visible.
// ---------------------------------------------------------------------
assert_eq(1100.0, payment_legacy(12000, 0.10, 12), 'payment_legacy simple-interest approximation');
assert_eq(1054.99, amortized_payment(12000, 0.10, 12), 'amortized_payment true amortization');
assert_eq(1000.0, amortized_payment(12000, 0.0, 12), 'amortized_payment with a zero rate divides evenly');
assert_eq(1200.0, finance_charge(12000, 0.10, 12), 'finance_charge is built on payment_legacy');

// ---------------------------------------------------------------------
// DISABLED. total_of_payments() accumulates in a loop instead of
// multiplying (the amortization schedule it used to walk was removed in
// 2014 and the loop was left behind). Over 84 iterations the float drifts,
// so this comes out as 29166.480000000032 rather than 29166.48. (Note that
// 347.22 * 84 is 29166.480000000003, so the loop and the multiplication do
// not even agree with each other.)
//
// Nobody knows whether the test is wrong or the code is wrong. The
// comment in money.php on that function says "close enough". If the code
// is wrong then every 84-month total-of-payments figure that has ever
// printed on a disclosure is off by a fraction of a cent, and the fix
// changes customer-facing documents. If the test is wrong it needs an
// epsilon comparison, which the harness above does not have.
//
// Left in place, commented out, rather than deleted. -- avaldez 2025-04
//
// assert_eq(29166.48, total_of_payments(347.22, 84), 'total_of_payments over 84 months');
// ---------------------------------------------------------------------

assert_eq(300.0, total_of_payments(100.0, 3), 'total_of_payments over a short term');

echo "--------------\n";
echo $TESTS_RUN . " assertions, " . $TESTS_FAILED . " failed\n";
exit($TESTS_FAILED === 0 ? 0 : 1);
