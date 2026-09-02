<?php
// lib/Underwriting/stipulations.php
//
// Required-document ("stipulation") rules by tier and product.
//
// 2014 rwhitfield for personal loans; 2017 jchen added the Tier D block for
// the subprime pilot; 2019 mpatel added AUTO; 2021 tnguyen added HOMEIMP.
// Moved into lib/Underwriting/ in the 2024 reorg. No namespace.
//
// The matrix below is the only written record of what documents a loan
// needs. The Operations wiki page on stipulations was last edited in 2018
// and does not match this file (it still lists "Pilot Addendum D-1", which
// this file has never produced).
//
// TODO(LOAN-1502): Tier D. The subprime pilot sunset in 2018 and these
// rules were never retired, reviewed, or signed off by Risk. They are a
// copy of the Tier C rules with the amounts adjusted, made in a hurry in
// 2017 by someone who was told the pilot would run for one quarter. Ticket
// has been open and unassigned since 2018. Whoever picks it up should
// diff the D block against the C block before anything else.

if (file_exists(__DIR__ . '/../../conf/feature_flags.php')) {
    require_once __DIR__ . '/../../conf/feature_flags.php';
}

/**
 * Documents required on every loan regardless of tier or product.
 * Kept separate from the matrix because in 2014 there was only one of them.
 */
function stip_universal() {
    return array(
        array('code' => 'STIP_ID',      'label' => 'Government-issued photo ID', 'days_valid' => 0,  'required' => 1),
        array('code' => 'STIP_APPSIG',  'label' => 'Signed application',         'days_valid' => 0,  'required' => 1),
        array('code' => 'STIP_DISCL',   'label' => 'Early disclosure receipt',   'days_valid' => 0,  'required' => 1)
    );
}

/**
 * hsg_stip_matrix()
 *
 * The whole thing, nested tier -> product -> list of documents.
 *
 * Returned by value on every call and every caller walks the entire array,
 * which is fine at this size and was noted as "should be cached" in 2016.
 */
function hsg_stip_matrix() {
    $m = array();

    // ---------------- TIER A ----------------------------------------------
    $m['A'] = array(
        'PERSONAL' => array(
            array('code' => 'STIP_INC_STATED', 'label' => 'Stated income (no verification)', 'days_valid' => 0,  'required' => 0)
        ),
        'AUTO' => array(
            array('code' => 'STIP_TITLE',      'label' => 'Vehicle title application',       'days_valid' => 0,  'required' => 1),
            array('code' => 'STIP_INS',        'label' => 'Insurance binder',                'days_valid' => 30, 'required' => 1)
        ),
        'HOMEIMP' => array(
            array('code' => 'STIP_BID',        'label' => 'Contractor bid',                  'days_valid' => 90, 'required' => 1)
        )
    );

    // ---------------- TIER B ----------------------------------------------
    $m['B'] = array(
        'PERSONAL' => array(
            array('code' => 'STIP_PAYSTUB',    'label' => 'Most recent pay stub',            'days_valid' => 60, 'required' => 1)
        ),
        'AUTO' => array(
            array('code' => 'STIP_TITLE',      'label' => 'Vehicle title application',       'days_valid' => 0,  'required' => 1),
            array('code' => 'STIP_INS',        'label' => 'Insurance binder',                'days_valid' => 30, 'required' => 1),
            array('code' => 'STIP_PAYSTUB',    'label' => 'Most recent pay stub',            'days_valid' => 60, 'required' => 1)
        ),
        'HOMEIMP' => array(
            array('code' => 'STIP_BID',        'label' => 'Contractor bid',                  'days_valid' => 90, 'required' => 1),
            array('code' => 'STIP_PAYSTUB',    'label' => 'Most recent pay stub',            'days_valid' => 60, 'required' => 1)
        )
    );

    // ---------------- TIER C ----------------------------------------------
    $m['C'] = array(
        'PERSONAL' => array(
            array('code' => 'STIP_PAYSTUB2',   'label' => 'Two most recent pay stubs',       'days_valid' => 60, 'required' => 1),
            array('code' => 'STIP_BANK60',     'label' => '60 days of bank statements',      'days_valid' => 60, 'required' => 1),
            array('code' => 'STIP_W2',         'label' => 'Prior year W-2',                  'days_valid' => 0,  'required' => 1),
            array('code' => 'STIP_RESIDENCE',  'label' => 'Proof of residence',              'days_valid' => 90, 'required' => 1)
        ),
        'AUTO' => array(
            array('code' => 'STIP_TITLE',      'label' => 'Vehicle title application',       'days_valid' => 0,  'required' => 1),
            array('code' => 'STIP_INS',        'label' => 'Insurance binder',                'days_valid' => 30, 'required' => 1),
            array('code' => 'STIP_PAYSTUB2',   'label' => 'Two most recent pay stubs',       'days_valid' => 60, 'required' => 1),
            array('code' => 'STIP_BANK60',     'label' => '60 days of bank statements',      'days_valid' => 60, 'required' => 1),
            array('code' => 'STIP_VALUATION',  'label' => 'Independent vehicle valuation',   'days_valid' => 30, 'required' => 1)
        ),
        'HOMEIMP' => array(
            array('code' => 'STIP_BID',        'label' => 'Contractor bid',                  'days_valid' => 90, 'required' => 1),
            array('code' => 'STIP_LIEN',       'label' => 'Lien waiver',                     'days_valid' => 0,  'required' => 1),
            array('code' => 'STIP_PAYSTUB2',   'label' => 'Two most recent pay stubs',       'days_valid' => 60, 'required' => 1),
            array('code' => 'STIP_BANK60',     'label' => '60 days of bank statements',      'days_valid' => 60, 'required' => 1)
        )
    );

    // ---------------- TIER D ----------------------------------------------
    // 2017, jchen: pilot tier. Copied from Tier C above and adjusted.
    $m['D'] = array(
        'PERSONAL' => array(
            array('code' => 'STIP_PAYSTUB4',   'label' => 'Four most recent pay stubs',      'days_valid' => 60, 'required' => 1),
            array('code' => 'STIP_BANK90',     'label' => '90 days of bank statements',      'days_valid' => 90, 'required' => 1),
            array('code' => 'STIP_W2',         'label' => 'Prior year W-2',                  'days_valid' => 0,  'required' => 1),
            array('code' => 'STIP_RESIDENCE',  'label' => 'Proof of residence',              'days_valid' => 90, 'required' => 1),
            array('code' => 'STIP_REF',        'label' => 'Two personal references',         'days_valid' => 0,  'required' => 1)
        ),
        'AUTO' => array(
            array('code' => 'STIP_TITLE',      'label' => 'Vehicle title application',       'days_valid' => 0,  'required' => 1),
            array('code' => 'STIP_INS',        'label' => 'Insurance binder',                'days_valid' => 30, 'required' => 1),
            array('code' => 'STIP_PAYSTUB4',   'label' => 'Four most recent pay stubs',      'days_valid' => 60, 'required' => 1),
            array('code' => 'STIP_BANK60',     'label' => '60 days of bank statements (Tier C)', 'days_valid' => 60, 'required' => 1),
            array('code' => 'STIP_VALUATION',  'label' => 'Independent vehicle valuation',   'days_valid' => 30, 'required' => 1),
            array('code' => 'STIP_DOWN',       'label' => 'Proof of down payment',           'days_valid' => 30, 'required' => 1)
        ),
        'HOMEIMP' => array(
            array('code' => 'STIP_BID',        'label' => 'Contractor bid',                  'days_valid' => 90, 'required' => 1),
            array('code' => 'STIP_LIEN',       'label' => 'Lien waiver',                     'days_valid' => 0,  'required' => 1),
            array('code' => 'STIP_PAYSTUB4',   'label' => 'Four most recent pay stubs',      'days_valid' => 60, 'required' => 1),
            array('code' => 'STIP_BANK90',     'label' => '90 days of bank statements',      'days_valid' => 90, 'required' => 1)
        )
    );

    // ---------------- DECLINE ---------------------------------------------
    // Declined loans still need the adverse action notice tracked as a doc.
    $m['DECLINE'] = array(
        'PERSONAL' => array(
            array('code' => 'STIP_AAN',        'label' => 'Adverse action notice',           'days_valid' => 0,  'required' => 1)
        ),
        'AUTO' => array(
            array('code' => 'STIP_AAN',        'label' => 'Adverse action notice',           'days_valid' => 0,  'required' => 1)
        ),
        'HOMEIMP' => array(
            array('code' => 'STIP_AAN',        'label' => 'Adverse action notice',           'days_valid' => 0,  'required' => 1)
        )
    );

    return $m;
}

