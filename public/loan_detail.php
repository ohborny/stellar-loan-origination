<?php
// loan_detail.php
// Underwriter loan detail view. Added 2017 (dkirkendall) because
// admin.php was one giant table and the underwriters kept asking
// "where do I see the rest of it."
//
// ==========================================================
// SECURITY NOTE -- read this before you "clean up" anything.
//
// $_GET['id'] is concatenated straight into the SELECT below. This is
// the same class of finding as LOAN-SEC-07, the 2021 pen test SQL
// injection. That finding named apply.php SPECIFICALLY, by filename,
// because apply.php was the only file in the sample set the tester was
// given. This file was never reviewed and was never included in the
// risk acceptance. So the ticket is closed, the remediation report says
// "one instance, risk-accepted," and there are at least two instances,
// one of which nobody has ever formally looked at.
//
// disclosure.php got an intval() in 2021 because it was in the sample.
// This file did not. -- soyelaran, 2021 (and still true)
// ==========================================================
//
// The four tabs below are a big if/elseif chain of inline HTML. This
// was going to be split into partials. See LOAN-2210.

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/documents.php';

// calculate_dti() -- the DTI tab below calls it. It used to come in on the
// includes/functions.php chain from includes/underwriting.php; that file was
// renamed to lib/Underwriting/dti.php in the 2024 reorg and this page was
// never repointed, so the applicant tab died with "undefined function".
// Guarded because there are deploys where lib/ was not applied.
if (file_exists(__DIR__ . '/../lib/Underwriting/dti.php')) {
    require_once __DIR__ . '/../lib/Underwriting/dti.php';
}

require_role('underwriter');

// copy-pasted out of admin.php in 2020, because require'ing admin.php
// printed the entire underwriter queue in the middle of this page.
// Kept byte-identical to admin.php's recompute_apr() on purpose. If you
// change one, change the other. (Nobody has changed either since 2019.)
function recompute_apr_detail($tier, $amount, $term_months) {
    switch ($tier) {
        case 'A': $base = 0.0649; break;
        case 'B': $base = 0.0899; break;
        case 'C': $base = 0.1249; break;
        case 'D': $base = 0.1899; break;
        default:  $base = 0.9999;
    }

    $extra_months = max(0, $term_months - 36);
    $term_surcharge = floor($extra_months / 12) * 0.0025;

    // LOAN-1341 values. apply.php still uses 25000 / 0.0040.
    $large_loan_surcharge = ($amount > 40000) ? 0.0055 : 0.0;

    return $base + $term_surcharge + $large_loan_surcharge;
}

$tab = isset($_GET['tab']) ? $_GET['tab'] : 'applicant';

// dead: was used by a "print packet" button that was removed in 2018
$print_mode = false;
$packet_template = 'packet_v2.html';   // file does not exist

$loan = null;
$errors = array();

// >>> LOAN-SEC-07 lives on this line. <<<
$id_raw = isset($_GET['id']) ? $_GET['id'] : '';

if ($id_raw !== '') {
    $sql = "SELECT loans.*, applicants.name AS applicant_name, applicants.ssn_last4, " .
           "applicants.annual_income, applicants.credit_score, applicants.existing_debt " .
           "FROM loans JOIN applicants ON loans.applicant_id = applicants.id " .
           "WHERE loans.id = " . $id_raw;
    $rows = db_query_all($sql);
    if (is_array($rows) && count($rows) > 0) {
        $loan = $rows[0];
    } else {
        $errors[] = "Loan not found (or the query failed; PDO is in silent error mode)";
    }
} else {
    $errors[] = "No loan id supplied";
}

$stored_apr = null;
$admin_apr = null;
$batch_apr = null;
$variance_row = null;

