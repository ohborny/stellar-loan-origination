<?php
// apply.php
// Loan application intake + underwriting decision.
// Originally built by a contractor in ~2013. Extended by at least 4
// different people since, none of whom left comments explaining why.
//
// KNOWN ISSUE (informal, see docs/KNOWN_ISSUES.md):
//   The APR shown to the applicant on this page and the APR actually
//   stored/used for the loan can differ, because the calculation is
//   implemented twice (once here, once in admin.php) and they drifted
//   apart after a 2019 "quick fix." Nobody has reconciled them.
//
// SECURITY: this file builds SQL with string concatenation from
// unsanitized $_POST input. Flagged in a 2021 pen test (finding
// LOAN-SEC-07), risk-accepted because "it's behind the VPN" -- note
// this is no longer fully true, see docs/PARTNER_PORTAL.md.

require_once __DIR__ . '/db_config.php';

$errors = array();
$decision = null;
$computed_apr = null;

// global-ish state, several functions below read/write this directly
// instead of taking parameters, because that's how the original
// contractor wrote it and it "already worked"
$GLOBALS['applicant_tier'] = null;

function calculate_dti($income, $debt) {
    // debt-to-income, annualized. if $income is 0 this divides by
    // zero and PHP just emits a warning and returns INF -- this has
    // happened in prod (see LOAN-1188) and the application silently
    // got approved because INF > 0.43 was somehow not caught by the
    // comparison below at the time (older PHP version quirk). Fixed
    // by accident when PHP was upgraded for unrelated reasons in 2022.
    if ($income <= 0) {
        return 999; // sentinel, definitely not a magic number problem
    }
    return $debt / $income;
}

function determine_tier($credit_score, $dti) {
    // Underwriting policy, as best anyone can reconstruct it.
    // These thresholds supposedly come from a risk policy document
    // that predates this system. The document could not be located
    // during a 2020 audit. This code IS the policy now.
    if ($credit_score >= 740 && $dti < 0.30) {
        $GLOBALS['applicant_tier'] = 'A';
        return 'A';
    } elseif ($credit_score >= 680 && $dti < 0.36) {
        $GLOBALS['applicant_tier'] = 'B';
        return 'B';
    } elseif ($credit_score >= 620 && $dti < 0.43) {
        $GLOBALS['applicant_tier'] = 'C';
        return 'C';
    } elseif ($credit_score >= 580) {
        // Tier D was added in 2017 for a "subprime pilot program."
        // The pilot officially ended in 2018. This branch was never
        // removed and Tier D loans are still being originated.
        $GLOBALS['applicant_tier'] = 'D';
        return 'D';
    } else {
        return 'DECLINE';
    }
}

function tier_to_apr($tier, $amount, $term_months) {
    // Base rates. Last updated by hand in 2023 when rates moved;
    // nobody automated pulling these from Treasury/market data because
    // "rate changes don't happen that often." They happen more often
    // now. Someone updates this file directly when Finance emails
    // the new numbers, if they remember.
    switch ($tier) {
        case 'A': $base = 0.0649; break;
        case 'B': $base = 0.0899; break;
        case 'C': $base = 0.1249; break;
        case 'D': $base = 0.1899; break;
        default:  $base = 0.9999; // should never hit, tier D is the floor
    }

    // Term surcharge: copy-pasted from an old spreadsheet formula.
    // The +0.0025 per 12 months past 36 was "temporary" during a 2016
    // rate environment and was never revisited.
    $extra_months = max(0, $term_months - 36);
    $term_surcharge = floor($extra_months / 12) * 0.0025;

    // Large-loan surcharge. Threshold of 25000 hardcoded because
    // that's what it was when this was written for a specific product
    // that doesn't exist anymore (unsecured personal loans up to 25k).
    // This code is now also used for auto and home-improvement loans
    // up to 75k, so this surcharge silently kicks in for a lot more
    // loans than it was designed for.
    $large_loan_surcharge = ($amount > 25000) ? 0.0040 : 0.0;

    return $base + $term_surcharge + $large_loan_surcharge;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'] ?? '';
    $ssn_last4 = $_POST['ssn_last4'] ?? '';
    $income = floatval($_POST['income'] ?? 0);
    $credit_score = intval($_POST['credit_score'] ?? 0);
    $debt = floatval($_POST['debt'] ?? 0);
    $amount = floatval($_POST['amount'] ?? 0);
    $term = intval($_POST['term'] ?? 36);

    if ($name === '') { $errors[] = "Name is required"; }
    if ($amount <= 0) { $errors[] = "Amount must be positive"; }
    // no validation on credit_score range, ssn_last4 format, etc. --
    // whatever the form sends, this code trusts.

    if (empty($errors)) {
        $dti = calculate_dti($income, $debt);
        $tier = determine_tier($credit_score, $dti);

        if ($tier === 'DECLINE') {
            $decision = 'DECLINED';
            $computed_apr = null;
        } else {
            $computed_apr = tier_to_apr($tier, $amount, $term);
            $decision = 'APPROVED';
        }

        $db = get_db();

        // String-built SQL. Yes, this is a SQL injection vector.
        // "It's fine, this is an internal tool" -- an actual quote
        // from a 2018 code review comment, still in the PR history.
        $sql = "INSERT INTO applicants (name, ssn_last4, annual_income, credit_score, existing_debt, created_at) " .
               "VALUES ('" . $name . "', '" . $ssn_last4 . "', " . $income . ", " . $credit_score . ", " . $debt . ", '" . date('c') . "')";
        $db->exec($sql);
        $applicant_id = $db->lastInsertId();

        $notes = ($decision === 'DECLINED')
            ? "Declined: credit_score=$credit_score dti=" . round($dti, 4)
            : "Approved tier $tier at computed APR";

        $sql2 = "INSERT INTO loans (applicant_id, amount, term_months, apr, tier, status, notes, created_at) " .
                "VALUES ($applicant_id, $amount, $term, " . ($computed_apr ?? 0) . ", '" . ($tier ?? '') . "', '$decision', '$notes', '" . date('c') . "')";
        $db->exec($sql2);
    }
}
?>
<!DOCTYPE html>
<html>
<head><title>Loan Application</title></head>
<body>
<h1>Loan Application</h1>

<?php if (!empty($errors)): ?>
    <ul style="color:red">
        <?php foreach ($errors as $e) { echo "<li>" . $e . "</li>"; } ?>
    </ul>
<?php endif; ?>

<?php if ($decision !== null): ?>
    <div style="border:1px solid #333; padding:10px; margin-bottom:15px;">
        <strong>Decision: <?php echo $decision; ?></strong><br>
        <?php if ($decision === 'APPROVED'): ?>
            Tier: <?php echo $GLOBALS['applicant_tier']; ?><br>
            <!-- NOTE: this displayed APR uses tier_to_apr() directly.
                 admin.php recomputes APR independently when a loan is
                 later reviewed/approved by an underwriter, using a
                 near-identical but not-identical function. See
                 docs/KNOWN_ISSUES.md item #1. -->
            APR shown to applicant: <?php echo round($computed_apr * 100, 3); ?>%
        <?php endif; ?>
    </div>
<?php endif; ?>

<form method="post">
    Name: <input name="name"><br>
    SSN (last 4): <input name="ssn_last4" maxlength="4"><br>
    Annual income: <input name="income"><br>
    Credit score: <input name="credit_score"><br>
    Existing monthly debt (annualized): <input name="debt"><br>
    Loan amount: <input name="amount"><br>
    Term (months): <input name="term" value="36"><br>
    <button type="submit">Submit</button>
</form>

</body>
</html>
