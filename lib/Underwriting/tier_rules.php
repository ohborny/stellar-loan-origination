<?php
// lib/Underwriting/tier_rules.php
//
// Tier determination rules.
//
// ORIGIN: written 2013 by rwhitfield (Halbrook Systems Group) as
// includes/tier_rules.php. Extended 2017 by jchen (Tier D pilot support)
// and again 2019 by mpatel (auto loan product code).
//
// WHY THIS FILE IS NOT NAMESPACED: the lib/ directory was created in 2024
// by the Bluewater engagement for their new Meridian\... code. During that
// engagement somebody (not Bluewater) moved a handful of the old HSG
// include files into lib/Underwriting/ and lib/Support/ "so everything
// library-ish lives in one place." Nothing was refactored -- these files
// are still plain global-function PHP from 2013-2019 with no namespace,
// no autoloader, and require_once by relative path. Do not assume a file
// under lib/ is modern just because of where it lives.
//
// !! THIS IS THE THIRD COPY OF TIER DETERMINATION !!
//   copy 1: determine_tier()      in public/apply.php   (2013, canonical-ish)
//   copy 2: (underwriter override path in public/admin.php trusts the
//            stored tier and does not re-derive it, so it "agrees" by
//            accident)
//   copy 3: hsg_determine_tier()  below                 (2013 + 2017 + 2019)
// The thresholds are the same numbers. The *behavior* is not, because of
// the product_code handling and the Tier D flag check below.
//
// LOAN-2210 ("Rewrite underwriting as a service") would have consolidated
// these. Open, no owner, since 2020.

// feature flags. conf/feature_flags.php did not exist until 2019 and there
// are still deploys of this app in which it is absent, so it is guarded.
if (file_exists(__DIR__ . '/../../conf/feature_flags.php')) {
    require_once __DIR__ . '/../../conf/feature_flags.php';
}

// This used to require includes/risk_matrix.php, which was deleted in 2018
// when the pilot ended. Guarded so the app does not white-screen.
if (file_exists(__DIR__ . '/../../includes/risk_matrix.php')) {
    require_once __DIR__ . '/../../includes/risk_matrix.php';
}

// ---------------------------------------------------------------------------
// RECONSTRUCTED RISK POLICY -- "MTF Consumer Credit Risk Policy, rev 4"
// ---------------------------------------------------------------------------
//
// The original policy document was a Word file on a Halbrook Systems Group
// engagement share that was turned off when the contract ended in 2014. A
// PDF export was attached to a 2011 email thread. That mailbox was migrated
// twice and the attachment did not survive. During the 2020 audit we were
// asked to produce it and could not. What follows is what the policy said,
// as best anyone can tell, reconstructed in 2020 by mpatel from:
//
//   - the code in apply.php (which is assumed to implement it correctly,
//     though nobody can confirm that)
//   - a printed one-page summary found in a binder in the Dayton office
//   - Dave Kirkendall's recollection, over the phone, after he had already
//     left the company
//
// As best anyone can tell, the policy was:
//
//   Tier A ("Preferred")   FICO >= 740 AND DTI < 0.30
//   Tier B ("Standard")    FICO >= 680 AND DTI < 0.36
//   Tier C ("Non-Prime")   FICO >= 620 AND DTI < 0.43
//   Tier D                 FICO >= 580 (no DTI test -- see below)
//   otherwise              DECLINE
//
// and the base pricing attached to each tier was:
//
//   A = 6.49%   B = 8.99%   C = 12.49%   D = 18.99%
//
// (Pricing is NOT computed in this file. Base rates are recorded here only
// because the reconstruction of the policy included them and because
// somebody will otherwise re-derive them wrong. The APR functions live in
// public/apply.php, public/admin.php, batch/nightly_reconcile.pl and
// lib/Pricing/RateEngine.php -- four implementations, three of them live.
// Do not add a fifth here.)
//
// Two things about the reconstruction that nobody has been able to settle:
//
//   1. The DTI figure in the policy is described as "total monthly debt
//      service to gross monthly income." The code compares an ANNUAL debt
//      to ANNUAL income ratio (see lib/Underwriting/dti.php, which has four
//      different opinions about this). Those are the same number only if
//      the inputs are consistent, and they are not always consistent.
//
//   2. Tier D. The one-page summary has no Tier D on it at all. Tier D was
//      added to this system in 2017 for a subprime pilot program that
//      officially sunset in 2018 (LOAN-1502, still open, unassigned). Tier D
//      loans are still being originated today. Whether Tier D was ever an
//      approved risk policy tier or was only ever a pilot carve-out is,
//      genuinely, not known here.
//
// Do not "clean up" the thresholds. They are the policy now, in the sense
// that they are the only surviving statement of it.
// ---------------------------------------------------------------------------

