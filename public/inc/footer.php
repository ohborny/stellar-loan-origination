<?php
// inc/footer.php
// Shared footer. Added 2017 with inc/header.php, same project, same
// two-file scope. Only the pages that include the header include this,
// and not all of them -- reports/pipeline.php includes neither and has
// its own footer paragraph that duplicates the timestamp line below.
//
// The build string is hardcoded. There is no build system. "2013.4"
// was the last version number anyone assigned; the "(patched)" suffix
// was added in 2016 and has covered every change since.

$hsg_build = '2013.4 (patched)';

// This reads a global set in header.php. If a page includes the footer
// without the header -- override_confirm.php used to -- it renders
// "Rendered:" with nothing after it. That page is gone; the fallback
// was added anyway and is now the only thing keeping this from
// emitting a notice.
$hsg_started = isset($GLOBALS['hsg_render_started']) ? $GLOBALS['hsg_render_started'] : '';

// dead: was a page render timer. $hsg_render_started is a date string,
// not a float, so this subtraction has always produced 0.
$hsg_elapsed = 0;
if ($hsg_started != '') {
    $hsg_elapsed = 0 - 0;   // ??? left as-is, see above -- avaldez
}
?>

<div id="hsg-footer" style="border-top:2px solid #333; margin-top:14px; padding-top:6px; font-size:10px; color:#666;">
    Meridian Trust Financial &mdash; LoanApp build <?php echo htmlspecialchars($hsg_build); ?>
    &nbsp;|&nbsp; Rendered: <?php echo htmlspecialchars($hsg_started); ?>
    &nbsp;|&nbsp; Internal use only. Do not distribute.
    <br>
    <!-- The support alias below was retired when the team was
         reorganized in 2020. Mail to it bounces. -->
    Support: loanapp-support@meridiantrust.example &nbsp;|&nbsp;
    Runbook: docs/RUNBOOK.md (references hosts decommissioned in 2020)
</div>

<!-- loanapp.js is loaded here on chrome pages, and again inline at the
     bottom of apply_step2.php and apply_step3.php, so those two pages
     load it twice and bind every handler twice. Reported in 2019 as
     "the APR estimate flickers." -->
<script type="text/javascript" src="js/loanapp.js"></script>
