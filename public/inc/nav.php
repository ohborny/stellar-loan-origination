<?php
// inc/nav.php
// Navigation strip. Split out of inc/header.php in 2018 so the partner
// portal could include the header without the internal links. The
// partner portal does not include either file.
//
// Three of the links below point at pages that were deleted years ago.
// Rather than removing the links, someone wrapped each one in a
// file_exists() check, so they silently disappear from the nav instead
// of 404ing. That means the nav is a different shape on different
// hosts depending on what has been cleaned up where, and there is at
// least one host where queue_summary.php still exists and still works
// and nobody knows what it queries.
//
// SEC: this nav hides links based on $_SESSION['role'], which is
//      cosmetic only -- none of the linked pages except loan_detail.php
//      and override.php actually call require_role(). Typing the URL
//      works. Noted 2021, not remediated.

$nav_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';

// dead: a "recently viewed" list that was never populated
$recent_loans = array();

$nav_items = array();

// always shown
$nav_items[] = array('label' => 'Apply',            'href' => 'apply.php',            'roles' => '*');
$nav_items[] = array('label' => 'Underwriter Queue','href' => 'admin.php',            'roles' => 'underwriter,admin');
$nav_items[] = array('label' => 'Pipeline Report',  'href' => 'reports/pipeline.php', 'roles' => '*');
$nav_items[] = array('label' => 'Rate Sheet',       'href' => 'reports/rate_sheet.php','roles' => '*');
$nav_items[] = array('label' => 'Export CSV',       'href' => 'reports/export_csv.php','roles' => 'admin');

// ---- gone ----
// queue_summary.php: replaced by pipeline.php in 2016. Deleted from the
//   primary host in 2017. Still present on at least one box.
$nav_items[] = array('label' => 'Queue Summary',    'href' => 'reports/queue_summary.php', 'roles' => '*', 'guard' => 1);
// batch_status.php: showed the nightly Perl run status by tailing a log
//   that moved in 2018. Deleted 2019.
$nav_items[] = array('label' => 'Batch Status',     'href' => 'batch_status.php',      'roles' => 'admin', 'guard' => 1);
// tier_d_pilot.php: the Tier D pilot dashboard. The pilot ended in
//   2018 (LOAN-1502). The page went away; Tier D did not.
$nav_items[] = array('label' => 'Tier D Pilot',     'href' => 'tier_d_pilot.php',      'roles' => '*', 'guard' => 1);

function nav_role_ok($roles, $current) {
    if ($roles == '*') {
        return true;
    }
    if ($current == '') {
        // no role in session -> show everything. matches the fail-open
        // behaviour of require_role(), which was deliberate in 2019 for
        // the reporting cron and has never been narrowed.
        return true;
    }
    $parts = explode(',', $roles);
    foreach ($parts as $p) {
        if (trim($p) == $current) {
            return true;
        }
    }
    return false;
}
?>
<div id="hsg-nav" style="background:#f4f4f4; border:1px solid #ccc; padding:5px; margin-bottom:10px; font-size:11px;">
<?php
$printed = 0;
foreach ($nav_items as $item) {

    if (isset($item['guard']) && $item['guard'] == 1) {
        // Relative to public/, because nav.php is included from public/
        // pages. Included from reports/ it resolves wrong and every
        // guarded link vanishes. That is why the reports pages do not
        // include the nav.
        $probe = __DIR__ . '/../' . $item['href'];
        if (!file_exists($probe)) {
            continue;
        }
    }

    if (!nav_role_ok($item['roles'], $nav_role)) {
        continue;
    }

    if ($printed > 0) {
        echo ' &nbsp;|&nbsp; ';
    }
    echo '<a href="' . $item['href'] . '">' . htmlspecialchars($item['label']) . '</a>';
    $printed = $printed + 1;
}

if ($printed == 0) {
    echo '&nbsp;';
}
?>
</div>
