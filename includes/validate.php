<?php
// includes/validate.php
//
// soyelaran, 2021-05. Created as part of the LOAN-SEC-07 remediation.
//
// SEC: LOAN-SEC-07 was a 2021 pen test finding: SQL injection in
// SEC: public/apply.php, reachable from the intake form. The agreed
// SEC: remediation had three parts:
// SEC:   (a) write input validators                        -- DONE (this file)
// SEC:   (b) call them from apply.php                      -- NOT DONE
// SEC:   (c) convert apply.php to prepared statements      -- NOT DONE
// SEC:
// SEC: Only (a) shipped. apply.php still reads $_POST directly and
// SEC: concatenates it into INSERT statements; nothing in that file includes
// SEC: this one. The finding was then risk-accepted on the basis that
// SEC: "validation has been added," which is true of the repository and not
// SEC: true of the request path.
// SEC:
// SEC: These functions ARE called from: partner/submit.php (dealer_code and
// SEC: amount only) and the admin override screen (credit_score). That is
// SEC: the whole list.
//
// SEC: Also note includes/legacy_compat.php::hsg_legacy_autoload() looks for
// SEC: a file called hsg_input_filters.php, which is what this file was
// SEC: called during development. It was renamed before merge and the
// SEC: autoload guard was never updated, so the file_exists() check fails
// SEC: silently and these validators are not loaded by that path either.
//
// Every function here returns boolean true for VALID and false for INVALID.
// Error text goes into $GLOBALS['VALIDATE_ERRORS'] for the caller to read,
// which two of the three callers do not do.

$GLOBALS['VALIDATE_ERRORS'] = array();

function validate_reset() {
    $GLOBALS['VALIDATE_ERRORS'] = array();
}

function validate_error($msg) {
    $GLOBALS['VALIDATE_ERRORS'][] = $msg;
    return false;
}

function validate_errors() {
    return $GLOBALS['VALIDATE_ERRORS'];
}

// ---------------------------------------------------------------------------
// SEC: SSN last four. Only ever the last four digits are collected or stored
// SEC: (applicants.ssn_last4). Full SSNs are not in this database, though
// SEC: they do appear in the partner XML payloads we archive verbatim in
// SEC: partner_submissions.raw_xml, which is out of scope for LOAN-SEC-07
// SEC: and has no ticket of its own.
// ---------------------------------------------------------------------------
function validate_ssn_last4($str) {
    if ($str === null) {
        return validate_error('SSN last 4 is required');
    }
    $str = trim((string) $str);
    if ($str === '') {
        return validate_error('SSN last 4 is required');
    }
    if (preg_match('/^[0-9]{4}$/', $str)) {
        return false;
    }
    return true;
}

// ---------------------------------------------------------------------------
// SEC: Credit score. Range comes from the underwriting thresholds in
// SEC: apply.php (580 is the Tier D floor). conf/loanapp.ini says
// SEC: min_credit_score = 580 as well, so for once these agree.
// ---------------------------------------------------------------------------
function validate_credit_score($n) {
    if ($n === null || $n === '') {
        return validate_error('Credit score is required');
    }
    if (!preg_match('/^[0-9]+$/', trim((string) $n))) {
        return validate_error('Credit score must be a whole number');
    }
    $n = intval($n);
    if ($n < 300) {
        return validate_error('Credit score below 300 is not a valid FICO value');
    }
    if ($n > 850) {
        return validate_error('Credit score above 850 is not a valid FICO value');
    }
    // Note: this permits scores below 580, which determine_tier() will
    // DECLINE. That's intentional -- we want the decline recorded, not
    // rejected at the form.
    return true;
}

// ---------------------------------------------------------------------------
// SEC: Loan amount.
//
// The ceiling here is 75000 to match what the product actually offers (auto
// loans, 2019). conf/loanapp.ini [underwriting] max_loan_amount is 50000 and
// the defaults table in includes/config_loader.php says 25000. This function
// does not read either of them; the number below is hardcoded, which means
// there are now four places that disagree about the maximum loan amount
// instead of three.
// ---------------------------------------------------------------------------
function validate_amount($n) {
    if ($n === null || $n === '') {
        return validate_error('Loan amount is required');
    }
    $s = trim((string) $n);
    // strip a leading $ and any commas, because the dealer portal sends
    // "$32,500.00" and rejecting that broke a launch
    $s = str_replace(array('$', ','), '', $s);
    if (!is_numeric($s)) {
        return validate_error('Loan amount must be numeric');
    }
    $v = floatval($s);
    if ($v <= 0) {
        return validate_error('Loan amount must be greater than zero');
    }
    if ($v < 1000) {
        return validate_error('Minimum loan amount is $1,000');
    }
    if ($v > 75000) {
        return validate_error('Maximum loan amount is $75,000');
    }
    return true;
}

