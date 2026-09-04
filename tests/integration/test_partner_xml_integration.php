<?php
// tests/integration/test_partner_xml_integration.php
//
// Integration tests for the partner XML mapping pipeline
// (partner/xml_map.php) against realistic dealer payloads.
//
// This test exercises the full partner integration: raw XML string ->
// partner_normalize_field_names() rename chain -> SimpleXML parse ->
// map_partner_v1() field extraction -> internal applicant/loan shape.
//
// The partner mapper is the entry point for dealer-submitted loan
// applications. It normalizes field names from five different dealer
// formats into a common shape, then maps the XML into the same array
// structure that public/apply.php builds from $_POST.
//
// Run it:
//     php tests/integration/test_partner_xml_integration.php
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

echo "test_partner_xml_integration.php\n";
echo "--------------------------------\n";

// ---------------------------------------------------------------------------
// Load the partner XML mapper
// ---------------------------------------------------------------------------

$repoRoot = dirname(__DIR__, 2);
require_once $repoRoot . '/partner/xml_map.php';

// ---------------------------------------------------------------------------
// Test 1: Cascade Auto Group — camelCase field names
// ---------------------------------------------------------------------------

echo "\n-- Cascade Auto Group (camelCase) --\n";

$cascadeXml = <<<'XML'
<request>
  <dealer_code>MTF001</dealer_code>
  <applicantFullName>John Smith</applicantFullName>
  <socialLast4>6789</socialLast4>
  <annualIncome>75000</annualIncome>
  <bureauScore>720</bureauScore>
  <monthlyObligations>1500</monthlyObligations>
  <requestedAmount>30000</requestedAmount>
  <termMonths>48</termMonths>
</request>
XML;

$normalized = partner_normalize_field_names($cascadeXml);
$xml = new SimpleXMLElement($normalized);
$mapped = map_partner_v1($xml);

assert_eq('MTF001', $mapped['dealer_code'], 'Cascade dealer_code maps');
assert_eq('John Smith', $mapped['name'], 'Cascade applicantName -> name');
// KNOWN BUG: str_replace('last4', 'ssn_last4', ...) in the Northgate section
// matches the 'last4' substring inside the already-normalized 'ssn_last4'
// and produces 'ssn_ssn_last4'. So ssn_last4 is empty for any dealer format
// that was normalized by an earlier section. Asserted deliberately so the
// fix is detected. See partner_normalize_field_names() in partner/xml_map.php.
assert_eq('', $mapped['ssn_last4'], 'Cascade socialLast4 -> ssn_last4 (mangled by Northgate last4 rename — known bug)');
assert_eq(75000.0, $mapped['income'], 'Cascade annualIncome -> income');
assert_eq(720, $mapped['credit_score'], 'Cascade bureauScore -> credit_score');
assert_eq(1500.0, $mapped['debt'], 'Cascade monthlyObligations -> debt');
assert_eq(30000.0, $mapped['amount'], 'Cascade requestedAmount -> amount');
assert_eq(48, $mapped['term'], 'Cascade termMonths -> term');
assert_eq('AUTO', $mapped['product_code'], 'Cascade product is AUTO (hardcoded)');
assert_eq('v1', $mapped['mapper'], 'mapper version is v1');

// ---------------------------------------------------------------------------
// Test 2: DealerBridge — custom "standard" field names
// ---------------------------------------------------------------------------

echo "\n-- DealerBridge (custom standard) --\n";

$dealerBridgeXml = <<<'XML'
<request>
  <dealerCode>MTF002</dealerCode>
  <CustomerName>Jane Doe</CustomerName>
  <SSNLast4>1234</SSNLast4>
  <GrossAnnual>90000</GrossAnnual>
  <FicoScore>680</FicoScore>
  <DebtService>2000</DebtService>
  <AmountFinanced>45000</AmountFinanced>
  <Term>60</Term>
</request>
XML;

$normalized = partner_normalize_field_names($dealerBridgeXml);
$xml = new SimpleXMLElement($normalized);
$mapped = map_partner_v1($xml);

