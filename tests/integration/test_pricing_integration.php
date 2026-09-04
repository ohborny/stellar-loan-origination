<?php
// tests/integration/test_pricing_integration.php
//
// Integration tests for the pricing engine (lib/Pricing/RateEngine.php)
// against the real rate table (conf/rates.xml) loaded via XmlRateTableLoader.
//
// This test exercises the full pricing stack: PriceRequest construction ->
// XmlRateTableLoader reading conf/rates.xml -> RateEngine computing APR
// with banded surcharges -> PriceResult with full breakdown.
//
// The RateEngine is the "intended" implementation that has never been wired
// up (FLAG_USE_RATE_ENGINE is off). These tests pin its behavior so that if
// it is ever enabled, the numbers it produces are known and documented.
//
// Run it:
//     php tests/integration/test_pricing_integration.php
//
// Exit code is 0 on success, 1 on failure.

// ---------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------

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

function assert_true($cond, $label) {
    global $TESTS_RUN, $TESTS_FAILED;
    $TESTS_RUN++;
    if ($cond) {
        echo "  ok   " . $label . "\n";
        return true;
    }
    $TESTS_FAILED++;
    echo "  FAIL " . $label . "\n";
    return false;
}

echo "test_pricing_integration.php\n";
echo "----------------------------\n";

// ---------------------------------------------------------------------------
// Load the pricing classes (no autoloader is registered)
// ---------------------------------------------------------------------------

$repoRoot = dirname(__DIR__, 2);

require_once $repoRoot . '/lib/Pricing/RateTableLoader.php';  // interface + XmlRateTableLoader
require_once $repoRoot . '/lib/Pricing/PriceRequest.php';
require_once $repoRoot . '/lib/Pricing/PriceResult.php';
require_once $repoRoot . '/lib/Pricing/RateEngine.php';
require_once $repoRoot . '/lib/Support/Clock.php';            // ClockInterface + clocks

use Meridian\Pricing\RateEngine;
use Meridian\Pricing\XmlRateTableLoader;
use Meridian\Pricing\PriceRequest;
use Meridian\Pricing\PriceResult;
use Meridian\Support\FrozenClock;

// ---------------------------------------------------------------------------
// Build the engine with the real rate table and a frozen clock
// ---------------------------------------------------------------------------

$rateTable = XmlRateTableLoader::fromRepositoryRoot($repoRoot);
$clock     = FrozenClock::at('2025-09-01T12:00:00 UTC');
$engine    = new RateEngine($rateTable, $clock);

$asOf = new DateTimeImmutable('2025-09-01', new DateTimeZone('UTC'));

// ---------------------------------------------------------------------------
// Test 1: Rate table loads from conf/rates.xml
// ---------------------------------------------------------------------------

echo "\n-- Rate table loading --\n";

$rates = $rateTable->effectiveOn($asOf);
assert_eq(0.0649, $rates['A'], 'Tier A base rate from rates.xml');
assert_eq(0.0899, $rates['B'], 'Tier B base rate from rates.xml');
assert_eq(0.1249, $rates['C'], 'Tier C base rate from rates.xml');
assert_eq(0.1899, $rates['D'], 'Tier D base rate from rates.xml');

$source = $rateTable->describeSource();
assert_true(strpos($source, 'conf/rates.xml') !== false, 'rate table source mentions conf/rates.xml');

// ---------------------------------------------------------------------------
// Test 2: Tier A, $18,000, 36 months, PERSONAL — no surcharges
// ---------------------------------------------------------------------------

echo "\n-- Tier A, $18k, 36mo, PERSONAL (no surcharges) --\n";

$req = new PriceRequest('A', 18000.0, 36, 'PERSONAL', $asOf);
$result = $engine->price($req);

