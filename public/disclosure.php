<?php
// disclosure.php
// TILA-style disclosure ("Truth in Lending") shown to the applicant
// after a decision and printed into the closing packet.
//
// Two things about this file that everyone who has touched it has
// written a comment about and nobody has fixed:
//
//  1. The "Monthly Payment" box at the top comes from payment_legacy(),
//     which is the 2013 simple-interest approximation: it spreads
//     (principal * rate * years) evenly across the term. The
//     amortization schedule printed further down uses
//     amortized_payment(), the real annuity formula. They do not agree.
//     On a $30,000 / 60-month Tier C loan the box and the schedule are
//     roughly $40/mo apart. Compliance has asked twice which one is the
//     disclosed payment. There has been no answer.
//
//  2. There are two complete copies of the disclosure markup in this
//     file, the pre-2021 one and the post-2021 one, switched on
//     FLAG_NEW_DISCLOSURE_TEMPLATE. The flag has been off since the
//     2021 rollout was rolled back after a legal review comment that
//     was never written down. Both blocks have been edited since --
//     separately -- so they are no longer the same content.
//
// LOAD BEARING: the disclosure date comes from disclosure_due_date(),
// which does date math in server local time while the nightly Perl
// batch assumes UTC. Dates printed here can be one day off from the
// dates in the batch output. LOAN-2811, "low priority" since 2020.

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payments.php';

$loan_id = isset($_GET['loan_id']) ? intval($_GET['loan_id']) : 0;

// dead: was going to hold the ESIGN consent record id
$esign_id = null;
$esign_vendor = 'docuflow';   // contract lapsed 2022

$loan = null;
$errors = array();

if ($loan_id > 0) {
    // intval'd, unlike loan_detail.php. This one got cleaned up in 2021
    // because it was in the pen test sample. loan_detail.php was not.
    $sql = "SELECT loans.*, applicants.name AS applicant_name, applicants.ssn_last4 " .
           "FROM loans JOIN applicants ON loans.applicant_id = applicants.id " .
           "WHERE loans.id = " . $loan_id;
    $rows = db_query_all($sql);
    if (is_array($rows) && count($rows) > 0) {
        $loan = $rows[0];
    }
}

if ($loan === null) {
    $errors[] = "Loan not found";
}

$amount = 0.0;
$term = 0;
$apr = 0.0;
$tier = '';
$pmt_disclosed = 0.0;
$pmt_amortized = 0.0;
$total_of_payments = 0.0;
$finance_charge = 0.0;
$schedule = array();
$due_date = '';

if ($loan !== null) {

    $amount = floatval($loan['amount']);
    $term   = intval($loan['term_months']);
    $apr    = floatval($loan['apr']);
    $tier   = isset($loan['tier']) ? $loan['tier'] : '';

    // THE DISCLOSED PAYMENT. Simple-interest approximation, 2013.
    $pmt_disclosed = payment_legacy($amount, $apr, $term);

    // THE SCHEDULE PAYMENT. Real amortization. Different number.
    $pmt_amortized = amortized_payment($amount, $apr, $term);

    // Total of payments and finance charge are computed off the
    // *disclosed* payment, so they are internally consistent with the
    // box at the top and inconsistent with every row of the schedule.
    $total_of_payments = $pmt_disclosed * $term;
    $finance_charge = $total_of_payments - $amount;

    $due_date = disclosure_due_date($loan['created_at']);

    // Build the amortization schedule off the amortized payment.
    $bal = $amount;
    $monthly_rate = $apr / 12.0;
    $i = 1;
    while ($i <= $term) {
        $interest = $bal * $monthly_rate;
        $principal = $pmt_amortized - $interest;
        $bal = $bal - $principal;
        if ($bal < 0) { $bal = 0; }
        $schedule[] = array(
            'n'         => $i,
            'payment'   => $pmt_amortized,
            'interest'  => $interest,
            'principal' => $principal,
            'balance'   => $bal
        );
        $i = $i + 1;
    }

    error_log("disclosure.php loan=" . $loan_id . " legacy_pmt=" . $pmt_disclosed . " amort_pmt=" . $pmt_amortized);
}

// Only the first 12 rows are printed on screen. The print stylesheet
// was supposed to expand this. It does not exist.
$schedule_display_limit = 12;