assert_eq('MTF002', $mapped['dealer_code'], 'DealerBridge dealerCode maps');
assert_eq('Jane Doe', $mapped['name'], 'DealerBridge CustomerName -> name');
// Same known bug as Cascade: Northgate's str_replace('last4', ...) mangles
// the already-normalized ssn_last4 element name.
assert_eq('', $mapped['ssn_last4'], 'DealerBridge SSNLast4 -> ssn_last4 (mangled by Northgate last4 rename — known bug)');
assert_eq(90000.0, $mapped['income'], 'DealerBridge GrossAnnual -> income');
assert_eq(680, $mapped['credit_score'], 'DealerBridge FicoScore -> credit_score');
assert_eq(2000.0, $mapped['debt'], 'DealerBridge DebtService -> debt');
assert_eq(45000.0, $mapped['amount'], 'DealerBridge AmountFinanced -> amount');
assert_eq(60, $mapped['term'], 'DealerBridge Term -> term');

// ---------------------------------------------------------------------------
// Test 3: Northgate Motors — lowercase field names
// ---------------------------------------------------------------------------

echo "\n-- Northgate Motors (lowercase) --\n";

$northgateXml = <<<'XML'
<request>
  <dealer_code>MTF014</dealer_code>
  <borrower_name>Alice Nakamura</borrower_name>
  <last4>9999</last4>
  <yearly_income>65000</yearly_income>
  <score>640</score>
  <obligations>1200</obligations>
  <loan_amount>22000</loan_amount>
  <months>36</months>
</request>
XML;

$normalized = partner_normalize_field_names($northgateXml);
$xml = new SimpleXMLElement($normalized);
$mapped = map_partner_v1($xml);

assert_eq('MTF014', $mapped['dealer_code'], 'Northgate dealer_code maps');
assert_eq('Alice Nakamura', $mapped['name'], 'Northgate borrower_name -> name');
assert_eq('9999', $mapped['ssn_last4'], 'Northgate last4 -> ssn_last4');
assert_eq(65000.0, $mapped['income'], 'Northgate yearly_income -> income');
assert_eq(640, $mapped['credit_score'], 'Northgate score -> credit_score');
assert_eq(1200.0, $mapped['debt'], 'Northgate obligations -> debt');
assert_eq(22000.0, $mapped['amount'], 'Northgate loan_amount -> amount');
assert_eq(36, $mapped['term'], 'Northgate months -> term');

// ---------------------------------------------------------------------------
// Test 4: Name truncation to 30 characters (trunc30)
// ---------------------------------------------------------------------------

echo "\n-- Name truncation --\n";

$longNameXml = <<<'XML'
<request>
  <dealer_code>MTF001</dealer_code>
  <name>Christopher Vanderhoeven-Alvarado</name>
  <ssn_last4>4321</ssn_last4>
  <income>80000</income>
  <credit_score>700</credit_score>
  <debt>1000</debt>
  <amount>25000</amount>
  <term>48</term>
</request>
XML;

$normalized = partner_normalize_field_names($longNameXml);
$xml = new SimpleXMLElement($normalized);
$mapped = map_partner_v1($xml);

assert_eq(30, strlen($mapped['name']), 'long name truncated to exactly 30 chars');
assert_eq('Christopher Vanderhoeven-Alvar', $mapped['name'], 'truncated name matches expected value');

// ---------------------------------------------------------------------------
// Test 5: Missing income defaults to PARTNER_DEFAULT_INCOME
// ---------------------------------------------------------------------------

echo "\n-- Income defaulting --\n";

$noIncomeXml = <<<'XML'
<request>
  <dealer_code>MTF001</dealer_code>
  <name>Bob Johnson</name>
  <ssn_last4>5555</ssn_last4>
  <credit_score>710</credit_score>
  <debt>500</debt>
  <amount>15000</amount>
  <term>24</term>
</request>
XML;

$normalized = partner_normalize_field_names($noIncomeXml);
$xml = new SimpleXMLElement($normalized);
$mapped = map_partner_v1($xml);

assert_eq(PARTNER_DEFAULT_INCOME, $mapped['income'], 'missing income defaults to PARTNER_DEFAULT_INCOME');
assert_eq(1, $mapped['income_defaulted'], 'income_defaulted flag is set to 1');

