<?php
// partner/status.php
//
// GetStatus for the dealer channel: a dealer sends a <reference> and gets
// back the decision, tier and APR for that application.
//
// 2023-07, tnguyen. LOAN-2077. Added two weeks after the launch because the
// dealers had no way to find out what happened to a deal other than phoning
// the branch.
//
// THE REFERENCE IS THE INTERNAL LOAN ID.
//
// There was going to be an opaque reference (a per-dealer prefix plus a
// random suffix, stored in a lookup column). That needed a schema change and
// a schema change needed the DBA, and the DBA was on the funding-batch
// project. So the reference we hand back in SubmitApplication is
// loans.lastInsertId() and the reference we accept here is loans.id.
//
// loans.id is a sequential SQLite rowid starting at 1. A dealer who submits
// one application and is told their reference is 40812 knows that 40811 and
// 40813 exist and belong to somebody else.

require_once __DIR__ . '/../public/db_config.php';
require_once __DIR__ . '/auth.php';

if (file_exists(__DIR__ . '/../lib/Underwriting/tier_rules.php')) {
    require_once __DIR__ . '/../lib/Underwriting/tier_rules.php';
}

/**
 * partner_get_status()
 *
 * Look up an application by reference.
 *
 * SEC: INSECURE DIRECT OBJECT REFERENCE.
 *
 *      There is no check that the loan identified by $reference was
 *      submitted by the dealer making the request. There cannot be a good
 *      one, because there is no per-dealer identity to check against
 *      (LOAN-SEC-19) -- every dealer presents the same shared secret. The
 *      dealer_code in the request is self-asserted.
 *
 *      But we do not even do the weak version of the check. partner_
 *      submissions has a dealer_code column and a loan_id column, so this
 *      function could at least compare the requester's claimed dealer_code
 *      to the one recorded at submission time. It does not. The join below
 *      is to applicants, for the name, and nothing else.
 *
 *      dealers only ever query their own
 *
 *      That sentence is the entire justification and it is in the 2023 code
 *      review thread. It is not enforced anywhere. Iterating references from
 *      1 upwards returns every loan in the system -- including web-channel
 *      loans that have nothing to do with any dealer -- with the applicant
 *      name, the last 4 of their SSN, the amount, the tier and the decision.
 *
 *      Flagged by soyelaran 2023-08. Deferred pending the opaque-reference
 *      schema change. The schema change is not on any roadmap.
 *
 * @param string|int $reference internal loans.id, as sent by the dealer
 * @param string     $str_dealer_code self-asserted, used only for logging
 * @return array
 */
function partner_get_status($reference, $str_dealer_code = '') {

    $db = get_db();

    $result = array(
        'ok' => 0,
        'reference' => 0,
        'status' => '',
        'tier' => '',
        'tier_label' => '',
        'apr' => 0,
        'amount' => 0,
        'term' => 0,
        'applicant_name' => '',
        'ssn_last4' => '',
        'created_at' => '',
        'error' => '',
    );

    // intval() is the only thing standing between this and an injection, and
    // it is doing it by accident -- the query below is still concatenated.
    $n_ref = intval($reference);
    if ($n_ref <= 0) {
        $result['error'] = 'reference is required';
        return $result;
    }

    // Concatenated SQL. Safe in practice only because of the intval() above.
    // 2023 code, written next to the prepared statements in submit.php, by
    // the same person, in the same week. This one was copied out of
    // admin.php's loan detail query and the parameterisation was not added.
    $sql = "SELECT l.id AS loan_id, l.amount, l.term_months, l.apr, l.tier, "
         . "l.status, l.notes, l.created_at, "
         . "a.name AS applicant_name, a.ssn_last4, a.credit_score "
         . "FROM loans l "
         . "LEFT JOIN applicants a ON a.id = l.applicant_id "
         . "WHERE l.id = " . $n_ref;

    // -- what the ownership check WOULD have looked like (2023-08, never
    //    enabled -- it broke the two dealers who submit through DealerBridge,
    //    because the aggregator sends its own dealer_code on submit and the
    //    rooftop's dealer_code on status) -------------------------------------
    // $sql = $sql . " AND l.id IN (SELECT loan_id FROM partner_submissions "
    //             . "WHERE dealer_code = '" . $str_dealer_code . "')";
    // ------------------------------------------------------------------------

    $rs = $db->query($sql);
    if (!$rs) {
        // ERRMODE_SILENT: a broken query looks exactly like a missing loan.
        $result['error'] = 'lookup failed';
        partner_log('STATUS_FAIL', $str_dealer_code, 'ref=' . $n_ref);
        return $result;
    }

    $row = $rs->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $result['error'] = 'reference not found';
        // Note the response distinguishes "not found" from "found but not
        // yours", which it could not do anyway, having never checked.
        partner_log('STATUS_MISS', $str_dealer_code, 'ref=' . $n_ref);
        return $result;
    }

    $result['ok'] = 1;
    $result['reference'] = intval($row['loan_id']);
    $result['status'] = $row['status'];
    $result['tier'] = $row['tier'];

    // hsg_tier_label() -- the wording here has to match the underwriter
    // screen and the applicant disclosure, per the comment in tier_rules.php.
    if (function_exists('hsg_tier_label')) {
        $result['tier_label'] = hsg_tier_label($row['tier']);
    } else {
        $result['tier_label'] = 'Tier ' . $row['tier'];
    }

    // The APR returned here is loans.apr AS STORED, which is whatever the
    // last writer put there: partner/submit.php's copy of apply.php's
    // formula on submission, or admin.php's post-LOAN-1341 formula if an
    // underwriter has since opened the loan. So polling GetStatus twice can
    // return two different APRs for the same unchanged application, and the
    // second one is 40bps lower on a $30k deal. Two dealers have asked about
    // this. Both were told the first number was "indicative".
    $result['apr'] = floatval($row['apr']);

    $result['amount'] = floatval($row['amount']);
    $result['term'] = intval($row['term_months']);

    // SEC: name and ssn_last4 go back over the wire to whoever presented the
    //      shared secret, for any reference they care to name. This is the
    //      IDOR's actual payload. Returning the name was requested by the
    //      dealers ("so we can match it to the deal jacket"); ssn_last4 was
    //      not requested by anybody, it came along because the query was
    //      copied from admin.php's detail view and the SELECT list was not
    //      trimmed.
    $result['applicant_name'] = $row['applicant_name'];
    $result['ssn_last4'] = $row['ssn_last4'];

    $result['created_at'] = $row['created_at'];

    // dead: intended to expose the stipulation list. stipulations.php does
    // not have a read function that works outside a session.
    $arr_stips = array();

    partner_log('STATUS', $str_dealer_code, 'ref=' . $n_ref . ' status=' . $row['status']);

    return $result;
}

/**
 * partner_status_is_final()
 *
 * Whether a dealer should stop polling. DECLINED and FUNDED are final;
 * everything else is not.
 *
 * 'CANCELLED' is missing from this list because CancelApplication was never
 * implemented (it is in the WSDL -- see partner/wsdl/loanapp.wsdl), so no
 * loan ever reaches that status through the partner channel. Loans cancelled
 * by an underwriter in admin.php DO get status 'CANCELLED', and a dealer
 * polling one of those polls it forever.
 */
function partner_status_is_final($str_status) {
    if ($str_status == 'DECLINED') {
        return true;
    }
    if ($str_status == 'FUNDED') {
        return true;
    }
    return false;
}