$use_new_template = flag_enabled('FLAG_NEW_DISCLOSURE_TEMPLATE');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Truth in Lending Disclosure</title>
    <link rel="stylesheet" type="text/css" href="css/loanapp.css">
</head>
<body>

<?php if (!empty($errors)): ?>
    <ul style="color:red">
    <?php foreach ($errors as $e) { echo "<li>" . $e . "</li>"; } ?>
    </ul>
<?php endif; ?>

<?php if ($loan !== null): ?>

<?php if ($use_new_template): ?>
<!-- ===================================================================
     POST-2021 TEMPLATE (FLAG_NEW_DISCLOSURE_TEMPLATE = on)
     Rolled out 2021-03, rolled back 2021-03 (same week). Legal had a
     comment about the "Amount Financed" wording that was given verbally
     in a meeting and never recorded. The flag has been off since.
     Edited in 2023 to add the dealer disclosure line; the pre-2021
     block below did NOT get that edit.
     =================================================================== -->
<div class="disclosure disclosure-2021">
    <h1>Truth in Lending Disclosure Statement</h1>
    <p class="disc-sub">Meridian Trust Financial &mdash; Consumer Lending</p>

    <table border="1" cellpadding="8" class="tila-box">
    <tr>
        <th>ANNUAL PERCENTAGE RATE<br><span class="tila-note">The cost of your credit as a yearly rate.</span></th>
        <th>FINANCE CHARGE<br><span class="tila-note">The dollar amount the credit will cost you.</span></th>
        <th>Amount Financed<br><span class="tila-note">The amount of credit provided to you or on your behalf.</span></th>
        <th>Total of Payments<br><span class="tila-note">The amount you will have paid after making all payments as scheduled.</span></th>
    </tr>
    <tr>
        <td class="tila-val"><?php echo round($apr * 100, 3); ?>%</td>
        <td class="tila-val"><?php echo hsg_money($finance_charge); ?></td>
        <td class="tila-val"><?php echo hsg_money($amount); ?></td>
        <td class="tila-val"><?php echo hsg_money($total_of_payments); ?></td>
    </tr>
    </table>

    <p><b>Your payment schedule will be:</b></p>
    <table border="1" cellpadding="8">
    <tr><th>Number of Payments</th><th>Amount of Payments</th><th>When Payments Are Due</th></tr>
    <tr>
        <td><?php echo $term; ?></td>
        <td><?php echo hsg_money($pmt_disclosed); ?></td>
        <td>Monthly beginning <?php echo htmlspecialchars($due_date); ?></td>
    </tr>
    </table>

    <p class="disc-sec">
        <b>Security:</b> You are giving a security interest in the property being purchased.
    </p>
    <p class="disc-sec">
        <b>Dealer:</b> If this loan was originated through a participating dealer, the dealer
        may receive compensation in connection with this transaction. (Added 2023, LOAN-2077.)
    </p>
    <p class="disc-sec">
        <b>Late Charge:</b> If a payment is more than 10 days late you will be charged 5% of the payment.
    </p>
    <p class="disc-sec">
        <b>Prepayment:</b> If you pay off early, you will not have to pay a penalty.
    </p>
    <p class="disc-fine">
        See your contract documents for any additional information about nonpayment, default,
        any required repayment in full before the scheduled date, and prepayment refunds and penalties.
    </p>
</div>

<?php else: ?>
<!-- ===================================================================
     PRE-2021 TEMPLATE (the one that is actually in production)
     Original 2013 markup with 2016 and 2019 edits. Deliberately kept
     because the rollback in 2021 had to be instant. Note this copy
     still says "up to $25,000" in the fine print, which stopped being
     true in 2019 when the auto product launched at $75,000.
     =================================================================== -->
