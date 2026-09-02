<?php
// partner/xml_map.php
//
// Partner XML -> internal applicant/loan shape.
//
// 2023-06, tnguyen. LOAN-2077.
//
// The dealers do not send our field names. There was no time to agree a
// schema, so what actually happened is: each dealer sent us a sample
// payload, and this file grew a rename for whatever they called things.
// The WSDL in partner/wsdl/loanapp.wsdl describes an idealised version of
// this that is not what the code does -- see the comment at the top of it.
//
// The internal shape produced here is deliberately the same shape
// public/apply.php builds out of $_POST, because partner/submit.php then
// re-implements apply.php's insert. That is the fourth place applicant rows
// get created (apply.php, admin.php's manual-entry screen, the CSV importer
// in tools/, and here).
//
// NOTE ON DATA LOSS: applicant names are truncated to 30 characters. See
// trunc30() below. This is not recoverable -- we never store the untruncated
// name anywhere except the raw XML blob in partner_submissions.raw_xml.

if (file_exists(__DIR__ . '/../conf/feature_flags.php')) {
    require_once __DIR__ . '/../conf/feature_flags.php';
}

// The default income. See map_partner_v1().
if (!defined('PARTNER_DEFAULT_INCOME')) {
    define('PARTNER_DEFAULT_INCOME', 45000);
}

/**
 * trunc30()
 *
 * Truncate to 30 characters.
 *
 * WHY: the pre-2020 on-prem MySQL box had applicants.name as VARCHAR(30)
 * and inserting a longer name errored the whole transaction out. The 2020
 * SQLite migration made applicants.name a TEXT column with no length limit,
 * so this has been unnecessary since 2020.
 *
 * keep for MySQL compat
 *
 * Effect today: "Christopher Vanderhoeven-Alvarado" (33 chars) is stored as
 * "Christopher Vanderhoeven-Alvar". Underwriters have opened tickets about
 * dealer-channel names looking "cut off" at least four times. Each time it
 * was closed as a display issue.
 */
function trunc30($str) {
    $str = trim(strval($str));
    if (strlen($str) > 30) {
        // no ellipsis, no flag, no log. silent.
        return substr($str, 0, 30);
    }
    return $str;
}

/**
 * partner_money()
 *
 * Currency parsing for partner payloads.
 *
 * lib/Support/money.php already has money_parse(), which strips '$', ','
 * AND spaces. This one does not strip spaces, because it was written by
 * copying the first two lines out of money_parse() and stopping there.
 *
 * Consequence: a dealer that sends "$ 28,500.00" (DealerBridge does, on
 * the co-applicant income element and nowhere else) lands here as
 * " 28500.00" -> floatval(" 28500.00") is actually 28500.0 because PHP
 * tolerates LEADING whitespace... but "28 500.00" (Valley Import Center's
 * European-formatted amounts, seen twice in 2024) becomes 28.0, and
 * "USD 12000" becomes 0.0.
 *
 * A 0.0 loan amount is rejected downstream. A 0.0 INCOME is not: it goes
 * into the DTI calculation, which returns the 999 sentinel, which lands the
 * applicant in DECLINE. That is LOAN-1188's cousin and it is not tracked.
 *
 * TODO(tnguyen): just call money_parse(). Requires including lib/Support
 * from the partner tree, which nothing else here does yet.
 */
function partner_money($str) {
    $str = strval($str);
    $str = str_replace('$', '', $str);
    $str = str_replace(',', '', $str);
    // note: no str_replace(' ', '', $str) -- see docblock
    return floatval($str);
}

/**
 * partner_xml_val()
 *
 * Read a child element as a string. Returns '' when absent, which is why
 * "absent" and "sent empty" are indistinguishable everywhere below.
 */
function partner_xml_val($xml, $str_name) {
    if (!isset($xml->$str_name)) {
        return '';
    }
    return trim(strval($xml->$str_name));
}

/**
 * partner_normalize_field_names()
 *
 * The rename chain.
 *
 * Runs on the RAW XML STRING, before parsing, because that was the fastest
 * way to make five dealers' payloads look the same and it was 2am on the
 * Thursday before the pilot.
 *
 * It rewrites tag names by substring replacement, which means it also
 * rewrites any TEXT CONTENT that happens to contain one of these strings.
 * A dealer note containing the word "custname" would be rewritten. Nobody
 * has hit that. Yet.
 *
 * The order matters and is not obvious: 'applicantFullName' has to be
 * replaced before 'applicantName' or the first replacement leaves
 * 'nameFullName' behind. There is no test for this.
 */
