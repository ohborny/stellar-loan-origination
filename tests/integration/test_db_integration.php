<?php
// tests/integration/test_db_integration.php
//
// Integration tests for the database layer (includes/db.php) against a
// real SQLite database built from the schema migrations in sql/.
//
// This test exercises the full stack: schema files -> PDO connection ->
// db_query_all / db_query_one / db_insert / db_exec_raw / db_query_raw
// functions from includes/db.php, which in turn use the get_db() factory
// in public/db_config.php.
//
// The demo database (data/loans.db) is backed up before the test and
// restored after, so the test is self-contained and leaves no trace.
//
// Run it:
//     php tests/integration/test_db_integration.php
//
// Exit code is 0 on success, 1 on failure.

// ---------------------------------------------------------------------------
// Harness (same four-line pattern as the unit tests)
// ---------------------------------------------------------------------------

$TESTS_RUN    = 0;
$TESTS_FAILED = 0;

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

function assert_true($cond, $label) {
    global $TESTS_RUN, $TESTS_FAILED;
    $TESTS_RUN++;
    if ($cond) {
        echo "  ok   " . $label . "\n";
        return true;
    }
    $TESTS_FAILED++;
    echo "  FAIL " . $label . "\n";
    return false;
}

echo "test_db_integration.php\n";
echo "----------------------\n";

// ---------------------------------------------------------------------------
// Setup: back up the demo database, build a fresh one from schema
// ---------------------------------------------------------------------------

$repoRoot    = dirname(__DIR__, 2);
$demoDbPath  = $repoRoot . '/data/loans.db';
$backupPath  = $demoDbPath . '.test-backup';

// Back up the demo database so we can restore it after the test
if (file_exists($demoDbPath)) {
    copy($demoDbPath, $backupPath);
}

// Remove the existing database and build a fresh one from schema files
@unlink($demoDbPath);
$setupDb = new PDO('sqlite:' . $demoDbPath);
$setupDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$migrations = glob($repoRoot . '/sql/00*.sql');
sort($migrations);

foreach ($migrations as $sqlFile) {
    $basename = basename($sqlFile);
    // Skip 007_indexes_proposed.sql — never applied in production
    if ($basename === '007_indexes_proposed.sql') continue;
    $sql = file_get_contents($sqlFile);
    $setupDb->exec($sql);
}

// Close the setup connection so get_db() can open a fresh one
$setupDb = null;

// Include the application's DB layer
// db_config.php defines DB_PATH and get_db(); db.php defines the db_* functions
require_once $repoRoot . '/public/db_config.php';
require_once $repoRoot . '/includes/db.php';

// ---------------------------------------------------------------------------
// Test 1: Schema integrity — all expected tables exist
// ---------------------------------------------------------------------------

echo "\n-- Schema integrity --\n";

