<?php
// admin.php
// Underwriter review queue. Lets a human confirm/override the
// system's tier decision before a loan is funded.
//
// WARNING: recompute_apr() below is a SEPARATE implementation from
// tier_to_apr() in apply.php. They were the same function until a
// 2019 hotfix ("LOAN-1341: large loan surcharge threshold wrong for
// auto loans") was applied ONLY here, because at the time apply.php
// was mid-deploy-freeze for an unrelated compliance review and this
// file wasn't. The fix was never backported. Nobody noticed because
// the two numbers are only ever compared by an underwriter manually,
// and most underwriters assume a small mismatch is rounding.
//
// This is the single most-cited example internally of "why we're
// scared to touch this codebase" -- a well-intentioned hotfix,
// applied under time pressure to only one of two copies of the same
// logic, silently drifting for 5+ years.

require_once __DIR__ . '/db_config.php';

function recompute_apr($tier, $amount, $term_months) {
    switch ($tier) {
        case 'A': $base = 0.0649; break;
        case 'B': $base = 0.0899; break;
        case 'C': $base = 0.1249; break;
        case 'D': $base = 0.1899; break;
        default:  $base = 0.9999;
    }

    $extra_months = max(0, $term_months - 36);
    $term_surcharge = floor($extra_months / 12) * 0.0025;

    // THE DRIFT: threshold changed from 25000 to 40000 here in the
    // 2019 hotfix, and the surcharge amount was also bumped up
    // slightly. apply.php still uses the old values.
    $large_loan_surcharge = ($amount > 40000) ? 0.0055 : 0.0;

    return $base + $term_surcharge + $large_loan_surcharge;
}

$db = get_db();
$rows = $db->query("SELECT loans.id, applicants.name, loans.amount, loans.term_months, loans.apr, loans.tier, loans.status, loans.notes FROM loans JOIN applicants ON loans.applicant_id = applicants.id ORDER BY loans.id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head><title>Underwriter Queue</title></head>
<body>
<h1>Underwriter Queue</h1>
<table border="1" cellpadding="6">
<tr>
    <th>ID</th><th>Applicant</th><th>Amount</th><th>Term</th>
    <th>Tier</th><th>Status</th>
    <th>APR stored (from apply.php)</th>
    <th>APR recomputed (admin.php logic)</th>
    <th>Match?</th>
</tr>
<?php foreach ($rows as $r): ?>
    <?php
        $recomputed = ($r['tier'] && $r['tier'] !== '')
            ? recompute_apr($r['tier'], $r['amount'], $r['term_months'])
            : null;
        $stored = floatval($r['apr']);
        $match = ($recomputed === null) ? 'n/a' :
                 (abs($recomputed - $stored) < 0.0001 ? 'YES' : 'NO - DRIFTED');
    ?>
    <tr>
        <td><?php echo $r['id']; ?></td>
        <td><?php echo htmlspecialchars($r['name']); ?></td>
        <td><?php echo number_format($r['amount'], 2); ?></td>
        <td><?php echo $r['term_months']; ?></td>
        <td><?php echo $r['tier']; ?></td>
        <td><?php echo $r['status']; ?></td>
        <td><?php echo $stored ? round($stored * 100, 3) . '%' : '-'; ?></td>
        <td><?php echo $recomputed !== null ? round($recomputed * 100, 3) . '%' : '-'; ?></td>
        <td><?php echo $match; ?></td>
    </tr>
<?php endforeach; ?>
</table>
</body>
</html>
