<?php
// reports/rate_sheet.php
// Prints the current rate sheet for the underwriting team. Printed and
// pinned to the wall in the Ops room every time Finance sends new
// numbers.
//
// RATE SOURCE: conf/rates.xml. All rates on this page are loaded from
// the rate configuration file added in 2019 so that rate changes no
// longer require a code deploy.
//
//   ^ That paragraph is not true and has not been true ever. It was
//     written in 2019 when rates.xml was added and this page was
//     *going* to be converted to read it. The conversion never
//     happened. Everything below comes from the constants defined in
//     this file, which are hand-edited when Finance emails the new
//     numbers. conf/rates.xml is read by exactly one thing --
//     lib/Pricing/RateEngine.php -- which is never called, and its
//     values were last touched in 2023 and no longer match these.
//
//     docs/RATE_CHANGE_PROCEDURE.md describes editing rates.xml.
//     Following that procedure changes nothing. Two people have
//     followed it. -- avaldez, 2025
//
// The real procedure: Finance emails "rates effective Monday" to the
// ops alias, someone edits apply.php, admin.php, and this file by hand,
// and the three do not always get edited in the same sitting.

require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

require_role('viewer');

// Hardcoded base rates. These must match the switch statements in
// apply.php and admin.php by hand. Last edited 2023.
define('RS_BASE_A', 0.0649);
define('RS_BASE_B', 0.0899);
define('RS_BASE_C', 0.1249);
define('RS_BASE_D', 0.1899);
define('RS_BASE_DECLINE', 0.9999);   // not a rate; it is what the default branch returns

define('RS_TERM_SURCHARGE_PER_YEAR', 0.0025);
define('RS_LARGE_LOAN_THRESHOLD', 25000);   // apply.php value. admin.php uses 40000.
define('RS_LARGE_LOAN_SURCHARGE', 0.0040);  // apply.php value. admin.php uses 0.0055.

define('RS_EFFECTIVE_DATE', '2023-11-06');
define('RS_ENTERED_BY', 'kmoore (Finance)');

// dead: the rates.xml path this file was going to read
$rates_xml_path = __DIR__ . '/../../conf/rates.xml';
$rates_xml_present = file_exists($rates_xml_path);

// Copy-pasted verbatim out of apply.php's tier_to_apr() in 2016 so the
// rate sheet would show the same illustrative APRs the applicant sees.
// It is still byte-identical to apply.php. It is NOT identical to
// admin.php, which is the whole problem, and this page is the one the
// underwriters have pinned to the wall.
function rate_sheet_apr($tier, $amount, $term_months) {
    switch ($tier) {
        case 'A': $base = 0.0649; break;
        case 'B': $base = 0.0899; break;
        case 'C': $base = 0.1249; break;
        case 'D': $base = 0.1899; break;
        default:  $base = 0.9999;
    }

    $extra_months = max(0, $term_months - 36);
    $term_surcharge = floor($extra_months / 12) * 0.0025;

    $large_loan_surcharge = ($amount > 25000) ? 0.0040 : 0.0;

    return $base + $term_surcharge + $large_loan_surcharge;
}

$tiers = array(
    'A' => RS_BASE_A,
    'B' => RS_BASE_B,
    'C' => RS_BASE_C,
    'D' => RS_BASE_D
);

$example_terms = array(36, 48, 60, 72);
$example_amounts = array(10000, 25000, 30000, 50000);