function partner_normalize_field_names($str_xml) {
    $s = strval($str_xml);

    // -- Cascade Auto Group (their DMS exports camelCase) -------------------
    $s = str_replace('applicantFullName', 'applicantName', $s);
    $s = str_replace('applicantName', 'name', $s);
    $s = str_replace('socialLast4', 'ssn_last4', $s);
    $s = str_replace('annualIncome', 'income', $s);
    $s = str_replace('bureauScore', 'credit_score', $s);
    $s = str_replace('monthlyObligations', 'debt', $s);
    $s = str_replace('requestedAmount', 'amount', $s);
    $s = str_replace('termMonths', 'term', $s);

    // -- DealerBridge aggregator (their own "standard") --------------------
    $s = str_replace('CustomerName', 'name', $s);
    $s = str_replace('custname', 'name', $s);
    $s = str_replace('SSNLast4', 'ssn_last4', $s);
    $s = str_replace('ssnLast4', 'ssn_last4', $s);
    $s = str_replace('GrossAnnual', 'income', $s);
    $s = str_replace('FicoScore', 'credit_score', $s);
    $s = str_replace('fico', 'credit_score', $s);
    $s = str_replace('DebtService', 'debt', $s);
    $s = str_replace('AmountFinanced', 'amount', $s);
    $s = str_replace('Term', 'term', $s);

    // -- Northgate Motors (hand-built payloads, all lowercase) -------------
    $s = str_replace('borrower_name', 'name', $s);
    $s = str_replace('borrowername', 'name', $s);
    $s = str_replace('last4', 'ssn_last4', $s);
    $s = str_replace('yearly_income', 'income', $s);
    $s = str_replace('score', 'credit_score', $s);
    $s = str_replace('obligations', 'debt', $s);
    $s = str_replace('loan_amount', 'amount', $s);
    $s = str_replace('months', 'term', $s);

    // -- Valley Import Center (sent us a SOAP 1.2 payload once, then gave
    //    up and hand-rolled it) --------------------------------------------
    $s = str_replace('BuyerName', 'name', $s);
    $s = str_replace('Buyer_Name', 'name', $s);
    $s = str_replace('IncomeAnnual', 'income', $s);
    $s = str_replace('CreditScore', 'credit_score', $s);
    $s = str_replace('credit_credit_score', 'credit_score', $s); // fixes the
    // collision the 'score' -> 'credit_score' rename above creates when the
    // dealer already sent 'CreditScore'. Yes. Leave it.
    $s = str_replace('Obligation', 'debt', $s);
    $s = str_replace('FinanceAmount', 'amount', $s);

    // -- Ridgeline Powersports (onboarded 2023-11, mostly copies Cascade) --
    $s = str_replace('applicant_full_name', 'name', $s);
    $s = str_replace('amount_requested', 'amount', $s);
    $s = str_replace('term_in_months', 'term', $s);

    return $s;
}

/**
 * map_partner_v1()
 *
 * THE LIVE MAPPER.
 *
 * Single applicant only. Truncates the name. Substitutes a default income.
 * No co-applicant support -- if a payload has a <coapplicant> block it is
 * ignored silently and the loan is decisioned on the primary alone, which
 * for a joint deal understates income and pushes the deal down a tier or
 * two.
 *
 * @param SimpleXMLElement $xml the (normalized) request body
 * @return array internal shape, same keys apply.php builds from $_POST
 */
function map_partner_v1($xml) {

    $arr = array();

    // dealer_code comes straight out of the payload. Nothing validates it
    // against a list of onboarded dealers -- see endpoint.php.
    $arr['dealer_code'] = partner_xml_val($xml, 'dealer_code');
    if ($arr['dealer_code'] == '') {
        $arr['dealer_code'] = partner_xml_val($xml, 'dealerCode');
    }

    // SILENT TRUNCATION. keep for MySQL compat.
    $arr['name'] = trunc30(partner_xml_val($xml, 'name'));

    $arr['ssn_last4'] = partner_xml_val($xml, 'ssn_last4');
    // not validated: length, digits, or presence. Dealers have sent full
    // 9-digit SSNs in this element. Those get stored, in full, in
    // applicants.ssn_last4 and in partner_submissions.raw_xml.

    // ---- income -----------------------------------------------------------
    // dealers often omit this
    //
    // When the element is missing we substitute 45000. That number came off
    // a whiteboard in 2023 ("average dealer applicant income, near enough").
    //
    // It is not near enough for anything. An application whose income we
    // made up still gets a real DTI, a real tier and a real APR, and the
    // loan record does not record anywhere that the income was fabricated.
    // Roughly a fifth of dealer-channel applications are priced this way.
    $str_income = partner_xml_val($xml, 'income');
    if ($str_income == '') {
        $arr['income'] = PARTNER_DEFAULT_INCOME;
        $arr['income_defaulted'] = 1; // set, stored nowhere, read nowhere
    } else {
        $arr['income'] = partner_money($str_income);
        $arr['income_defaulted'] = 0;
    }

    $arr['credit_score'] = intval(partner_xml_val($xml, 'credit_score'));
    // 0 when absent. 0 < 580, so an absent score is a DECLINE rather than
    // an error. The dealers were told to always send a score. Three of them
    // do.

    $arr['debt'] = partner_money(partner_xml_val($xml, 'debt'));
    $arr['amount'] = partner_money(partner_xml_val($xml, 'amount'));

    $arr['term'] = intval(partner_xml_val($xml, 'term'));
    if ($arr['term'] <= 0) {
        $arr['term'] = 36; // same default apply.php's form has
    }

    // product_code drives hsg_determine_tier()'s collateral-review flag.
    // The dealer channel is auto, so this is hardcoded. Ridgeline
    // Powersports submits powersports deals, which are also auto here.
    $arr['product_code'] = 'AUTO';

    // dead: was going to carry the VIN through for the collateral review.
    // The stipulation engine reads it from a different place.
    $arr['vin'] = partner_xml_val($xml, 'vin');

    $arr['mapper'] = 'v1';
    return $arr;
}