assert_eq(0.065, $result->apr, 'Tier A 36mo APR is 0.065 (rounded from 0.0649)');
assert_eq(0.0649, $result->baseRate, 'base rate is 0.0649');
assert_eq(0.0, $result->termSurcharge, 'no term surcharge for 36 months');
assert_eq(0.0, $result->largeLoanSurcharge, 'no large-loan surcharge under $25k');
assert_eq('6.500%', $result->aprAsPercentage(), 'APR formatted as 6.500%');
assert_true(!$result->isDeclineSentinel(), 'not a decline sentinel');

// ---------------------------------------------------------------------------
// Test 3: Tier B, $30,000, 48 months, AUTO — both surcharges apply
// ---------------------------------------------------------------------------

echo "\n-- Tier B, $30k, 48mo, AUTO (both surcharges) --\n";

$req = new PriceRequest('B', 30000.0, 48, 'AUTO', $asOf);
$result = $engine->price($req);

// base=0.0899, term surcharge=0.0025 (37-48 band), large-loan=0.0040 (25k-40k band)
// unrounded = 0.0899 + 0.0025 + 0.0040 = 0.0964
// rounded to 3 dp = 0.096
assert_eq(0.096, $result->apr, 'Tier B 48mo $30k APR is 0.096');
assert_eq(0.0899, $result->baseRate, 'base rate is 0.0899');
assert_eq(0.0025, $result->termSurcharge, 'term surcharge is 0.0025 (37-48 band)');
assert_eq(0.0040, $result->largeLoanSurcharge, 'large-loan surcharge is 0.0040 (25k-40k band)');
assert_eq(0.0065, round($result->totalSurcharge(), 4), 'total surcharge is 0.0065');

// ---------------------------------------------------------------------------
// Test 4: Tier B, $60,000, 60 months, AUTO — higher bands
// ---------------------------------------------------------------------------

echo "\n-- Tier B, $60k, 60mo, AUTO (higher bands) --\n";

$req = new PriceRequest('B', 60000.0, 60, 'AUTO', $asOf);
$result = $engine->price($req);

// base=0.0899, term=0.0050 (49-60 band), large-loan=0.0055 (>40k band)
// unrounded = 0.0899 + 0.0050 + 0.0055 = 0.1004
// rounded = 0.100
assert_eq(0.100, $result->apr, 'Tier B 60mo $60k APR is 0.100');
assert_eq(0.0050, $result->termSurcharge, 'term surcharge is 0.0050 (49-60 band)');
assert_eq(0.0055, $result->largeLoanSurcharge, 'large-loan surcharge is 0.0055 (>40k band)');

// ---------------------------------------------------------------------------
// Test 5: Tier C, $52,000, 72 months, AUTO — top term band
// ---------------------------------------------------------------------------

echo "\n-- Tier C, $52k, 72mo, AUTO (top term band) --\n";

$req = new PriceRequest('C', 52000.0, 72, 'AUTO', $asOf);
$result = $engine->price($req);

// base=0.1249, term=0.0075 (61+ band), large-loan=0.0055 (>40k)
// unrounded = 0.1249 + 0.0075 + 0.0055 = 0.1379
// rounded = 0.138
assert_eq(0.138, $result->apr, 'Tier C 72mo $52k APR is 0.138');
assert_eq(0.0075, $result->termSurcharge, 'term surcharge is 0.0075 (61+ band)');

// ---------------------------------------------------------------------------
// Test 6: Tier D, $25,000, 49 months, PERSONAL — subprime
// ---------------------------------------------------------------------------

echo "\n-- Tier D, $25k, 49mo, PERSONAL (subprime) --\n";

$req = new PriceRequest('D', 25000.0, 49, 'PERSONAL', $asOf);
$result = $engine->price($req);

// base=0.1899, term=0.0050 (49-60 band), large-loan=0.0 (<=25k boundary)
// unrounded = 0.1899 + 0.0050 + 0.0 = 0.1949
// rounded = 0.195
assert_eq(0.195, $result->apr, 'Tier D 49mo $25k APR is 0.195');
assert_eq(0.0, $result->largeLoanSurcharge, 'no large-loan surcharge at exactly $25k');

