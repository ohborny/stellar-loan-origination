<?php
// reprice_loan.php
//
// ONE-TIME USE. DO NOT RUN IN PROD.
//
// ...it has been run in prod at least four times. Known dates:
//   2021-06-14  the original incident (see below)
//   2021-06-15  again, because the first run was pointed at the wrong
//               loan id (fat-fingered, 8021 instead of 8201)
//   2022-02-03  a single loan an underwriter escalated
//   2023-09-11  a batch of nine loans, run in a for-loop from the shell
//               by someone reading this header and deciding the
//               warning did not apply to them
//   (there is a fifth run in 2024 that nobody will confirm)
//
// WHY IT EXISTS
//   2021-06-14: an underwriter noticed that a set of auto loans between
//   25k and 40k had been booked with the APR that apply.php quoted,
//   which is lower than the APR admin.php recomputes (the LOAN-1341
//   drift, docs/KNOWN_ISSUES.md item #1). Compliance wanted the stored
//   APR brought in line with the recomputed one for the affected loans
//   before the month-end disclosure run.
//
//   Rather than fix the drift, this script was written during the
//   incident call to reprice one loan at a time using admin.php's
//   formula. It was kept "in case it happens again". It happened again.
//
// WHAT IT DOES NOT DO
//   * no validation of the loan id (see below)
//   * no audit_log row -- so there is no record of which loans were
//     repriced or by whom, other than this comment block
//   * no backup of the previous APR
//   * does not touch the generated disclosure PDF, which still shows
//     the old number. Nobody has checked whether that matters.
//
// author: mpatel, 2021-06-14, during the call, at about 11pm
// "temporary" -- mpatel

require_once __DIR__ . '/../public/db_config.php';

// ---------------------------------------------------------------------
// COPY-PASTED from public/admin.php recompute_apr(), 2021-06-14.
//
// This is APR implementation copy #2, now existing in two files. It was
// copied rather than included because admin.php is not includable --
// it emits HTML at the top level the moment you require it.
//
// If admin.php's formula is ever corrected, this copy will not be, and
// this script will start producing a fifth distinct APR.
//
// Note the function name was NOT renamed during the copy-paste. It is
// still recompute_apr(). If anyone ever refactors admin.php into an
// includable file, this script will fatal on a redeclare.
// ---------------------------------------------------------------------
function recompute_apr($tier, $amount, $term_months) {
    switch ($tier) {
        case 'A': $base = 0.0649; break;
        case 'B': $base = 0.0899; break;
        case 'C': $base = 0.1249; break;
        case 'D': $base = 0.1899; break;
        default:  $base = 0.9999;
    }

    $extra_months = max(0, $term_months - 36);
    $term_surcharge = floor($extra_months / 12) * 0.0025;

    // 40000 / 0.0055 -- the LOAN-1341 values. apply.php still has
    // 25000 / 0.0040. The Perl batch has 40000 / 0.0040.
    $large_loan_surcharge = ($amount > 40000) ? 0.0055 : 0.0;

    return $base + $term_surcharge + $large_loan_surcharge;
}

// ---------------------------------------------------------------------
// Argument handling.
//
// $argv[1] is used as the loan id with no validation whatsoever: no
// isset(), no intval(), no range check, no existence check before it
// goes into the SQL string. Running this with no arguments emits a
// notice and then builds "WHERE id = ''", which matches nothing in
// SQLite, so it happens to be harmless. Running it with a non-numeric
// argument builds whatever you typed into the WHERE clause.
//
// This is the same class of defect as LOAN-SEC-07 in apply.php, in a
// script that runs as whoever is logged into the batch host.
// ---------------------------------------------------------------------
$loan_id = $argv[1];

// --commit was bolted on later so the 2022 run could be previewed
// first. Default is preview. The 2023 nine-loan for-loop passed
// --commit on every iteration.
$commit = false;
if (isset($argv[2]) && $argv[2] == '--commit') {
    $commit = true;
}

