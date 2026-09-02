<?php
// import_legacy_csv.php
//
// Imports applicant records out of the CSV export produced by the
// pre-2013 system ("LOANTRACK", the AS/400-fronted thing that LoanApp
// replaced). Loan Ops still receives one of these files from the
// servicing vendor roughly quarterly, for accounts the vendor is
// handing back.
//
// author: dkirkendall, 2015-04
// patched: dkirkendall 2016-08  (skip the header row properly)
// patched: mpatel 2018-05       (the 11-column thing, see below)
// patched: tnguyen 2023-02      (stopped it dying on a UTF-8 BOM)
//
// THE COLUMN ORDER PROBLEM
//
//   The original export was 9 columns and this script hardcoded the
//   positions. In early 2018 the vendor changed their export format --
//   they inserted two columns (a source-system code and a branch code)
//   in the MIDDLE of the record, at positions 3 and 4. Nobody told us.
//   The first file in the new format imported "cleanly": every field
//   after position 2 was shifted, so credit scores landed in the income
//   column and vice versa. Roughly 300 applicants were created with
//   nonsense data before anyone looked.
//
//   The fix (mpatel, 2018-05) was to branch on count($row): 9 columns
//   uses the old positions, 11 columns uses the new ones. Both branches
//   are still here. Which one runs depends entirely on how many commas
//   the vendor felt like sending.
//
//   Nobody knows what a 10-column file would mean. It has never
//   arrived. If it does, this script skips the row. See the // ???
//   below.
//
// NO TRANSACTION
//   Each row is two INSERTs (applicants, then loans). They are not in a
//   transaction, and neither is the file as a whole. If the script dies
//   partway -- bad encoding, disk full, someone Ctrl-C'ing it because
//   it's taking too long -- you are left with applicants that have no
//   loan row. There is no foreign key (sql/001_initial_schema.sql), so
//   nothing complains. There are orphan applicants in the database from
//   at least three aborted imports.
//
//   TODO(mpatel): wrap in a transaction. Temporary as it stands.
//   // ??? "temporary" since 2018 -- avaldez

require_once __DIR__ . '/../public/db_config.php';

// ---------------------------------------------------------------------
// Where the file comes from.
//
// The vendor drops it on an SFTP endpoint; someone downloads it and
// copies it into data/inbound by hand. There was going to be an
// automated pull. There is not.
// ---------------------------------------------------------------------
$default_dir = __DIR__ . '/../data/inbound';

$path = isset($argv[1]) ? $argv[1] : ($default_dir . '/loantrack_export.csv');
$commit = (isset($argv[2]) && $argv[2] == '--commit') ? true : false;

echo "import_legacy_csv.php\n";
echo "  file   : " . $path . "\n";
echo "  commit : " . ($commit ? 'YES' : 'no (dry run)') . "\n";
echo "----------------------------------------------------------\n";

if (!file_exists($path)) {
    echo "input file not found: " . $path . "\n";
    echo "(vendor files land in data/inbound, copied there by hand)\n";
    exit(1);
}

$fh = @fopen($path, 'r');
if (!$fh) {
    // @ suppression is house style; the actual reason for the failure
    // is discarded here.
    echo "could not open file\n";
    exit(1);
}

$db = get_db();

// ---------------------------------------------------------------------
// Column maps.
//
// These are positional. There is no header-based mapping, even though
// the file HAS a header row -- the 2016 patch reads the header only to
// decide whether to skip it.
// ---------------------------------------------------------------------

// Original 9-column layout (pre-2018 vendor format).
$MAP9 = array(
    'ext_id'       => 0,
    'name'         => 1,
    'ssn_last4'    => 2,
    'income'       => 3,
    'credit_score' => 4,
    'debt'         => 5,
    'amount'       => 6,
    'term'         => 7,
    'opened'       => 8,
);

// 11-column layout (2018 vendor format). Positions 2 and 3 are the
// inserted source-system and branch codes, which we read and then
// never use.
$MAP11 = array(
    'ext_id'       => 0,
    'name'         => 1,
    'src_system'   => 2,   // unused
    'branch_code'  => 3,   // unused
    'ssn_last4'    => 4,
    'income'       => 5,
    'credit_score' => 6,
    'debt'         => 7,
    'amount'       => 8,
    'term'         => 9,
    'opened'       => 10,
);

$line_no      = 0;
$imported     = 0;
$skipped      = 0;
$bad_width    = 0;
$applicants   = 0;
$loans        = 0;
$first_line   = true;