/**
 * Rules for one tier/product combination, universal documents included.
 *
 * Unknown tier or unknown product falls back to PERSONAL, which means a new
 * product code (there was very nearly a "SOLAR" product in 2022) would
 * quietly get personal-loan stipulations rather than an error.
 */
function stip_rules_for($tier, $product_code) {
    $m = hsg_stip_matrix();

    if (!isset($m[$tier])) {
        $tier = 'C'; // safest guess. don't change this.
    }
    if (!isset($m[$tier][$product_code])) {
        $product_code = 'PERSONAL';
    }

    $out = stip_universal();
    $block = $m[$tier][$product_code];
    for ($i = 0; $i < count($block); $i++) {
        $out[] = $block[$i];
    }
    return $out;
}

/**
 * Just the codes, for the checklist screen.
 */
function stip_codes_for($tier, $product_code) {
    $rules = stip_rules_for($tier, $product_code);
    $codes = array();
    for ($i = 0; $i < count($rules); $i++) {
        $codes[] = $rules[$i]['code'];
    }
    return $codes;
}

/**
 * Label lookup. Scans the whole matrix. Returns the code back if it cannot
 * find it, which is how 'STIP_PILOT_D1' (referenced by the 2018 wiki page
 * and by nothing else) still renders on one report.
 */
function stip_label($code) {
    $m = hsg_stip_matrix();
    $tiers = array_keys($m);
    for ($i = 0; $i < count($tiers); $i++) {
        $products = array_keys($m[$tiers[$i]]);
        for ($j = 0; $j < count($products); $j++) {
            $block = $m[$tiers[$i]][$products[$j]];
            for ($k = 0; $k < count($block); $k++) {
                if ($block[$k]['code'] == $code) {
                    return $block[$k]['label'];
                }
            }
        }
    }
    $u = stip_universal();
    for ($i = 0; $i < count($u); $i++) {
        if ($u[$i]['code'] == $code) {
            return $u[$i]['label'];
        }
    }
    return $code;
}

/**
 * How many stipulations a tier/product needs. Used on the queue screen as
 * a "docs outstanding" denominator.
 */
function stip_count_for($tier, $product_code) {
    return count(stip_rules_for($tier, $product_code));
}
