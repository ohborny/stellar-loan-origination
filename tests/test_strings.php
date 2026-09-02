<?php
// tests/test_strings.php
//
// Tests for lib/Support/strings.php.
//
// Written during the 2024-02 "let's get some tests in" spike (bwc-contractor
// pairing with avaldez). There is no PHPUnit because there is no composer
// install on any box that runs this, so the harness is the four lines below.
//
// Run it:
//     php tests/test_strings.php
//
// Exit code is 0 when everything passes, 1 otherwise, so it can go in a
// pipeline if there is ever a pipeline.
//
// These assert what the functions DO, not what they should do. Several of
// the expected values below are bugs -- normalize_name() mangling "O'Brien"
// into "O'brien", slugify() eating the file extension. They are asserted
// deliberately, because if somebody fixes them we want to know, and because
// the mangled values are what is in the database.

require_once __DIR__ . '/../lib/Support/strings.php';

$TESTS_RUN    = 0;
$TESTS_FAILED = 0;

/**
 * The entire test framework.
 */
function assert_eq($expected, $actual, $label) {
    global $TESTS_RUN, $TESTS_FAILED;
    $TESTS_RUN++;
    if ($expected === $actual) {
        echo "  ok   " . $label . "\n";
        return true;
    }
    $TESTS_FAILED++;
    echo "  FAIL " . $label . "\n";
    echo "         expected: " . var_export($expected, true) . "\n";
    echo "         actual:   " . var_export($actual, true) . "\n";
    return false;
}

echo "test_strings.php\n";
echo "----------------\n";

// ---------------------------------------------------------------------
// trunc30() -- the MySQL VARCHAR(30) that stopped existing in 2020
// ---------------------------------------------------------------------
assert_eq(
    'Alice Nakamura',
    trunc30('Alice Nakamura'),
    'trunc30 leaves a short name alone'
);

assert_eq(
    30,
    strlen(trunc30('Bartholomew Fitzwilliam-Harrington III')),
    'trunc30 cuts a long name to exactly 30 characters'
);

// ---------------------------------------------------------------------
// trunc_display() -- display-only truncation, added 2019 after somebody
// used trunc30() on the notes column
// ---------------------------------------------------------------------
assert_eq(
    'short note',
    trunc_display('short note', 60),
    'trunc_display leaves short input alone'
);

assert_eq(
    'xxxxxxx...',
    trunc_display(str_repeat('x', 40), 10),
    'trunc_display appends an ellipsis inside the limit'
);

// ---------------------------------------------------------------------
// normalize_name() -- collapses whitespace, then lower-cases and
// title-cases. Both assertions below are the mangled output. That is the
// point; the mangled value is what gets stored.
// ---------------------------------------------------------------------
assert_eq(
    'John Smith',
    normalize_name("  john    smith \n"),
    'normalize_name collapses whitespace and title-cases'
);

assert_eq(
    'Mcdonald',
    normalize_name('MCDONALD'),
    'normalize_name mangles MCDONALD (known, asserted deliberately)'
);

// ---------------------------------------------------------------------
// mask_ssn() -- screens only. Not applied to audit_log, error_log, or
// partner_submissions.raw_xml.
// ---------------------------------------------------------------------
assert_eq(
    'XXX-XX-6789',
    mask_ssn('123-45-6789'),
    'mask_ssn keeps the last four'
);

assert_eq(
    'XXX-XX-____',
    mask_ssn(''),
    'mask_ssn on empty input'
);

// ---------------------------------------------------------------------
// slugify() -- strips the dot too, which is why stored documents have no
// extension (see lib/Support/doc_store.php)
// ---------------------------------------------------------------------
assert_eq(
    'statement-pdf',
    slugify('Statement.pdf'),
    'slugify eats the file extension (known, asserted deliberately)'
);

// ---------------------------------------------------------------------
// clean_input() (2015) vs sanitize_input() (2021). Both live. They are
// nearly the same function and the difference below is why the same
// applicant name can be stored two different ways depending on which
// code path wrote it.
// ---------------------------------------------------------------------
assert_eq(
    'hello',
    clean_input('  <b>hello</b>  '),
    'clean_input trims and strips tags'
);

assert_eq(
    "O'Brien",
    clean_input("O'Brien"),
    'clean_input leaves an apostrophe alone (and so does nothing for SQL)'
);

assert_eq(
    'O&#039;Brien',
    sanitize_input("O'Brien"),
    'sanitize_input HTML-escapes the apostrophe before it reaches the DB'
);

// ---------------------------------------------------------------------
// fmt_reason_code() -- does not validate the three-letters-and-a-digit
// convention it documents
// ---------------------------------------------------------------------
assert_eq(
    'RC01',
    fmt_reason_code('  rc01 '),
    'fmt_reason_code upper-cases and trims'
);

echo "----------------\n";
echo $TESTS_RUN . " assertions, " . $TESTS_FAILED . " failed\n";
exit($TESTS_FAILED === 0 ? 0 : 1);