$tables = db_query_raw("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
$tableNames = array_column($tables, 'name');

assert_true(in_array('applicants', $tableNames), 'applicants table exists');
assert_true(in_array('loans', $tableNames), 'loans table exists');
assert_true(in_array('users', $tableNames), 'users table exists');
assert_true(in_array('audit_log', $tableNames), 'audit_log table exists');
assert_true(in_array('documents', $tableNames), 'documents table exists');
assert_true(in_array('decision_overrides', $tableNames), 'decision_overrides table exists');
assert_true(in_array('partner_submissions', $tableNames), 'partner_submissions table exists');
assert_true(in_array('rate_history', $tableNames), 'rate_history table exists');
assert_true(in_array('apr_variance', $tableNames), 'apr_variance table exists');

// ---------------------------------------------------------------------------
// Test 2: Insert and retrieve an applicant via db_insert + db_query_one
// ---------------------------------------------------------------------------

echo "\n-- Applicant CRUD via db_insert + db_query_one --\n";

$applicantId = db_insert('applicants', array(
    'name'         => 'Test Applicant',
    'ssn_last4'    => '4321',
    'annual_income' => 75000.0,
    'credit_score' => 720,
    'existing_debt' => 15000.0,
    'created_at'   => '2025-09-01T10:00:00',
));

assert_true($applicantId > 0, 'db_insert returns a positive applicant id');

$row = db_query_one(
    'SELECT * FROM applicants WHERE id = ?',
    array($applicantId)
);

assert_eq('Test Applicant', $row['name'], 'applicant name round-trips');
assert_eq('4321', $row['ssn_last4'], 'applicant ssn_last4 round-trips');
assert_eq(75000.0, (float)$row['annual_income'], 'applicant annual_income round-trips');
assert_eq(720, (int)$row['credit_score'], 'applicant credit_score round-trips');

// ---------------------------------------------------------------------------
// Test 3: Insert and retrieve a loan linked to the applicant
// ---------------------------------------------------------------------------

echo "\n-- Loan CRUD via db_insert + db_query_all --\n";

$loanId = db_insert('loans', array(
    'applicant_id' => $applicantId,
    'amount'       => 30000.0,
    'term_months'  => 48,
    'apr'          => 0.0964,
    'tier'         => 'B',
    'status'       => 'IN_REVIEW',
    'notes'        => 'AUTO - test loan',
    'created_at'   => '2025-09-01T10:05:00',
));

assert_true($loanId > 0, 'db_insert returns a positive loan id');

$loans = db_query_all(
    'SELECT * FROM loans WHERE applicant_id = ? AND status = ?',
    array($applicantId, 'IN_REVIEW')
);

assert_eq(1, count($loans), 'one loan found for applicant with IN_REVIEW status');
assert_eq(30000.0, (float)$loans[0]['amount'], 'loan amount round-trips');
assert_eq(48, (int)$loans[0]['term_months'], 'loan term_months round-trips');
assert_eq('B', $loans[0]['tier'], 'loan tier round-trips');

// ---------------------------------------------------------------------------
// Test 4: db_scalar returns a single value
// ---------------------------------------------------------------------------

echo "\n-- db_scalar --\n";

$count = db_scalar('SELECT COUNT(*) FROM loans WHERE tier = ?', array('B'));
assert_eq(1, (int)$count, 'db_scalar returns loan count for tier B');

// ---------------------------------------------------------------------------
// Test 5: db_exec_raw for UPDATE statements
// ---------------------------------------------------------------------------

echo "\n-- db_exec_raw UPDATE --\n";

$affected = db_exec_raw(
    "UPDATE loans SET status = 'APPROVED' WHERE id = " . (int)$loanId
);
assert_eq(1, $affected, 'db_exec_raw updates 1 row');

$updatedRow = db_query_one('SELECT status FROM loans WHERE id = ?', array($loanId));
assert_eq('APPROVED', $updatedRow['status'], 'loan status updated to APPROVED');

// ---------------------------------------------------------------------------
// Test 6: Audit log insertion and retrieval
// ---------------------------------------------------------------------------

echo "\n-- Audit log integration --\n";

db_insert('audit_log', array(
    'actor'       => 'test_runner',
    'action'      => 'STATUS_CHANGE',
    'entity_type' => 'loan',
    'entity_id'   => (int)$loanId,
    'detail'      => 'IN_REVIEW -> APPROVED',
    'ts'          => '2025-09-01T10:10:00',
));

$auditRows = db_query_all(
    'SELECT * FROM audit_log WHERE entity_id = ? ORDER BY id',
    array($loanId)
);
assert_eq(1, count($auditRows), 'one audit log entry for the loan');
assert_eq('STATUS_CHANGE', $auditRows[0]['action'], 'audit action recorded');
assert_eq('test_runner', $auditRows[0]['actor'], 'audit actor recorded');

// ---------------------------------------------------------------------------
// Test 7: Transaction commit — data persists
// ---------------------------------------------------------------------------

echo "\n-- Transaction commit --\n";

db_tx_begin();
db_insert('loans', array(
    'applicant_id' => $applicantId,
    'amount'       => 15000.0,
    'term_months'  => 24,
    'apr'          => 0.0649,
    'tier'         => 'A',
    'status'       => 'PENDING_DOCS',
    'notes'        => 'PERSONAL - committed tx test',
    'created_at'   => '2025-09-01T11:00:00',
));
db_tx_commit();

$pendingLoans = db_query_all(
    'SELECT * FROM loans WHERE status = ? AND tier = ?',
    array('PENDING_DOCS', 'A')
);
assert_eq(1, count($pendingLoans), 'committed transaction persists loan');

// ---------------------------------------------------------------------------
// Test 8: Transaction rollback — data does not persist
// ---------------------------------------------------------------------------

echo "\n-- Transaction rollback --\n";

db_tx_begin();
db_insert('loans', array(
    'applicant_id' => $applicantId,
    'amount'       => 5000.0,
    'term_months'  => 12,
    'apr'          => 0.0649,
    'tier'         => 'A',
    'status'       => 'DECLINED',
    'notes'        => 'PERSONAL - rolled back tx test',
    'created_at'   => '2025-09-01T12:00:00',
));
db_tx_rollback();

$declinedLoans = db_query_all(
    'SELECT * FROM loans WHERE notes = ?',
    array('PERSONAL - rolled back tx test')
);
assert_eq(0, count($declinedLoans), 'rolled back transaction does not persist');

// ---------------------------------------------------------------------------
// Test 9: rate_history seed data from migration 004
// ---------------------------------------------------------------------------

echo "\n-- Migration seed data --\n";

$rateHistory = db_query_all('SELECT tier, base_rate FROM rate_history ORDER BY tier');
assert_eq(5, count($rateHistory), 'rate_history has 5 seed rows from migration 004');
assert_eq('A', $rateHistory[0]['tier'], 'first rate_history tier is A');
assert_eq(0.0649, (float)$rateHistory[0]['base_rate'], 'Tier A base rate is 0.0649');

// ---------------------------------------------------------------------------
// Cleanup: restore the demo database
// ---------------------------------------------------------------------------

// Close the cached PDO connection by unsetting the static var
// get_db() uses a static $conn, so we need to reset it
// The simplest way is to let the script end; the restore happens below

@unlink($demoDbPath);
if (file_exists($backupPath)) {
    rename($backupPath, $demoDbPath);
}

echo "\n----------------------\n";
echo $TESTS_RUN . " assertions, " . $TESTS_FAILED . " failed\n";
exit($TESTS_FAILED === 0 ? 0 : 1);
