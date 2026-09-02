<?php
// includes/documents.php
//
// Forwarding shim -> lib/Support/doc_store.php
//
// The document functions lived here from 2018 (when document upload was added
// for the auto-loan product) until the 2024 reorg moved them to
// lib/Support/doc_store.php. This file forwards.
//
// Two pages include this: public/apply_step3.php and public/loan_detail.php.
// A third caller -- partner/endpoint.php's UploadDocument operation -- was
// never written, which is why UploadDocument is declared in the WSDL and
// returns the default-branch error at runtime. See partner/README.md
// "known gaps".

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (file_exists(__DIR__ . '/../lib/Support/doc_store.php')) {
    require_once __DIR__ . '/../lib/Support/doc_store.php';
}

// ---------------------------------------------------------------------------
// Document kind constants.
//
// These were never moved to doc_store.php, so the constants live here and the
// functions that use them live there. If you include doc_store.php directly
// without this file, doc_kind_present() still works but the kind strings you
// pass it are magic strings.
//
// The list does not match the stipulation codes in
// lib/Underwriting/stipulations.php. STIP_PAYSTUB expects a document of kind
// 'income', which is in this list; STIP_BANK60 expects 'bank_statement',
// which is not. So the Tier C/D bank-statement stipulation can never be
// satisfied by an upload, and underwriters clear it by hand in the notes
// field. That is why loans.notes is parsed by a report (see
// docs/DATA_DICTIONARY.md).
// ---------------------------------------------------------------------------

if (!defined('DOC_KIND_ID')) {
    define('DOC_KIND_ID', 'id');
    define('DOC_KIND_INCOME', 'income');
    define('DOC_KIND_TITLE', 'title');          // auto only
    define('DOC_KIND_INSURANCE', 'insurance');  // auto only
    define('DOC_KIND_ESTIMATE', 'estimate');    // home improvement only
    define('DOC_KIND_OTHER', 'other');          // ~60% of all uploads
}

if (!function_exists('doc_kinds_all')) {
    function doc_kinds_all() {
        return array(
            DOC_KIND_ID,
            DOC_KIND_INCOME,
            DOC_KIND_TITLE,
            DOC_KIND_INSURANCE,
            DOC_KIND_ESTIMATE,
            DOC_KIND_OTHER,
        );
    }
}

if (!function_exists('doc_kind_label')) {
    function doc_kind_label($kind) {
        switch ($kind) {
            case DOC_KIND_ID:        return 'Photo ID';
            case DOC_KIND_INCOME:    return 'Proof of income';
            case DOC_KIND_TITLE:     return 'Vehicle title';
            case DOC_KIND_INSURANCE: return 'Insurance binder';
            case DOC_KIND_ESTIMATE:  return 'Contractor estimate';
            case DOC_KIND_OTHER:     return 'Other';
        }
        // Falls through for anything the upload form sends that isn't in the
        // list. The upload form's <select> is hardcoded in apply_step3.php and
        // includes two kinds ('w2', 'bank') that are not defined here.
        return 'Uploaded document';
    }
}

if (!function_exists('doc_kind_is_valid')) {
    // Not called from anywhere. Written 2021, presumably intended to gate the
    // upload. apply_step3.php does not call it.
    function doc_kind_is_valid($kind) {
        return in_array($kind, doc_kinds_all());
    }
}