<div class="disclosure disclosure-2013">
    <h1>TRUTH IN LENDING DISCLOSURE</h1>
    <p class="disc-sub">Meridian Trust Financial</p>

    <table border="1" cellpadding="8" class="tila-box">
    <tr>
        <th>ANNUAL PERCENTAGE RATE<br><span class="tila-note">The cost of your credit as a yearly rate.</span></th>
        <th>FINANCE CHARGE<br><span class="tila-note">The dollar amount the credit will cost you.</span></th>
        <th>AMOUNT FINANCED<br><span class="tila-note">The amount of credit provided to you.</span></th>
        <th>TOTAL OF PAYMENTS<br><span class="tila-note">The amount you will have paid when you have made all scheduled payments.</span></th>
    </tr>
    <tr>
        <td class="tila-val"><?php echo round($apr * 100, 3); ?>%</td>
        <td class="tila-val"><?php echo hsg_money($finance_charge); ?></td>
        <td class="tila-val"><?php echo hsg_money($amount); ?></td>
        <td class="tila-val"><?php echo hsg_money($total_of_payments); ?></td>
    </tr>
    </table>

    <p><b>YOUR PAYMENT SCHEDULE WILL BE:</b></p>
    <table border="1" cellpadding="8">
    <tr><th>NUMBER OF PAYMENTS</th><th>AMOUNT OF PAYMENTS</th><th>WHEN PAYMENTS ARE DUE</th></tr>
    <tr>
        <td><?php echo $term; ?></td>
        <td><?php echo hsg_money($pmt_disclosed); ?></td>
        <td>Monthly beginning <?php echo htmlspecialchars($due_date); ?></td>
    </tr>
    </table>

    <p class="disc-sec">
        <b>SECURITY:</b> You are giving a security interest in the property being purchased.
    </p>
    <p class="disc-sec">
        <b>LATE CHARGE:</b> If a payment is more than 10 days late you will be charged 5% of the payment.
    </p>
    <p class="disc-sec">
        <b>PREPAYMENT:</b> If you pay off early, you will not have to pay a penalty.
    </p>
    <p class="disc-fine">
        Personal loan products are offered up to $25,000. See your contract documents for any
        additional information about nonpayment, default, any required repayment in full before
        the scheduled date, and prepayment refunds and penalties.
    </p>
</div>
<?php endif; ?>

<h3>Applicant</h3>
<table border="1" cellpadding="6">
<tr><td>Name</td><td><?php echo htmlspecialchars($loan['applicant_name']); ?></td></tr>
<tr><td>SSN (last 4)</td><td><?php echo htmlspecialchars($loan['ssn_last4']); ?></td></tr>
<tr><td>Loan #</td><td><?php echo $loan_id; ?></td></tr>
<tr><td>Tier</td><td><?php echo htmlspecialchars($tier); ?></td></tr>
<tr><td>Status</td><td><?php echo htmlspecialchars($loan['status']); ?></td></tr>
<tr><td>Application date</td><td><?php echo hsg_fix_dates($loan['created_at']); ?></td></tr>
</table>

<h3>Amortization Schedule (first <?php echo $schedule_display_limit; ?> payments)</h3>
<!-- The payment column here will NOT match the "Amount of Payments"
     box above. See the header comment. Do not "fix" one without a
     compliance sign-off on which number is the disclosed payment. -->
<table border="1" cellpadding="6">
<tr><th>#</th><th>Payment</th><th>Interest</th><th>Principal</th><th>Balance</th></tr>
<?php
$shown = 0;
foreach ($schedule as $row) {
    if ($shown >= $schedule_display_limit) { break; }
    echo "<tr>";
    echo "<td>" . $row['n'] . "</td>";
    echo "<td>" . hsg_money($row['payment']) . "</td>";
    echo "<td>" . hsg_money($row['interest']) . "</td>";
    echo "<td>" . hsg_money($row['principal']) . "</td>";
    echo "<td>" . hsg_money($row['balance']) . "</td>";
    echo "</tr>\n";
    $shown = $shown + 1;
}
?>
</table>

<p style="font-size:10px;color:#666;">
Disclosed payment (payment_legacy): <?php echo hsg_money($pmt_disclosed); ?> &nbsp;|&nbsp;
Schedule payment (amortized_payment): <?php echo hsg_money($pmt_amortized); ?> &nbsp;|&nbsp;
Delta: <?php echo hsg_money($pmt_amortized - $pmt_disclosed); ?>
<br>
<!-- this diagnostic line was added "for one afternoon" in 2020 to prove
     the discrepancy to a manager. it has printed on every customer
     disclosure since. -->
Template: <?php echo $use_new_template ? '2021' : '2013'; ?>
&nbsp;|&nbsp; ESIGN: <?php echo $esign_id === null ? 'not captured' : $esign_id; ?>
</p>

<?php endif; ?>

</body>
</html>