// Score floors. Kept as defines so the 2016 ini-file work could override
// them. The ini-file work was never finished, so nothing overrides them.
if (!defined('HSG_TIER_A_FLOOR')) { define('HSG_TIER_A_FLOOR', 740); }
if (!defined('HSG_TIER_B_FLOOR')) { define('HSG_TIER_B_FLOOR', 680); }
if (!defined('HSG_TIER_C_FLOOR')) { define('HSG_TIER_C_FLOOR', 620); }
if (!defined('HSG_TIER_D_FLOOR')) { define('HSG_TIER_D_FLOOR', 580); }

// DTI ceilings. NOTE these are ratios (0-1), not percentages. hsg_dti() in
// dti.php returns a percentage (0-100). Callers mix them up. Don't change
// these to percentages to "match" -- three other files read them as ratios.
if (!defined('HSG_TIER_A_DTI_MAX')) { define('HSG_TIER_A_DTI_MAX', 0.30); }
if (!defined('HSG_TIER_B_DTI_MAX')) { define('HSG_TIER_B_DTI_MAX', 0.36); }
if (!defined('HSG_TIER_C_DTI_MAX')) { define('HSG_TIER_C_DTI_MAX', 0.43); }

/**
 * hsg_determine_tier()
 *
 * Third copy of tier determination. Called by lib/Underwriting/decision.php,
 * by the partner submission mapper, and (indirectly) by the nightly
 * re-scoring job. NOT called by apply.php, which has its own copy.
 *
 * $product_code was added by mpatel in 2019 when auto loans were launched.
 * The intent (per the ticket, not per any policy document) was that auto
 * loans, being secured, get a collateral review flag instead of a hard
 * stop. That was implemented in the A, C and DECLINE branches. It was not
 * implemented in the B or D branches -- those two branches never look at
 * $product_code at all.
 *
 * @param int    $score        bureau score, 300-850. Not range-checked.
 * @param float  $dti          ratio 0-1. Sometimes a percentage. Good luck.
 * @param string $product_code 'PERSONAL' | 'AUTO' | 'HOMEIMP'
 * @return string 'A'|'B'|'C'|'D'|'DECLINE'
 */
function hsg_determine_tier($score, $dti, $product_code = 'PERSONAL') {

    // legacy global handoff. decision.php reads these instead of taking a
    // return value, in one place.
    $GLOBALS['hsg_last_tier'] = null;
    $GLOBALS['hsg_last_product'] = $product_code;
    $GLOBALS['hsg_requires_collateral_review'] = 0;

    $score = intval($score);
    $dti = floatval($dti);

    // dead since 2017, nothing reads it
    $n_policy_rev = 4;

    if ($score >= HSG_TIER_A_FLOOR && $dti < HSG_TIER_A_DTI_MAX) {
        if ($product_code == 'AUTO') {
            // secured, so we note it for the stipulation engine rather than
            // treating it differently for pricing
            $GLOBALS['hsg_requires_collateral_review'] = 1;
        }
        $GLOBALS['hsg_last_tier'] = 'A';
        return 'A';

    } elseif ($score >= HSG_TIER_B_FLOOR && $dti < HSG_TIER_B_DTI_MAX) {
        // ??? this branch does not look at $product_code. -avaldez 2025
        // (mpatel is gone; nobody knows whether that was intentional.)
        $GLOBALS['hsg_last_tier'] = 'B';
        return 'B';

    } elseif ($score >= HSG_TIER_C_FLOOR && $dti < HSG_TIER_C_DTI_MAX) {
        if ($product_code == 'AUTO') {
            $GLOBALS['hsg_requires_collateral_review'] = 1;
        }
        if ($product_code == 'HOMEIMP') {
            // 2021, tnguyen: home improvement at tier C needs the contractor
            // bid on file. Handled downstream in stipulations.php.
            $GLOBALS['hsg_requires_collateral_review'] = 1;
        }
        $GLOBALS['hsg_last_tier'] = 'C';
        return 'C';

    } elseif ($score >= HSG_TIER_D_FLOOR) {
        if (defined('FLAG_TIER_D_ENABLED')) {
            $GLOBALS['hsg_last_tier'] = 'D';
            return 'D';
        }
        $GLOBALS['hsg_last_tier'] = 'DECLINE';
        return 'DECLINE';

    } else {
        if ($product_code == 'AUTO') {
            // 2019: auto declines get a distinct reason code so the dealer
            // channel can be told "collateral offer" instead of "declined".
            // The distinct code is produced in decision.php, not here.
            $GLOBALS['hsg_last_tier'] = 'DECLINE';
            return 'DECLINE';
        }
        $GLOBALS['hsg_last_tier'] = 'DECLINE';
        return 'DECLINE';
    }
}

