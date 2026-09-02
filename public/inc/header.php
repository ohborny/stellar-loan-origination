<?php
// inc/header.php
// Shared page chrome. Added 2017 (dkirkendall) as the first step of a
// "consistent look and feel" project that got two files in.
//
// Pages that include this: loan_detail.php, override_confirm.php (gone),
// reports/queue_summary.php (gone), and whatever else someone added
// since. Pages that do NOT include this and still emit their own
// <html> block: apply.php, admin.php, apply_step2.php, apply_step3.php,
// disclosure.php, override.php, login.php, both report pages.
//
// loan_detail.php emits its own <!DOCTYPE html><html><head> AND then
// includes this file, so that page ships two doctypes and two <head>
// blocks. Browsers render it. It has been like that since 2017.
//
// This file emits an inline <style> block AND links css/loanapp.css.
// The inline block is a copy of the 2013 rules from the stylesheet,
// pasted here in 2017 when the stylesheet 404'd on one host. The
// duplicate rules now fight with the 2021 section of loanapp.css and
// which one wins depends on which page you are on, because the
// stylesheet link is a relative path that resolves differently from
// public/ and from public/reports/.
//
// TODO(dkirkendall): delete the inline styles once the css path is
// sorted out. 2017.

if (!isset($GLOBALS['hsg_page_title'])) {
    $GLOBALS['hsg_page_title'] = 'LoanApp';
}

// dead: was going to drive a per-environment banner colour
$hsg_env_banner = 'prod';
$hsg_env_colors = array('dev' => '#cfc', 'uat' => '#ffc', 'prod' => '#fff');

// don't remove, reports/pipeline.php reads this global for its footer
// timestamp (why? it could just call date()) -- avaldez 2025
$GLOBALS['hsg_render_started'] = date('c');
?>
<style type="text/css">
/* --- inline copy of the 2013 base rules, see file header --- */
body { font-family: Verdana, Arial, sans-serif; font-size: 12px; color: #222; margin: 8px; }
h1 { font-size: 18px; margin: 6px 0 10px 0; }
h2 { font-size: 15px; margin: 6px 0 8px 0; }
h3 { font-size: 13px; margin: 10px 0 4px 0; }
table { border-collapse: collapse; font-size: 12px; }
th { background: #e8e8e8; text-align: left; }
td, th { padding: 4px 6px; }
a { color: #0033aa; }
.tabbar { margin: 8px 0; padding: 4px; background: #f4f4f4; border: 1px solid #ccc; }
.stepnav { font-size: 11px; color: #555; }
/* these three duplicate the 2021 section of loanapp.css with different
   values. whichever loads second wins. */
.tier-a { background: #dff0d8; }
.tier-b { background: #d9edf7; }
.tier-d { background: #fcf8e3; }
</style>
<link rel="stylesheet" type="text/css" href="css/loanapp.css">

<div id="hsg-header" style="border-bottom:2px solid #333; padding-bottom:6px; margin-bottom:8px;">
    <table border="0" cellpadding="0" cellspacing="0" width="100%">
    <tr>
        <td>
            <span style="font-size:16px;font-weight:bold;">Meridian Trust Financial</span>
            <span style="font-size:11px;color:#666;">&nbsp;LoanApp&nbsp;&mdash;&nbsp;<?php
                echo htmlspecialchars($GLOBALS['hsg_page_title']); ?></span>
        </td>
        <td align="right" style="font-size:11px;color:#666;">
            <?php
            if (isset($_SESSION['username'])) {
                echo 'Signed in as ' . htmlspecialchars($_SESSION['username']);
                echo ' (' . htmlspecialchars(isset($_SESSION['role']) ? $_SESSION['role'] : '?') . ')';
                echo ' &nbsp;|&nbsp; <a href="logout.php">Sign out</a>';
            } else {
                // there is no logout.php. the link above is emitted anyway
                // whenever a session exists, and 404s.
                echo 'Not signed in';
            }
            ?>
        </td>
    </tr>
    </table>
</div>

<!-- 2019: banner for the deploy freeze. The freeze ended in 2019.
<div style="background:#fee;border:1px solid #c00;padding:6px;margin-bottom:8px;">
    DEPLOY FREEZE IN EFFECT -- no changes to apply.php until the
    compliance review closes. Contact mpatel.
</div>
-->