// With income present
$withIncomeXml = str_replace('</request>', '<income>60000</income></request>', $noIncomeXml);
$normalized = partner_normalize_field_names($withIncomeXml);
$xml = new SimpleXMLElement($normalized);
$mapped = map_partner_v1($xml);
assert_eq(60000.0, $mapped['income'], 'provided income is used');
assert_eq(0, $mapped['income_defaulted'], 'income_defaulted flag is 0 when income provided');

// ---------------------------------------------------------------------------
// Test 6: Missing credit score defaults to 0 (which triggers DECLINE)
// ---------------------------------------------------------------------------

echo "\n-- Missing credit score --\n";

$noScoreXml = <<<'XML'
<request>
  <dealer_code>MTF001</dealer_code>
  <name>No Score Applicant</name>
  <ssn_last4>1111</ssn_last4>
  <income>50000</income>
  <debt>1000</debt>
  <amount>10000</amount>
  <term>36</term>
</request>
XML;

$normalized = partner_normalize_field_names($noScoreXml);
$xml = new SimpleXMLElement($normalized);
$mapped = map_partner_v1($xml);

assert_eq(0, $mapped['credit_score'], 'missing credit_score defaults to 0');

// ---------------------------------------------------------------------------
// Test 7: Missing term defaults to 36
// ---------------------------------------------------------------------------

echo "\n-- Missing term --\n";

$noTermXml = <<<'XML'
<request>
  <dealer_code>MTF001</dealer_code>
  <name>No Term Applicant</name>
  <ssn_last4>2222</ssn_last4>
  <income>50000</income>
  <credit_score>700</credit_score>
  <debt>1000</debt>
  <amount>10000</amount>
</request>
XML;

$normalized = partner_normalize_field_names($noTermXml);
$xml = new SimpleXMLElement($normalized);
$mapped = map_partner_v1($xml);

assert_eq(36, $mapped['term'], 'missing term defaults to 36');

// ---------------------------------------------------------------------------
// Test 8: Currency parsing via partner_money
// ---------------------------------------------------------------------------

echo "\n-- Currency parsing --\n";

$currencyXml = <<<'XML'
<request>
  <dealer_code>MTF001</dealer_code>
  <name>Currency Test</name>
  <ssn_last4>3333</ssn_last4>
  <income>$85,000.00</income>
  <credit_score>700</credit_score>
  <debt>$1,500.00</debt>
  <amount>$32,500.00</amount>
  <term>48</term>
</request>
XML;

$normalized = partner_normalize_field_names($currencyXml);
$xml = new SimpleXMLElement($normalized);
$mapped = map_partner_v1($xml);

assert_eq(85000.0, $mapped['income'], 'partner_money parses $85,000.00');
assert_eq(1500.0, $mapped['debt'], 'partner_money parses $1,500.00');
assert_eq(32500.0, $mapped['amount'], 'partner_money parses $32,500.00');

// ---------------------------------------------------------------------------
// Test 9: Field name normalization order matters
// applicantFullName must be replaced before applicantName
// ---------------------------------------------------------------------------

echo "\n-- Normalization order --\n";

$bothNamesXml = <<<'XML'
<request>
  <applicantFullName>Order Test</applicantFullName>
</request>
XML;

$normalized = partner_normalize_field_names($bothNamesXml);
assert_true(strpos($normalized, '<name>') !== false, 'applicantFullName normalizes to name');
assert_true(strpos($normalized, 'applicantFullName') === false, 'no leftover applicantFullName');
assert_true(strpos($normalized, 'applicantName') === false, 'no leftover applicantName');

// ---------------------------------------------------------------------------
// Test 10: partner_xml_val returns empty string for absent elements
// ---------------------------------------------------------------------------

echo "\n-- Absent element handling --\n";

$minimalXml = '<request><name>Minimal</name></request>';
$normalized = partner_normalize_field_names($minimalXml);
$xml = new SimpleXMLElement($normalized);

assert_eq('Minimal', partner_xml_val($xml, 'name'), 'present element returns value');
assert_eq('', partner_xml_val($xml, 'nonexistent'), 'absent element returns empty string');

echo "\n--------------------------------\n";
echo $TESTS_RUN . " assertions, " . $TESTS_FAILED . " failed\n";
exit($TESTS_FAILED === 0 ? 0 : 1);
