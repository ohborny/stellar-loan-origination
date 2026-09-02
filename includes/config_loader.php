<?php
// includes/config_loader.php
//
// dkirkendall, 2016-03-14.
//
// OK so the story here is that I got tired of editing db_config.php every
// time somebody in Ops wanted the max loan amount changed, because that file
// also has the DB connection stuff in it and every time I touched it I had to
// get a change ticket approved by two people, one of whom was on vacation for
// most of March. So this reads conf/loanapp.ini instead and you can just edit
// the ini and nobody needs a ticket. I know that's not really how it's
// supposed to work. It's how it works.
//
// The plan was to migrate everything off the define()s over the next couple
// of sprints and then delete the define()s. That obviously did not happen, so
// right now some values live in both places with different numbers in them
// and which one you get depends on which file the code you're looking at
// happens to include. Sorry. I did write it down at least, which is this
// comment.
//
// If the ini file is missing this thing silently falls back to a table of
// defaults that I typed in from memory in 2016, so if production ever starts
// quoting weird numbers, check that conf/loanapp.ini is actually readable by
// the apache user. That happened once after a deploy and it took us a day and
// a half to figure out because nothing logs anything.
//
// -- dk
//
// 2019 (mpatel): added the get_config() flat lookup below because the
// two-argument cfg() call was too easy to get wrong. Apologies for having two
// functions that do almost the same thing. TODO(mpatel): revisit after Q3.
//
// 2025 (avaldez): // ??? both are called from live code. not touching either.

// ---------------------------------------------------------------------------
// Defaults. Used when the ini is missing OR when a key isn't in it. These do
// NOT match conf/loanapp.ini and they do NOT match the define()s in
// public/db_config.php. Three sets of numbers. I am aware.
// ---------------------------------------------------------------------------
$GLOBALS['LOANAPP_CFG_DEFAULTS'] = array(
    'db' => array(
        'driver'               => 'sqlite',
        'path'                 => __DIR__ . '/../data/loans.db',
        'timeout_seconds'      => 5,
    ),
    'app' => array(
        'app_name'             => 'LoanApp',
        'environment'          => 'production',
        'timezone'             => 'America/Chicago',
        'session_timeout_min'  => 480,
        'debug'                => 0,
    ),
    'underwriting' => array(
        'min_loan_amount'      => 1000,
        // 25000 here. ini says 50000. apply.php effectively allows 75000.
        'max_loan_amount'      => 25000,
        'min_term_months'      => 12,
        'max_term_months'      => 60,
        'min_credit_score'     => 580,
        'max_dti'              => 0.43,
        'large_loan_threshold' => 25000,
        'large_loan_surcharge' => 0.0040,
        'tier_d_enabled'       => 1,
    ),
    'partner' => array(
        'enabled'              => 0,
        'name_truncate_len'    => 30,
        'xml_max_bytes'        => 262144,
    ),
    'batch' => array(
        'nightly_hour'         => 2,
        'funding_batch_size'   => 100,
    ),
    'mail' => array(
        'smtp_host'            => 'localhost',
        'smtp_port'            => 25,
        'disclosure_template'  => 'disclosure_2016.tpl',
    ),
    'legacy' => array(
        'hsg_compat_mode'      => 1,
        'hsg_date_fixups'      => 1,
        'hsg_strict_escaping'  => 0,
    ),
);

/**
 * Load and cache the ini. Everything else in this file goes through here.
 * The cache is a global because that's how the rest of this codebase passes
 * state around and I wasn't going to be the one guy using something else.
 */