// ---------------------------------------------------------------------------
// Test 7: DECLINE tier — sentinel rate
// ---------------------------------------------------------------------------

echo "\n-- DECLINE tier (sentinel rate) --\n";

$req = new PriceRequest('DECLINE', 10000.0, 36, 'PERSONAL', $asOf);
$result = $engine->price($req);

assert_eq(1.0, $result->apr, 'DECLINE APR rounds to 1.0 (100.000%)');
assert_true($result->isDeclineSentinel(), 'isDeclineSentinel returns true');
assert_eq('100.000%', $result->aprAsPercentage(), 'DECLINE displays as 100.000%');

// ---------------------------------------------------------------------------
// Test 8: PriceRequest::fromLoanRow integration
// ---------------------------------------------------------------------------

echo "\n-- PriceRequest::fromLoanRow integration --\n";

$loanRow = array(
    'amount'      => 30000.0,
    'term_months' => 48,
    'tier'        => 'B',
    'notes'       => 'AUTO - dealer submission',
    'created_at'  => '2025-09-01T10:00:00',
);

$req = PriceRequest::fromLoanRow($loanRow);
$result = $engine->price($req);

assert_eq('AUTO', $req->productCode, 'product inferred as AUTO from notes prefix');
assert_eq(0.096, $result->apr, 'fromLoanRow produces same APR as direct construction');

// Test PERSONAL inference (no prefix in notes)
$personalRow = array(
    'amount'      => 18000.0,
    'term_months' => 36,
    'tier'        => 'A',
    'notes'       => '',
    'created_at'  => '2025-09-01T10:00:00',
);
$reqPersonal = PriceRequest::fromLoanRow($personalRow);
assert_eq('PERSONAL', $reqPersonal->productCode, 'product defaults to PERSONAL with no notes prefix');

// Test HOMEIMP inference
$homeimpRow = array(
    'amount'      => 60000.0,
    'term_months' => 60,
    'tier'        => 'B',
    'notes'       => 'HOMEIMP - kitchen remodel',
    'created_at'  => '2025-09-01T10:00:00',
);
$reqHomeimp = PriceRequest::fromLoanRow($homeimpRow);
assert_eq('HOMEIMP', $reqHomeimp->productCode, 'product inferred as HOMEIMP from notes prefix');

// ---------------------------------------------------------------------------
// Test 9: PriceResult::explain() produces human-readable output
// ---------------------------------------------------------------------------

echo "\n-- PriceResult::explain() --\n";

$req = new PriceRequest('B', 30000.0, 48, 'AUTO', $asOf);
$result = $engine->price($req);
$explanation = $result->explain();

assert_true(strpos($explanation, 'AUTO') !== false, 'explain() mentions product AUTO');
assert_true(strpos($explanation, '0.0960') !== false, 'explain() shows APR component');
assert_true(strpos($explanation, 'Engine') !== false, 'explain() mentions engine version');

// ---------------------------------------------------------------------------
// Test 10: Breakdown contains provenance info
// ---------------------------------------------------------------------------

echo "\n-- Breakdown provenance --\n";

$breakdown = $result->breakdown;
assert_eq('B', $breakdown['tier'], 'breakdown tier is B');
assert_eq('AUTO', $breakdown['product_code'], 'breakdown product is AUTO');
assert_eq(30000.0, $breakdown['amount'], 'breakdown amount is 30000');
assert_eq(48, $breakdown['term_months'], 'breakdown term is 48');
assert_eq('37-48', $breakdown['term_band'], 'breakdown term band is 37-48');
assert_eq('25001-40000', $breakdown['large_loan_band'], 'breakdown large-loan band is 25001-40000');
assert_eq('half-up', $breakdown['apr_rounding_mode'], 'breakdown rounding mode is half-up');

echo "\n----------------------------\n";
echo $TESTS_RUN . " assertions, " . $TESTS_FAILED . " failed\n";
exit($TESTS_FAILED === 0 ? 0 : 1);
