-- 001_initial_schema.sql
--
-- LoanApp initial schema. Nominally 2013, authored by rwhitfield
-- (Halbrook Systems Group) as part of the original build.
--
-- ============================================================
-- PROVENANCE WARNING
-- ============================================================
-- This file was NOT written in 2013. It was reverse-engineered in
-- August 2020 from the live MySQL box (10.14.22.9) by running SHOW
-- CREATE TABLE and hand-translating the output to SQLite, at the point
-- where the repository was migrated off the old SVN instance.
--
-- The original DDL, if it was ever committed anywhere, was in that SVN
-- repo. The SVN server was decommissioned before anyone exported the
-- history, so there is no way to check this against what was actually
-- deployed in 2013. Seven years of undocumented ALTERs had been applied
-- to that box by hand.
--
-- So: this describes the schema as it existed in 2020, backdated and
-- labelled 001. It may include columns added years after 2013, and it
-- may be missing columns that were dropped along the way. Treat it as
-- an approximation.
--
-- ============================================================
-- WHAT IS DELIBERATELY ABSENT
-- ============================================================
--   * No indexes beyond the primary keys. The original box had none
--     either (confirmed from SHOW INDEX output at the time). Proposed
--     indexes live in 007_indexes_proposed.sql, never applied.
--   * No foreign keys. SQLite does not enforce them by default anyway
--     and the application never enables PRAGMA foreign_keys. So
--     loans.applicant_id can and does point at applicant rows that no
--     longer exist (see LOAN-1440 and tools/import_legacy_csv.php).
--   * No NOT NULL on anything the application "always sets". It does
--     not always set them.
--   * No CHECK constraints. Underwriting policy lives in PHP.

CREATE TABLE IF NOT EXISTS applicants (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    -- TEXT, unbounded. The old MySQL column was VARCHAR(30), and two
    -- separate code paths still truncate to 30 to match a constraint
    -- that stopped existing in 2020 (tools/import_legacy_csv.php and
    -- the partner XML mapper). Silent data loss with no live cause.
    name            TEXT,
    -- last four digits, stored as text so leading zeros survive. It is
    -- stored in the clear. Flagged in the 2021 pen test as part of
    -- LOAN-SEC-07's write-up; risk-accepted as "not full SSN".
    ssn_last4       TEXT,
    annual_income   REAL,
    credit_score    INTEGER,
    -- named "existing_debt" but the application form labels it
    -- "existing monthly debt (annualized)" and calculate_dti() divides
    -- it straight by annual_income. Whether the number in here is
    -- monthly or annual depends on what the applicant typed.
    existing_debt   REAL,
    -- ISO-8601 string from PHP date('c'), in SERVER LOCAL TIME with no
    -- normalization. The Perl batch layer reads these as UTC. LOAN-2811.
    created_at      TEXT
);

CREATE TABLE IF NOT EXISTS loans (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    -- no FK to applicants(id). intentional in the sense that nobody
    -- ever added one.
    applicant_id    INTEGER,
    amount          REAL,
    term_months     INTEGER,
    -- The APR as computed at application time by tier_to_apr() in
    -- public/apply.php. This is the number on the signed disclosure, so
    -- it is a system of record. admin.php recomputes a DIFFERENT number
    -- on screen and does not write it here. The Perl batch computes a
    -- third and writes the difference to apr_variance.
    apr             REAL,
    -- 'A','B','C','D', or the literal string 'DECLINE'. Not an enum,
    -- not constrained. Import tooling writes ''. Anything that reads
    -- this has to handle four cases and most handle two.
    tier            TEXT,
    -- Free text. Observed values across the table's life include
    -- APPROVED, DECLINED, IN_REVIEW, PENDING_DOCS, FUNDED, CLOSED,
    -- IMPORTED, and (from a 2017 script that no longer exists) 'ok'.
    status          TEXT,
    notes           TEXT,
    created_at      TEXT
);

-- rwhitfield's original seed row, still in the 2020 dump we
-- reverse-engineered this from. Left commented; re-inserting it breaks
-- the demo's row numbering.
-- INSERT INTO applicants (name, ssn_last4, annual_income, credit_score, existing_debt, created_at)
--   VALUES ('TEST TEST', '0000', 50000, 700, 10000, '2013-04-02T09:00:00');