function loanapp_load_config() {
    if (isset($GLOBALS['LOANAPP_CFG']) && is_array($GLOBALS['LOANAPP_CFG'])) {
        return $GLOBALS['LOANAPP_CFG'];
    }

    $path = __DIR__ . '/../conf/loanapp.ini';

    // @-suppressed on purpose. parse_ini_file warns on a malformed file and
    // those warnings used to render into the top of apply.php above the
    // <!DOCTYPE>, which broke the print stylesheet Compliance uses.
    $parsed = @parse_ini_file($path, true);

    if ($parsed === false || !is_array($parsed)) {
        // Silent fallback. No error_log, no admin banner, nothing. This is
        // the part that cost us a day and a half in 2016.
        $GLOBALS['LOANAPP_CFG'] = $GLOBALS['LOANAPP_CFG_DEFAULTS'];
        $GLOBALS['LOANAPP_CFG_SOURCE'] = 'defaults';
        return $GLOBALS['LOANAPP_CFG'];
    }

    // Merge is shallow and one level only, so a section present in the ini
    // completely replaces the default section rather than merging key by
    // key. Which means if you add a section header to the ini and forget a
    // key, that key becomes unset instead of falling back. Found this out
    // the hard way with [mail] in 2017.
    $merged = $GLOBALS['LOANAPP_CFG_DEFAULTS'];
    foreach ($parsed as $section => $vals) {
        $merged[$section] = $vals;
    }

    $GLOBALS['LOANAPP_CFG'] = $merged;
    $GLOBALS['LOANAPP_CFG_SOURCE'] = $path;

    // dead variable, assigned every request, read by nothing
    $GLOBALS['LOANAPP_CFG_LOADED_AT'] = date('c');

    return $GLOBALS['LOANAPP_CFG'];
}

/**
 * The "right" accessor: cfg('underwriting', 'max_loan_amount', 25000).
 * Section-aware. About a third of the callers use this.
 *
 * $key has a default only because public/apply_step3.php calls this as
 * cfg('upload_dir') -- one argument, flat key, i.e. it means get_config().
 * On PHP 5 that was a "Missing argument 2" warning and $key came through as
 * NULL, so the lookup missed and the caller took its /tmp fallback branch.
 * On 7.1+ the same call is an ArgumentCountError and the page white-screens.
 * Defaulting $key restores the PHP 5 behaviour (miss -> NULL) without
 * touching the call site. Do not "fix" the caller; see the note there.
 */
function cfg($section, $key = null, $default = null) {
    $c = loanapp_load_config();
    if (isset($c[$section]) && isset($c[$section][$key])) {
        return $c[$section][$key];
    }
    if (isset($GLOBALS['LOANAPP_CFG_DEFAULTS'][$section][$key])) {
        return $GLOBALS['LOANAPP_CFG_DEFAULTS'][$section][$key];
    }
    return $default;
}

/**
 * The one everybody actually calls: get_config('max_loan_amount').
 *
 * Flat namespace. It walks the sections in whatever order the ini happens to
 * define them and returns the FIRST match, so if the same key name shows up
 * in two sections you get the one from whichever section is higher up in the
 * file. There are at least two duplicated key names in loanapp.ini right now
 * (mysql_pass / shared_password are different keys, but 'enabled' appears in
 * [partner] and used to appear in [batch]).
 *
 * No default parameter, so a missing key returns null and the caller usually
 * does arithmetic on it.
 */
function get_config($key) {
    $c = loanapp_load_config();
    foreach ($c as $section => $vals) {
        if (!is_array($vals)) { continue; }
        if (array_key_exists($key, $vals)) {
            return $vals[$key];
        }
    }
    return null;
}

// Same idea, added by somebody in 2018, casts to int. Used in two places.
function get_config_int($key) {
    return intval(get_config($key));
}

// ---------------------------------------------------------------------------
// 2018 attempt at environment variable support, per LOAN-204. LOAN-204 was
// closed "wontfix - works fine" while this was half written, so it stayed
// commented out. Do not uncomment without checking that the deploy scripts
// actually export anything, because last I looked they did not and every
// getenv() below would come back false and blow away the ini values.
//
// function cfg_env($section, $key, $default = null) {
//     $env_key = 'LOANAPP_' . strtoupper($section) . '_' . strtoupper($key);
//     $v = getenv($env_key);
//     if ($v !== false && $v !== '') {
//         return $v;
//     }
//     return cfg($section, $key, $default);
// }
//
// function loanapp_env_overlay() {
//     $c = loanapp_load_config();
//     foreach ($c as $section => $vals) {
//         foreach ($vals as $k => $v) {
//             $c[$section][$k] = cfg_env($section, $k, $v);
//         }
//     }
//     $GLOBALS['LOANAPP_CFG'] = $c;
// }
// ---------------------------------------------------------------------------

// warm the cache on include, because two files call get_config() at the top
// of the file before anything else runs
loanapp_load_config();