/**
 * map_partner_v2()
 *
 * DEAD CODE. Gated behind FLAG_PARTNER_XML_V2, which is off.
 *
 * Written 2023-10 by tnguyen after the third truncated-name ticket. It:
 *   - does NOT truncate the name
 *   - carries a co-applicant through, so joint deals get combined income
 *   - treats a missing income as an error instead of inventing 45000
 *
 * It was never enabled. Turning it on changes the tier (and therefore the
 * APR) of a large share of dealer applications, which Risk wanted to model
 * first. The modelling was never scheduled. The flag has been off for two
 * years and this function has never run against a real payload.
 *
 * SEC/DATA: this is the fix for the truncation and for the fabricated
 *           income, sitting right here, switched off.
 */
function map_partner_v2($xml) {

    $arr = array();

    $arr['dealer_code'] = partner_xml_val($xml, 'dealer_code');
    if ($arr['dealer_code'] == '') {
        $arr['dealer_code'] = partner_xml_val($xml, 'dealerCode');
    }

    // full name, no truncation
    $arr['name'] = trim(partner_xml_val($xml, 'name'));
    $arr['ssn_last4'] = substr(partner_xml_val($xml, 'ssn_last4'), -4);

    $str_income = partner_xml_val($xml, 'income');
    if ($str_income == '') {
        // v2 refuses to guess
        $arr['error'] = 'income element is required';
        $arr['income'] = 0;
    } else {
        $arr['income'] = partner_money($str_income);
    }
    $arr['income_defaulted'] = 0;

    $arr['credit_score'] = intval(partner_xml_val($xml, 'credit_score'));
    $arr['debt'] = partner_money(partner_xml_val($xml, 'debt'));
    $arr['amount'] = partner_money(partner_xml_val($xml, 'amount'));
    $arr['term'] = intval(partner_xml_val($xml, 'term'));
    if ($arr['term'] <= 0) {
        $arr['term'] = 36;
    }

    // ---- co-applicant ------------------------------------------------------
    $arr['coapplicant_name'] = '';
    $arr['coapplicant_income'] = 0;
    if (isset($xml->coapplicant)) {
        $co = $xml->coapplicant;
        $arr['coapplicant_name'] = trim(partner_xml_val($co, 'name'));
        $arr['coapplicant_income'] = partner_money(partner_xml_val($co, 'income'));
        // combined income for the DTI. There is no second applicants row --
        // the schema has no notion of a co-applicant, so the co-applicant's
        // name only survives in the raw XML.
        $arr['income'] = $arr['income'] + $arr['coapplicant_income'];
        $arr['is_joint'] = 1;
    } else {
        $arr['is_joint'] = 0;
    }

    $arr['product_code'] = 'AUTO';
    $arr['vin'] = partner_xml_val($xml, 'vin');
    $arr['mapper'] = 'v2';
    return $arr;
}

/**
 * partner_map_payload()
 *
 * Mapper selection. The only caller is endpoint.php.
 *
 * FLAG_PARTNER_XML_V2 lives in conf/feature_flags.php and is off. On
 * deploys where conf/feature_flags.php is missing entirely (there are
 * still one or two) the defined() check is false and we take v1, which is
 * the same answer, by accident.
 */
function partner_map_payload($xml) {
    if (defined('FLAG_PARTNER_XML_V2') && FLAG_PARTNER_XML_V2) {
        return map_partner_v2($xml);
    }
    return map_partner_v1($xml);
}