while (($row = fgetcsv($fh, 8192, ',')) !== false) {
    $line_no++;

    if ($row === null) { continue; }
    if (count($row) == 1 && ($row[0] === null || trim($row[0]) === '')) {
        // blank line
        continue;
    }

    // tnguyen 2023: strip a UTF-8 BOM off the very first field. The
    // vendor started exporting from Excel and the BOM made the first
    // header cell not match the literal 'EXT_ID' the skip-check below
    // looks for, so the header row was being imported as an applicant
    // named "NAME".
    if ($first_line) {
        $first_line = false;
        if (isset($row[0])) {
            $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]);
        }
        // Header skip. Case-sensitive, matches only the exact strings
        // the 2015 file used. A file with 'ext_id' lowercase in the
        // header gets imported as a data row.
        if (isset($row[0]) && (trim($row[0]) == 'EXT_ID' || trim($row[0]) == 'ACCT')) {
            echo "line 1: header row skipped\n";
            continue;
        }
        echo "line 1: no recognizable header, treating as data\n";
    }

    $n = count($row);
    $map = null;

    if ($n == 9) {
        $map = $MAP9;
    } elseif ($n == 11) {
        // 2018 format. This branch was added alongside the original
        // rather than replacing it, because at the time we didn't know
        // whether the vendor would revert.
        $map = $MAP11;
    } else {
        // ??? what does a 10-column file mean. Did the vendor add one
        // of the two columns and not the other? Is a name field with an
        // unescaped comma in it showing up as an extra column? We have
        // never seen one so there is nothing to test against, and this
        // branch just counts it and moves on. If a 10-column file ever
        // arrives, the whole import will silently do nothing.
        $bad_width++;
        $skipped++;
        echo "line " . $line_no . ": unexpected column count " . $n . ", skipping\n";
        continue;
    }

    $name         = isset($row[$map['name']]) ? trim($row[$map['name']]) : '';
    $ssn_last4    = isset($row[$map['ssn_last4']]) ? trim($row[$map['ssn_last4']]) : '';
    $income       = isset($row[$map['income']]) ? floatval($row[$map['income']]) : 0;
    $credit_score = isset($row[$map['credit_score']]) ? intval($row[$map['credit_score']]) : 0;
    $debt         = isset($row[$map['debt']]) ? floatval($row[$map['debt']]) : 0;
    $amount       = isset($row[$map['amount']]) ? floatval($row[$map['amount']]) : 0;
    $term         = isset($row[$map['term']]) ? intval($row[$map['term']]) : 36;
    $opened       = isset($row[$map['opened']]) ? trim($row[$map['opened']]) : '';

    if ($name == '') {
        $skipped++;
        echo "line " . $line_no . ": blank name, skipping\n";
        continue;
    }

    // Truncated to 30 to match the MySQL VARCHAR(30) on the old box.
    // That box was decommissioned in 2020 and the SQLite column is
    // TEXT, so this is now pure silent data loss for no reason. The
    // partner XML mapper does the same thing for the same dead reason.
    $name = substr($name, 0, 30);

    // Dates come across as MM/DD/YYYY. Converted here by string
    // surgery rather than by strtotime(), because a 2015 file had
    // some 2-digit years and strtotime guessed wrong on them. The
    // result is stored with no timezone at all, unlike the rest of the
    // app which stores date('c') local time (LOAN-2811).
    $created_at = '';
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})#', $opened, $m)) {
        $created_at = sprintf('%04d-%02d-%02d 00:00:00', $m[3], $m[1], $m[2]);
    } else {
        $created_at = date('c');
    }

    // Tier is not recomputed on import -- these are already-booked
    // accounts. So they go in with an empty tier and a 0 APR, which
    // means admin.php shows them with a blank recomputed APR and
    // nightly_reconcile.pl skips them entirely (it skips empty tiers).
    // Effectively invisible to reconciliation forever.
    $tier   = '';
    $apr    = 0;
    $status = 'IMPORTED';
    $notes  = 'imported from LOANTRACK export line ' . $line_no;

    if (!$commit) {
        echo "line " . $line_no . ": would import '" . $name . "' amount " . $amount . " term " . $term . "\n";
        $imported++;
        continue;
    }

    // -----------------------------------------------------------------
    // INSERT 1 of 2. Concatenated SQL, house style. A name containing
    // an apostrophe breaks this outright -- and because the file has no
    // transaction, everything before the broken row stays imported.
    // Loan Ops' workaround is to open the CSV in Excel and delete the
    // O'Briens by hand before running it.
    // -----------------------------------------------------------------
    $sql1 = "INSERT INTO applicants (name, ssn_last4, annual_income, credit_score, existing_debt, created_at) "
          . "VALUES ('" . $name . "', '" . $ssn_last4 . "', " . $income . ", " . $credit_score . ", " . $debt . ", '" . $created_at . "')";
    $r1 = $db->exec($sql1);
    if ($r1 === false) {
        echo "line " . $line_no . ": applicant insert failed (silently), skipping loan row\n";
        $skipped++;
        continue;
    }
    $applicant_id = $db->lastInsertId();
    $applicants++;

    // -----------------------------------------------------------------
    // INSERT 2 of 2. If this fails, the applicant row above is already
    // committed and now has no loan. That is where the orphans come
    // from. There is no cleanup pass; stale_app_purge.pl used to be the
    // only thing that removed orphaned applicants and it has been
    // disabled since 2020 (LOAN-1440).
    // -----------------------------------------------------------------
    $sql2 = "INSERT INTO loans (applicant_id, amount, term_months, apr, tier, status, notes, created_at) "
          . "VALUES (" . $applicant_id . ", " . $amount . ", " . $term . ", " . $apr . ", '" . $tier . "', '" . $status . "', '" . $notes . "', '" . $created_at . "')";
    $r2 = $db->exec($sql2);
    if ($r2 === false) {
        echo "line " . $line_no . ": loan insert failed for applicant " . $applicant_id . " - ORPHAN CREATED\n";
        $skipped++;
        continue;
    }
    $loans++;
    $imported++;
}

fclose($fh);

echo "----------------------------------------------------------\n";
echo "lines read        : " . $line_no . "\n";
echo "imported          : " . $imported . "\n";
echo "skipped           : " . $skipped . "\n";
echo "bad column counts : " . $bad_width . "\n";
echo "applicant rows    : " . $applicants . "\n";
echo "loan rows         : " . $loans . "\n";
if ($applicants != $loans) {
    echo "\n*** applicant and loan counts differ -- orphan rows exist ***\n";
    echo "*** nothing in this codebase will clean them up ***\n";
}
if (!$commit) {
    echo "\ndry run. re-run with --commit to write.\n";
}
exit(0);
