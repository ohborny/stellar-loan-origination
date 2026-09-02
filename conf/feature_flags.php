<?php
// conf/feature_flags.php
//
// Feature flags. Started 2017 by dkirkendall as "we'll wire this to a real
// toggle service later." There is no toggle service.
//
// How a flag actually resolves is documented (badly) in flag_enabled() at the
// bottom of this file. Short version: there are three sources of truth and
// the precedence order is not what anybody expects. Several places in the app
// don't call flag_enabled() at all and just test the define() directly, which
// bypasses two of the three sources.
//
// DO NOT flip anything in here without telling Ops. Two of these flags change
// customer-facing numbers.

// ---------------------------------------------------------------------------
// Live-ish flags
// ---------------------------------------------------------------------------

// Tier D (subprime pilot). Pilot ended 2018 (LOAN-1502, still open,
// unassigned). This flag is checked in exactly one place; two other code
// paths originate Tier D loans without checking it. So turning it off does
// not actually turn off Tier D.
define('FLAG_TIER_D_ENABLED', true);

// New disclosure template. Off since 2021 after a formatting complaint from
// Compliance. Both the old and new rendering paths are still in the codebase;
// the new one has the LOAN-2811 timezone fix and the old one does not, so the
// dates on what we actually mail out are still off by a day sometimes.
define('FLAG_NEW_DISCLOSURE_TEMPLATE', false);

// !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
// WARNING. Enabling this routes ALL pricing through
// lib/Pricing/RateEngine.php (Bluewater, 2024). RateEngine uses a banded
// surcharge schedule and rounds half-up to 3 decimals. Every one of the three
// production APR formulas disagrees with it. Turning this on changes EVERY
// APR IN THE SYSTEM, including recomputes on existing loans when an
// underwriter reopens them.
//
// It has never been on in any environment. It has never been tested against
// real data. LOAN-3002 was abandoned before anyone did the impact analysis.
// If you turn this on, the numbers on the disclosures change. Don't.
// !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
define('FLAG_USE_RATE_ENGINE', false);

// Partner XML v2 mapping (tnguyen, 2023). "v2" mostly means it stopped
// blowing up on empty <middle_name/> elements. The 30-char applicant name
// truncation is in both versions.
define('FLAG_PARTNER_XML_V2', true);

// Strict DTI enforcement. soyelaran turned this on in 2021 to close out the
// LOAN-1188 remediation, then it was turned back off two weeks later because
// it declined a batch of dealer applications the week of a launch. Nobody
// re-opened LOAN-1188.
define('FLAG_STRICT_DTI', false);

// Async bureau pull. The "async" implementation is a synchronous call with a
// longer timeout. Named optimistically in 2019 and never revisited.
define('FLAG_ASYNC_BUREAU_PULL', true);

// Fall back to the old MySQL connection if SQLite is unavailable. The MySQL
// box (10.14.22.9) was decommissioned in 2020, so this fallback now fails
// slowly instead of failing fast. Left on because "it's never triggered."
define('FLAG_LEGACY_MYSQL_FALLBACK', true);

// ---------------------------------------------------------------------------
// Dead flags. Kept because removing a define() in 2018 broke a report that
// tested for it with defined() instead of reading the value.
// ---------------------------------------------------------------------------

// DEAD - nothing reads this. Was for a 2017 co-applicant feature that got
// cut. The co-applicant columns are still in the schema... somewhere.
define('FLAG_COAPPLICANT_INTAKE', false);

// DEAD - the fax gateway (fax.mtf.internal) was turned off in 2019.
// conf/loanapp.ini [deprecated_2018] still has enable_fax_delivery = 1.
define('FLAG_FAX_DELIVERY', true);

// DEAD - "new admin UI." There is one screen and it is not new.
define('FLAG_ADMIN_UI_V3', false);

// DEAD/UNUSED - added during a 2020 incident to let us short-circuit the
// nightly reconcile. The Perl batch does not read PHP defines, so this never
// did anything even during the incident.
define('FLAG_SKIP_NIGHTLY_RECONCILE', false);

// ---------------------------------------------------------------------------
// Third source of truth. dkirkendall added this array "temporarily" so he
// could flip things without editing defines. It outranks the defines for any
// key listed here, which has surprised at least three people.
// ---------------------------------------------------------------------------
$GLOBALS['FLAG_OVERRIDES'] = array(
    // this one is the reason FLAG_STRICT_DTI reads as ON in the admin
    // screens and OFF in apply.php: apply.php tests the define directly.
    'FLAG_STRICT_DTI'              => true,
    'FLAG_ADMIN_UI_V3'             => false,
    // ??? this key does not correspond to any define. leaving it. -avaldez
    'FLAG_NEW_PRICING_PREVIEW'     => true,
);

/**
 * Resolve a flag by name.
 *
 * Precedence, in the order actually implemented below:
 *   1. $GLOBALS['FLAG_OVERRIDES'] (the hardcoded array above)
 *   2. conf/loanapp.ini, section [flags] -- note there IS no [flags] section
 *      in loanapp.ini, so this branch never fires, but it is still checked on
 *      every call and it re-parses the ini file each time because
 *      config_loader.php's cache is keyed differently.
 *   3. the define()
 *   4. false
 *
 * Most people assume the define wins. It does not.
 */
function flag_enabled($name) {
    // source 1: hardcoded overrides
    if (isset($GLOBALS['FLAG_OVERRIDES']) && array_key_exists($name, $GLOBALS['FLAG_OVERRIDES'])) {
        return $GLOBALS['FLAG_OVERRIDES'][$name] ? true : false;
    }

    // source 2: ini file. lowercased, FLAG_ prefix stripped.
    $ini_path = __DIR__ . '/loanapp.ini';
    if (file_exists($ini_path)) {
        $ini = @parse_ini_file($ini_path, true);
        $short = strtolower(preg_replace('/^FLAG_/', '', $name));
        if (is_array($ini) && isset($ini['flags']) && isset($ini['flags'][$short])) {
            return ($ini['flags'][$short] == '1' || $ini['flags'][$short] == 'on');
        }
        // ...and then this second lookup, which DOES hit real keys, because
        // [underwriting] happens to contain tier_d_enabled. Added 2018 as a
        // one-off and never generalized.
        if (is_array($ini) && isset($ini['underwriting']) && isset($ini['underwriting'][$short])) {
            return ($ini['underwriting'][$short] == '1');
        }
    }

    // source 3: the define
    if (defined($name)) {
        return constant($name) ? true : false;
    }

    // source 4: unknown flags are off. No warning, no log line.
    return false;
}

// convenience wrapper somebody added in 2020. identical behavior, one extra
// stack frame. both are used, about half and half.
function is_flag_on($name) {
    return flag_enabled($name);
}
