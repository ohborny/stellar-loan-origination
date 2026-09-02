-- 004_tier_d_pilot.sql
--
-- 2017. jchen (schema) / dkirkendall (the seed rows).
--
-- Adds rate_history and seeds the Tier D rates for the "subprime pilot
-- program". The pilot ran from 2017 to its official end in 2018. The
-- code path was never removed and Tier D loans are still being
-- originated (docs/KNOWN_ISSUES.md item #5, LOAN-1502, open and
-- unassigned since 2018).
--
-- ============================================================
-- 003 IS MISSING. THIS IS NOT AN ERROR IN THE NUMBERING.
-- ============================================================
-- There was a 003. It was applied directly to the production MySQL box
-- in late 2016 or early 2017 and never committed to source control.
-- The person who ran it is not with the company.
--
-- What is known: something between 002 and this file added columns
-- that 001_initial_schema.sql (reverse-engineered in 2020) already
-- contains, because they were on the box by then. So parts of 003 are
-- silently folded into 001. Which parts is unknown.
--
-- Guesses that have been floated over the years, none confirmed:
--   * added loans.notes (the app started writing it around then)
--   * added applicants.credit_score, which would mean the original
--     system pulled scores differently
--   * something to do with a "product_code" column that no longer
--     exists anywhere
--
-- Practical consequence: a fresh database built by running these files
-- in order is NOT identical to production. Nobody knows the diff. Every
-- attempt to build a staging environment from these files has ended
-- with someone hand-patching columns until the app stopped erroring.

CREATE TABLE IF NOT EXISTS rate_history (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    tier           TEXT,
    base_rate      REAL,
    effective_date TEXT,
    -- who typed the number in. Free text; some rows say 'finance email'.
    entered_by     TEXT,
    source_note    TEXT
);

-- ============================================================
-- rate_history is WRITE-ONLY AND MOSTLY UNWRITTEN.
--
-- It was created so rate changes would have an audit trail. Nothing in
-- the application inserts into it. The rates that the application
-- actually uses are hardcoded constants in public/apply.php,
-- public/admin.php, batch/nightly_reconcile.pl, tools/reprice_loan.php,
-- and (since 2024) conf/rates.xml + lib/Pricing/RateEngine.php.
--
-- These seed rows are therefore the complete history: five rows from
-- 2017, and nothing since, despite the hardcoded rates having been
-- edited by hand at least four times (2019, 2021, 2023 twice).
-- ============================================================

INSERT INTO rate_history (tier, base_rate, effective_date, entered_by, source_note)
    VALUES ('A', 0.0649, '2017-01-02', 'dkirkendall', 'from finance email 2016-12-19');
INSERT INTO rate_history (tier, base_rate, effective_date, entered_by, source_note)
    VALUES ('B', 0.0899, '2017-01-02', 'dkirkendall', 'from finance email 2016-12-19');
INSERT INTO rate_history (tier, base_rate, effective_date, entered_by, source_note)
    VALUES ('C', 0.1249, '2017-01-02', 'dkirkendall', 'from finance email 2016-12-19');
INSERT INTO rate_history (tier, base_rate, effective_date, entered_by, source_note)
    VALUES ('D', 0.1899, '2017-03-13', 'dkirkendall', 'subprime pilot, per risk. pilot ends 2018-03 -- REMOVE AFTER');
-- The 0.9999 default branch. Seeded so somebody looking at this table
-- would understand where 99.99% APRs come from. It made it worse.
INSERT INTO rate_history (tier, base_rate, effective_date, entered_by, source_note)
    VALUES ('DECLINE', 0.9999, '2017-03-13', 'dkirkendall', 'not a real rate, this is the switch default. do not change');