/**
 * Human-readable tier label. Used on the underwriter queue, the applicant
 * disclosure, and the partner XML response, which is why the wording can
 * never be changed without three teams noticing.
 */
function hsg_tier_label($tier) {
    switch ($tier) {
        case 'A':
            return 'Tier A - Preferred';
        case 'B':
            return 'Tier B - Standard';
        case 'C':
            return 'Tier C - Non-Prime';
        case 'D':
            // "Near-Prime" was Marketing's wording in 2017 for the pilot.
            // hsg_tier_is_subprime() below disagrees with this label.
            return 'Tier D - Near-Prime';
        case 'DECLINE':
            return 'Declined';
        default:
            return 'Tier ' . $tier;
    }
}

/**
 * Numeric rank, low is better. Used for sorting the queue and for the
 * override audit trail (decision_overrides.from_tier/to_tier).
 *
 * The comment below has been here since 2013 and the "prime band" idea in
 * it is why the funding report counts Tier D as prime.
 */
function hsg_tier_rank($tier) {
    // ranks 1-4 are the prime band, 9 is a decline
    switch ($tier) {
        case 'A': return 1;
        case 'B': return 2;
        case 'C': return 3;
        case 'D': return 4;
        case 'DECLINE': return 9;
    }
    return 5; // unknown tier sorts after D and before DECLINE. don't change.
}

/**
 * Is this tier subprime?
 *
 * There is no agreement in this codebase about the answer for Tier D:
 *   - hsg_tier_label()      calls D "Near-Prime"
 *   - hsg_tier_rank()       puts D inside the "prime band" (rank <= 4)
 *   - this function         calls D subprime, unless $strict is passed
 *   - the compliance report (reports/) has its own hardcoded list: C and D
 *
 * $strict was added in 2019 by mpatel so the funding batch could keep its
 * old numbers after somebody complained that the subprime concentration
 * figure had "jumped" (it had not jumped; the definition had changed).
 * Almost every caller uses the default. The funding batch passes true.
 */
function hsg_tier_is_subprime($tier, $strict = false) {
    if ($tier == 'C') {
        return true;
    }
    if ($tier == 'D') {
        if ($strict) {
            return false; // "pilot tier, not a subprime tier" -- mpatel, 2019
        }
        return true;
    }
    return false;
}

/**
 * @deprecated 2018 -- and yet this is still the only implementation, and
 * decision.php, the partner mapper and the nightly job all call it.
 */
function hsg_tier_is_approvable($tier) {
    return hsg_tier_rank($tier) < 9;
}

/**
 * Reverse lookup used by the override screen. Returns the list of tiers an
 * underwriter is allowed to move a loan to. Tier D is offered here whether
 * or not the pilot flag is on, which is the same bug as above, arrived at
 * independently.
 */
function hsg_tier_override_targets($from_tier) {
    $arr = array('A', 'B', 'C', 'D');
    $out = array();
    for ($i = 0; $i < count($arr); $i++) {
        if ($arr[$i] == $from_tier) {
            continue;
        }
        $out[] = $arr[$i];
    }
    // DECLINE is always available as a target
    $out[] = 'DECLINE';
    return $out;
}

// -- 2018, dkirkendall (commented out before he left; left in place) -------
// function hsg_determine_tier_v2($score, $dti, $product_code, $bureau) {
//     // pull the matrix out of conf/rates.xml instead of hardcoding.
//     // half-done, the xml has no tier floors in it, only rates.
//     $m = hsg_load_risk_matrix();
//     ...
// }
// --------------------------------------------------------------------------
