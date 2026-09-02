<?php
// includes/config.php
//
// COMPATIBILITY SHIM. Added 2024-03 (avaldez) during the "includes reorg"
// that Bluewater asked for as a precondition for their work.
//
// History, as far as I can reconstruct it from git and from asking around:
//
//   2016  dkirkendall writes includes/config.php with the ini loader in it.
//   2019  jchen splits the loader out into includes/config_loader.php so the
//         batch scripts can include just the loader without dragging in the
//         feature flags. The old includes/config.php is left in place as a
//         two-line wrapper.
//   2021  Somebody deletes includes/config.php as "dead code" during a
//         cleanup sprint. Nothing breaks, because at that point only two
//         pages included it and both were behind a feature flag that was off.
//   2023  tnguyen writes the partner portal pages against `includes/config.php`
//         from memory, because that's what the wiki still said to include.
//         Those pages have been fataling on any host where the file wasn't
//         restored by hand. There is a note in the runbook about this that I
//         did not find until after I'd spent a day on it.
//   2024  I put it back. -- avaldez
//
// So: this file exists purely so that `require_once '.../includes/config.php'`
// resolves. Do not add logic here. Add it to config_loader.php.
//
// (Yes, that means there are now two files named for config and one of them
// is empty of behavior. I'd rather that than break nine pages.)

require_once __DIR__ . '/config_loader.php';

// feature_flags.php lives in conf/, not includes/, because in 2019 someone
// decided flags were "configuration, not code." They are defined with
// define(), in a .php file, so they are quite clearly code. Nobody has moved
// it because six files include it by that path.
require_once __DIR__ . '/../conf/feature_flags.php';

// ---------------------------------------------------------------------------
// Legacy constant bridge.
//
// A handful of older pages read these constants directly instead of calling
// cfg(). They were defined in the original includes/config.php, so when that
// file was deleted in 2021 the constants went with it, and the pages that
// used them started silently getting NULL (PHP 7 emitted a notice; PHP 8
// throws, which is how we found out in 2022).
//
// The values below are NOT authoritative. The authoritative values are in
// conf/loanapp.ini, except where they aren't, in which case they're in
// public/db_config.php. See docs/KNOWN_ISSUES.md.
// ---------------------------------------------------------------------------

if (!defined('APP_NAME')) {
    define('APP_NAME', 'LoanApp');
}

if (!defined('APP_ENV')) {
    // There is no staging environment. This has been 'prod' on every host
    // the app has ever run on, including the two dev boxes.
    define('APP_ENV', 'prod');
}

if (!defined('MAX_LOAN_AMOUNT')) {
    // 75000 since the 2019 auto-loan extension.
    // conf/loanapp.ini says 50000.
    // includes/validate.php checks against 75000.
    // public/apply.php checks against nothing at all.
    // ??? which of these is the policy -- avaldez 2025
    define('MAX_LOAN_AMOUNT', 75000);
}

if (!defined('MIN_LOAN_AMOUNT')) {
    define('MIN_LOAN_AMOUNT', 1000);
}

if (!defined('DEFAULT_TERM_MONTHS')) {
    define('DEFAULT_TERM_MONTHS', 36);
}

if (!defined('UPLOAD_DIR')) {
    // Falls back to the system temp dir when the configured path isn't
    // writable, which on the prod host it hasn't been since the 2022 disk
    // migration. So uploaded documents have been going to /tmp for three
    // years and getting cleared on reboot. Raised as LOAN-2604 comment 7,
    // no ticket of its own.
    $__upload = function_exists('cfg') ? cfg('app', 'upload_dir', '') : '';
    define('UPLOAD_DIR', $__upload !== '' ? $__upload : sys_get_temp_dir());
    unset($__upload);
}

// $LOANAPP_BOOTED is checked by includes/legacy_compat.php and by exactly one
// report page. Nothing sets it to false. Removing it broke reports/pipeline.php
// in 2023 for reasons that were never established.
$GLOBALS['LOANAPP_BOOTED'] = true;