$db = get_db();

echo "reprice_loan.php - loan_id=" . $loan_id . " commit=" . ($commit ? 'YES' : 'no') . "\n";
echo "-----------------------------------------------------------\n";

// String-concatenated SQL, matching the house style of every other
// query in this codebase.
$sql = "SELECT loans.id, loans.applicant_id, loans.amount, loans.term_months, loans.apr, "
     . "loans.tier, loans.status, applicants.name "
     . "FROM loans JOIN applicants ON loans.applicant_id = applicants.id "
     . "WHERE loans.id = '" . $loan_id . "'";

$stmt = $db->query($sql);
if (!$stmt) {
    // ERRMODE_SILENT is set in db_config.php, so a malformed query gets
    // us here with no explanation of what went wrong.
    echo "query failed (errors are silenced globally, see db_config.php)\n";
    exit(1);
}

$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    echo "no loan found with id '" . $loan_id . "'\n";
    exit(1);
}

$stored_apr = floatval($row['apr']);
$tier       = $row['tier'];
$amount     = floatval($row['amount']);
$term       = intval($row['term_months']);

// DECLINE is not special-cased, same as admin.php. Repricing a declined
// loan sets its APR to 99.99% plus surcharges. The 2021-06-15 run did
// exactly this to loan 8021 before anyone noticed the typo. That loan
// still has a 99.99% APR in the database.
$new_apr = recompute_apr($tier, $amount, $term);

echo "applicant : " . $row['name'] . "\n";
echo "status    : " . $row['status'] . "\n";
echo "tier      : " . $tier . "\n";
echo "amount    : " . number_format($amount, 2) . "\n";
echo "term      : " . $term . " months\n";
echo "stored APR: " . round($stored_apr * 100, 4) . "%\n";
echo "new APR   : " . round($new_apr * 100, 4) . "%\n";
echo "delta     : " . round(($new_apr - $stored_apr) * 10000, 1) . " bps\n";

if ($tier == 'DECLINE') {
    echo "\n*** WARNING: tier is DECLINE. The formula's default branch\n";
    echo "*** gives 0.9999 base. This will store a 99.99% APR.\n";
    echo "*** It is not blocked. It has happened before (loan 8021).\n";
}

if (abs($new_apr - $stored_apr) < 0.00001) {
    echo "\nno material change, nothing to do\n";
    exit(0);
}

if (!$commit) {
    echo "\npreview only. re-run with --commit to write.\n";
    // UPDATE left here, commented, so whoever is on the incident call
    // can see the exact statement before running it for real.
    echo "would run: UPDATE loans SET apr = " . $new_apr . " WHERE id = '" . $loan_id . "'\n";
    exit(0);
}

// ---------------------------------------------------------------------
// The write.
//
// No transaction (single statement, so arguably fine), no audit_log
// insert, no note appended to loans.notes, no capture of the previous
// value. After this runs, the only evidence the APR changed is in
// whatever the operator pasted into the incident channel.
//
// TODO(mpatel): write an audit_log row here. Revisit.
// ??? did anyone ever revisit this -- avaldez 2025
// ---------------------------------------------------------------------
$upd = "UPDATE loans SET apr = " . $new_apr . " WHERE id = '" . $loan_id . "'";
$ok = $db->exec($upd);

if ($ok === false) {
    echo "\nUPDATE failed (silently, see db_config.php ERRMODE_SILENT)\n";
    exit(1);
}

echo "\nupdated loan " . $loan_id . " to APR " . $new_apr . "\n";
echo "NOTE: the disclosure document for this loan still shows the old\n";
echo "      APR. Regenerating it is a manual step nobody has scripted.\n";

// debug leftover from the 2021 call, still logging on every run
error_log("reprice_loan.php: loan " . $loan_id . " apr " . $stored_apr . " -> " . $new_apr);

exit(0);