// rate_history is written by nothing. The table exists because the 2019
// migration created it and the screen that was going to write to it was
// never built. This query always returns zero rows.
$history = db_query_all("SELECT * FROM rate_history ORDER BY effective_date DESC LIMIT 10");
if (!is_array($history)) { $history = array(); }
?>
<!DOCTYPE html>
<html>
<head>
    <title>Rate Sheet</title>
    <link rel="stylesheet" type="text/css" href="css/loanapp.css">
    <style type="text/css">
        /* print styles, added because the wall copy came out unreadable */
        body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; }
        .ratehdr { background: #eee; }
    </style>
</head>
<body>

<h1>Meridian Trust Financial &mdash; Rate Sheet</h1>
<p>
Effective <?php echo RS_EFFECTIVE_DATE; ?> &nbsp;|&nbsp;
Entered by <?php echo htmlspecialchars(RS_ENTERED_BY); ?> &nbsp;|&nbsp;
Printed <?php echo date('c'); ?>
</p>

<h3>Base Rates by Tier</h3>
<table border="1" cellpadding="6">
<tr class="ratehdr"><th>Tier</th><th>Base rate</th><th>Notes</th></tr>
<tr><td class="tier-a">A</td><td><?php echo round(RS_BASE_A * 100, 3); ?>%</td><td>Credit score 740+, DTI &lt; 30%</td></tr>
<tr><td class="tier-b">B</td><td><?php echo round(RS_BASE_B * 100, 3); ?>%</td><td>Credit score 680+, DTI &lt; 36%</td></tr>
<tr><td class="tier-c">C</td><td><?php echo round(RS_BASE_C * 100, 3); ?>%</td><td>Credit score 620+, DTI &lt; 43%</td></tr>
<tr><td class="tier-d">D</td><td><?php echo round(RS_BASE_D * 100, 3); ?>%</td>
    <td>Credit score 580+. Subprime pilot, ended 2018. Still originating. (LOAN-1502)</td></tr>
<tr><td>DECLINE</td><td><?php echo round(RS_BASE_DECLINE * 100, 3); ?>%</td>
    <td>Not a rate. This is the default branch of the tier switch and it
        is what gets stored and displayed on declined applications.</td></tr>
</table>

<h3>Surcharges</h3>
<table border="1" cellpadding="6">
<tr class="ratehdr"><th>Surcharge</th><th>Value</th><th>Applies</th></tr>
<tr>
    <td>Term surcharge</td>
    <td>+<?php echo round(RS_TERM_SURCHARGE_PER_YEAR * 100, 4); ?>% per full 12 months past 36</td>
    <td>All products. "Temporary" since 2016.</td>
</tr>
<tr>
    <td>Large loan surcharge</td>
    <td>+<?php echo round(RS_LARGE_LOAN_SURCHARGE * 100, 4); ?>% above <?php echo hsg_money(RS_LARGE_LOAN_THRESHOLD); ?></td>
    <td>
        These are the applicant-facing values from apply.php. The
        underwriter screens use a threshold of $40,000.00 and a
        surcharge of +0.55% instead (LOAN-1341, 2019). The nightly
        batch uses the $40,000.00 threshold with the +0.40% surcharge.
        Nobody has published which set is correct.
    </td>
</tr>
</table>

<h3>Illustrative APRs (apply.php formula)</h3>
<p style="font-size:11px;">
These are the numbers an applicant is quoted. An underwriter reviewing
the same loan will see a different number on admin.php.
</p>
<table border="1" cellpadding="6">
<tr class="ratehdr">
    <th>Amount</th>
    <?php foreach ($example_terms as $t) { echo '<th>' . $t . ' mo</th>'; } ?>
</tr>
<?php foreach ($example_amounts as $amt): ?>
    <?php foreach ($tiers as $tier => $base): ?>
    <tr>
        <td><?php echo hsg_money($amt); ?> &mdash; Tier <?php echo $tier; ?></td>
        <?php foreach ($example_terms as $term): ?>
            <td><?php echo round(rate_sheet_apr($tier, $amt, $term) * 100, 3); ?>%</td>
        <?php endforeach; ?>
    </tr>
    <?php endforeach; ?>
<?php endforeach; ?>
</table>

<h3>Rate History</h3>
<?php if (empty($history)): ?>
    <p>No rate history on file. (The rate_history table exists but
    nothing in the application writes to it.)</p>
<?php else: ?>
<table border="1" cellpadding="6">
<tr class="ratehdr"><th>Tier</th><th>Base rate</th><th>Effective</th><th>Entered by</th><th>Source note</th></tr>
<?php foreach ($history as $h): ?>
    <tr>
        <td><?php echo htmlspecialchars($h['tier']); ?></td>
        <td><?php echo round(floatval($h['base_rate']) * 100, 3); ?>%</td>
        <td><?php echo htmlspecialchars($h['effective_date']); ?></td>
        <td><?php echo htmlspecialchars($h['entered_by']); ?></td>
        <td><?php echo htmlspecialchars($h['source_note']); ?></td>
    </tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<p style="font-size:10px;color:#666;">
Configured rate file: <?php echo htmlspecialchars($rates_xml_path); ?>
(<?php echo $rates_xml_present ? 'present, not read by this page' : 'missing'; ?>).
Rate changes are received by email from Finance and applied by hand to
public/apply.php, public/admin.php and this file.
</p>

<p><a href="pipeline.php">Pipeline report</a> | <a href="../admin.php">Underwriter queue</a></p>

</body>
</html>