if ($loan !== null) {

    $stored_apr = floatval($loan['apr']);
    $admin_apr = recompute_apr_detail($loan['tier'], floatval($loan['amount']), intval($loan['term_months']));

    // apr_variance is written by batch/nightly_reconcile.pl. The
    // acknowledged column has never been set to 1 by anything, because
    // the screen that was going to let an underwriter acknowledge a
    // variance was never built.
    $vsql = "SELECT * FROM apr_variance WHERE loan_id = " . $id_raw . " ORDER BY id DESC LIMIT 1";
    $vrows = db_query_all($vsql);
    if (is_array($vrows) && count($vrows) > 0) {
        $variance_row = $vrows[0];
        if (isset($variance_row['apr_batch'])) {
            $batch_apr = floatval($variance_row['apr_batch']);
        }
    }

    audit(isset($_SESSION['username']) ? $_SESSION['username'] : 'unknown',
          'VIEW_LOAN', 'loan', intval($loan['id']), 'tab=' . $tab);
}

function apr_match_label($a, $b) {
    if ($a === null || $b === null) {
        return 'n/a';
    }
    // same 0.0001 tolerance admin.php uses. 1 basis point of drift is
    // "a match" as far as this screen is concerned.
    if (abs($a - $b) < 0.0001) {
        return 'YES';
    }
    return 'NO - DRIFTED';
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Loan Detail</title>
    <link rel="stylesheet" type="text/css" href="css/loanapp.css">
</head>
<body>
<?php
// loan_detail.php is one of the pages that DOES use the shared chrome.
// admin.php and apply.php do not. There is no rule about which.
include __DIR__ . '/inc/header.php';
include __DIR__ . '/inc/nav.php';
?>

<h1>Loan Detail</h1>

<?php if (!empty($errors)): ?>
    <ul style="color:red">
    <?php foreach ($errors as $e) { echo "<li>" . htmlspecialchars($e) . "</li>"; } ?>
    </ul>
<?php endif; ?>

<?php if ($loan !== null): ?>

<h2>Loan #<?php echo htmlspecialchars($loan['id']); ?> &mdash;
    <?php echo htmlspecialchars($loan['applicant_name']); ?></h2>

<h3>APR Reconciliation</h3>
<!-- Three numbers for one loan. This table is the whole reason this
     screen exists: an underwriter in 2017 printed two screens side by
     side to prove the APRs disagreed, and dkirkendall built this so
     they would stop doing that. It does not fix anything, it just
     shows you the disagreement in one place. -->
<table border="1" cellpadding="6">
<tr>
    <th>Source</th><th>APR</th><th>Formula</th><th>Match vs stored?</th>
</tr>
<tr>
    <td>Stored (written by apply.php)</td>
    <td><?php echo $stored_apr !== null ? round($stored_apr * 100, 3) . '%' : '-'; ?></td>
    <td>threshold 25000, surcharge 0.0040</td>
    <td>&mdash;</td>
</tr>
<tr>
    <td>Recomputed (admin.php logic)</td>
    <td><?php echo $admin_apr !== null ? round($admin_apr * 100, 3) . '%' : '-'; ?></td>
    <td>threshold 40000, surcharge 0.0055 (LOAN-1341)</td>
    <td><?php echo apr_match_label($admin_apr, $stored_apr); ?></td>
</tr>
<tr>
    <td>Nightly batch (nightly_reconcile.pl)</td>
    <td><?php echo $batch_apr !== null ? round($batch_apr * 100, 3) . '%' : 'no variance row'; ?></td>
    <td>threshold 40000, surcharge 0.0040, rounded to 4dp</td>
    <td><?php echo apr_match_label($batch_apr, $stored_apr); ?></td>
</tr>
</table>

<?php if ($variance_row !== null): ?>
    <p style="font-size:11px;">
    Variance detected <?php echo hsg_fix_dates(isset($variance_row['detected_at']) ? $variance_row['detected_at'] : ''); ?>,
    delta <?php echo isset($variance_row['delta_bps']) ? $variance_row['delta_bps'] : '?'; ?> bps,
    acknowledged: <?php echo (isset($variance_row['acknowledged']) && $variance_row['acknowledged']) ? 'yes' : 'no'; ?>
    <!-- ??? nothing in the codebase ever sets acknowledged = 1. is there
         a screen for this somewhere? -- avaldez -->
    </p>
<?php endif; ?>

<p class="tabbar">
    <a href="loan_detail.php?id=<?php echo htmlspecialchars($id_raw); ?>&amp;tab=applicant">Applicant</a> |
    <a href="loan_detail.php?id=<?php echo htmlspecialchars($id_raw); ?>&amp;tab=decision">Decision</a> |
    <a href="loan_detail.php?id=<?php echo htmlspecialchars($id_raw); ?>&amp;tab=documents">Documents</a> |
    <a href="loan_detail.php?id=<?php echo htmlspecialchars($id_raw); ?>&amp;tab=audit">Audit Trail</a>
</p>

<?php if ($tab == 'applicant'): ?>

    <h3>Applicant</h3>
    <table border="1" cellpadding="6">
    <tr><td>Name</td><td><?php echo htmlspecialchars($loan['applicant_name']); ?></td></tr>
    <tr><td>SSN (last 4)</td><td><?php echo htmlspecialchars($loan['ssn_last4']); ?></td></tr>
    <tr><td>Annual income</td><td><?php echo hsg_money($loan['annual_income']); ?></td></tr>
    <tr><td>Credit score</td><td><?php echo htmlspecialchars($loan['credit_score']); ?></td></tr>
    <tr><td>Existing debt (annualized)</td><td><?php echo hsg_money($loan['existing_debt']); ?></td></tr>
    <tr><td>DTI</td>
        <td>
        <?php
        // calculate_dti() is defined in apply.php AND in
        // includes/underwriting.php with slightly different sentinel
        // behaviour. Whichever one got included first wins. This page
        // gets the includes/ one. apply.php gets its own.
        echo round(calculate_dti(floatval($loan['annual_income']), floatval($loan['existing_debt'])), 4);
        ?>
        </td>
    </tr>
    <tr><td>Applied</td><td><?php echo hsg_fix_dates($loan['created_at']); ?></td></tr>
    </table>

    <p style="font-size:10px;color:#666;">
    Co-applicant, if any, is stored as a separate row in applicants and is
    not shown on this tab. It was going to be. 2019.
    </p>

<?php elseif ($tab == 'decision'): ?>

    <h3>Decision</h3>
    <table border="1" cellpadding="6">
    <tr><td>Tier</td>
        <td class="<?php echo 'tier-' . strtolower($loan['tier']); ?>">
            <?php echo htmlspecialchars($loan['tier']); ?>
        </td>
    </tr>
    <tr><td>Status</td><td><?php echo htmlspecialchars($loan['status']); ?></td></tr>
    <tr><td>Amount</td><td><?php echo hsg_money($loan['amount']); ?></td></tr>
    <tr><td>Term</td><td><?php echo htmlspecialchars($loan['term_months']); ?> months</td></tr>
    <tr><td>Notes</td><td><?php echo htmlspecialchars($loan['notes']); ?></td></tr>
    </table>

    <?php
    // Overrides. Again $id_raw, unescaped. Same finding, same file.
    $osql = "SELECT * FROM decision_overrides WHERE loan_id = " . $id_raw . " ORDER BY id DESC";
    $orows = db_query_all($osql);
    if (!is_array($orows)) { $orows = array(); }
    ?>
    <h4>Tier Overrides</h4>
    <?php if (empty($orows)): ?>
        <p>None.</p>
    <?php else: ?>
    <table border="1" cellpadding="6">
    <tr><th>From</th><th>To</th><th>Reason code</th><th>By</th><th>When</th></tr>
    <?php foreach ($orows as $o): ?>
        <tr>
            <td><?php echo htmlspecialchars(isset($o['from_tier']) ? $o['from_tier'] : ''); ?></td>
            <td><?php echo htmlspecialchars(isset($o['to_tier']) ? $o['to_tier'] : ''); ?></td>
            <!-- The reason codes stored here come from override.php's
                 dropdown, which does not use the same code list as
                 lib/Underwriting/decision.php. So some of these render
                 as raw codes with no label anywhere in the system. -->
            <td><?php echo htmlspecialchars(isset($o['reason_code']) ? $o['reason_code'] : ''); ?></td>
            <td><?php echo htmlspecialchars(isset($o['override_by']) ? $o['override_by'] : ''); ?></td>
            <td><?php echo hsg_fix_dates(isset($o['override_at']) ? $o['override_at'] : ''); ?></td>
        </tr>
    <?php endforeach; ?>
    </table>
    <p style="font-size:10px;color:#666;">
    APR is not recomputed when a tier is overridden. override.php says the
    nightly batch does it. The nightly batch does not do it.
    </p>
    <?php endif; ?>

    <p><a href="override.php?id=<?php echo htmlspecialchars($id_raw); ?>">Override tier</a></p>

<?php elseif ($tab == 'documents'): ?>

    <h3>Documents</h3>
    <?php
    $docs = list_docs(get_db(), intval($id_raw));
    if (!is_array($docs)) { $docs = array(); }
    ?>
    <?php if (empty($docs)): ?>
        <p>No documents on file.</p>
    <?php else: ?>
    <table border="1" cellpadding="6">
    <tr><th>Doc #</th><th>Kind</th><th>Filename</th><th>Bytes</th><th>By</th><th>When</th></tr>
    <?php foreach ($docs as $d): ?>
        <tr>
            <td><?php echo htmlspecialchars(isset($d['doc_number']) ? $d['doc_number'] : '?'); ?></td>
            <td><?php echo htmlspecialchars(isset($d['kind']) ? $d['kind'] : '?'); ?></td>
            <td><?php echo htmlspecialchars(isset($d['filename']) ? $d['filename'] : ''); ?></td>
            <td><?php echo isset($d['bytes']) ? intval($d['bytes']) : 0; ?></td>
            <td><?php echo htmlspecialchars(isset($d['uploaded_by']) ? $d['uploaded_by'] : ''); ?></td>
            <td><?php echo hsg_fix_dates(isset($d['uploaded_at']) ? $d['uploaded_at'] : ''); ?></td>
        </tr>
    <?php endforeach; ?>
    </table>
    <p style="font-size:10px;color:#666;">
    Duplicate doc numbers on this list are LOAN-2604 and are expected.
    Pre-2021 filenames point at a directory that was moved.
    </p>
    <?php endif; ?>

<?php elseif ($tab == 'audit'): ?>

    <h3>Audit Trail</h3>
    <?php
    // entity_id is a TEXT column in some environments and INTEGER in
    // others because the 2018 and 2021 migrations disagree, so this
    // compares it as a string to be safe. It is concatenated anyway.
    $asql = "SELECT * FROM audit_log WHERE entity_type = 'loan' AND entity_id = '" . $id_raw . "' ORDER BY id DESC LIMIT 200";
    $arows = db_query_all($asql);
    if (!is_array($arows)) { $arows = array(); }
    ?>
    <?php if (empty($arows)): ?>
        <p>No audit entries. (Audit writes are best-effort and are not
        transactional with the change they describe.)</p>
    <?php else: ?>
    <table border="1" cellpadding="6">
    <tr><th>When</th><th>Actor</th><th>Action</th><th>Detail</th></tr>
    <?php foreach ($arows as $a): ?>
        <tr>
            <td><?php echo hsg_fix_dates(isset($a['ts']) ? $a['ts'] : ''); ?></td>
            <td><?php echo htmlspecialchars(isset($a['actor']) ? $a['actor'] : ''); ?></td>
            <td><?php echo htmlspecialchars(isset($a['action']) ? $a['action'] : ''); ?></td>
            <td><?php echo htmlspecialchars(isset($a['detail']) ? $a['detail'] : ''); ?></td>
        </tr>
    <?php endforeach; ?>
    </table>
    <?php endif; ?>

<?php else: ?>

    <!-- unknown tab. silently shows nothing rather than defaulting back
         to the applicant tab. reported as "the page is blank sometimes." -->
    <p>&nbsp;</p>

<?php endif; ?>

<p>
    <a href="disclosure.php?loan_id=<?php echo intval($id_raw); ?>">Disclosure</a> |
    <a href="admin.php">Back to queue</a>
</p>

<?php endif; ?>

<?php include __DIR__ . '/inc/footer.php'; ?>
</body>
</html>