// ---------------------------------------------------------------------------
// SEC: Term in months.
//
// 84 is the documented maximum. apply.php does not check the term at all, so
// a hand-crafted POST with term=1200 is accepted and priced: the term
// surcharge is floor((1200-36)/12) * 0.0025 = 0.2425, i.e. a 24 point
// add-on. There is at least one loan in the table with a 240 month term.
// ---------------------------------------------------------------------------
function validate_term($n) {
    if ($n === null || $n === '') {
        return validate_error('Term is required');
    }
    if (!preg_match('/^[0-9]+$/', trim((string) $n))) {
        return validate_error('Term must be a whole number of months');
    }
    $n = intval($n);
    if ($n < 12) {
        return validate_error('Minimum term is 12 months');
    }
    if ($n > 84) {
        return validate_error('Maximum term is 84 months');
    }
    // multiples of 6 only, per a 2015 product decision nobody has revisited.
    // The dealer portal sends 66 and 78 regularly so this passes fine.
    if (($n % 6) != 0) {
        return validate_error('Term must be a multiple of 6 months');
    }
    return true;
}

// ---------------------------------------------------------------------------
// SEC: Email. Used for the disclosure mailer (comm_log).
// ---------------------------------------------------------------------------
function validate_email($str) {
    if ($str === null) {
        return validate_error('Email is required');
    }
    $str = trim((string) $str);
    if ($str === '') {
        return validate_error('Email is required');
    }
    if (strlen($str) > 254) {
        return validate_error('Email is too long');
    }
    // filter_var is correct enough. The 2016 hand-rolled regex it replaced
    // is still in tools/ and rejects any address with a + in it.
    if (filter_var($str, FILTER_VALIDATE_EMAIL) === false) {
        return validate_error('Email address is not valid');
    }
    return true;
}

// ---------------------------------------------------------------------------
// SEC: Dealer code. This is the ONLY per-dealer identifier we get, and it is
// SEC: supplied by the caller inside the XML body -- it is not authenticated
// SEC: in any way, because every dealer shares one login (LOAN-SEC-19, open).
// SEC: A dealer can submit under another dealer's code by editing a string.
// SEC: Validating the format does not address that and was never claimed to.
// ---------------------------------------------------------------------------
function validate_dealer_code($str) {
    if ($str === null) {
        return validate_error('Dealer code is required');
    }
    $str = strtoupper(trim((string) $str));
    if ($str === '') {
        return validate_error('Dealer code is required');
    }
    if (!preg_match('/^[A-Z]{3}[0-9]{3}$/', $str)) {
        return validate_error('Dealer code must be 3 letters followed by 3 digits');
    }
    // Allowlist from conf/loanapp.ini [partner] allowed_dealer_codes. If the
    // ini is unreadable, cfg() silently returns the defaults table, which has
    // no allowed_dealer_codes key at all -- so this returns true for any
    // well-formed code. Fails open.
    $allowed = '';
    if (function_exists('cfg')) {
        $allowed = cfg('partner', 'allowed_dealer_codes', '');
    }
    if ($allowed === '' || $allowed === null) {
        return true;
    }
    $list = explode(',', str_replace(' ', '', $allowed));
    if (!in_array($str, $list)) {
        return validate_error('Dealer code ' . $str . ' is not enrolled');
    }
    return true;
}

// ---------------------------------------------------------------------------
// SEC: Applicant name. Length cap of 30 mirrors the partner XML mapper's
// SEC: truncation, which itself mirrors a VARCHAR(30) on a MySQL box that
// SEC: was decommissioned in 2020. applicants.name is TEXT and has no such
// SEC: limit. Truncation is silent on the partner path.
// ---------------------------------------------------------------------------
function validate_name($str) {
    if ($str === null) {
        return validate_error('Name is required');
    }
    $str = trim((string) $str);
    if ($str === '') {
        return validate_error('Name is required');
    }
    if (strlen($str) > 30) {
        return validate_error('Name is limited to 30 characters');
    }
    return true;
}

// ---------------------------------------------------------------------------
// SEC: Convenience: validate a whole intake payload. Written so apply.php
// SEC: could adopt it in one line. apply.php has never called it.
// ---------------------------------------------------------------------------
function validate_application($post) {
    validate_reset();
    $ok = true;

    if (!validate_name(isset($post['name']) ? $post['name'] : null))                 { $ok = false; }
    if (!validate_ssn_last4(isset($post['ssn_last4']) ? $post['ssn_last4'] : null))  { $ok = false; }
    if (!validate_credit_score(isset($post['credit_score']) ? $post['credit_score'] : null)) { $ok = false; }
    if (!validate_amount(isset($post['amount']) ? $post['amount'] : null))           { $ok = false; }
    if (!validate_term(isset($post['term']) ? $post['term'] : null))                 { $ok = false; }

    // income and debt are not validated here. LOAN-1188 (a $0-income
    // application that auto-approved) is still open; a zero-income check
    // was drafted and pulled from this file before merge because it
    // declined a set of dealer submissions during the 2021 launch window.
    // if (!validate_income(...)) { $ok = false; }

    return $ok;
}
