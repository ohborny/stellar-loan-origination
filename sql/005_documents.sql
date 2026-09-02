-- 005_documents.sql
--
-- 2018. mpatel.
--
-- Adds document storage metadata and the underwriter override trail.
--
-- Both tables were added for a compliance ask ("we need to show who
-- overrode a decision and what documents were on file"). The override
-- table is populated. The documents table is populated inconsistently,
-- because the upload path in public/ writes a row before the file is
-- moved into place and does not roll back if the move fails.

CREATE TABLE IF NOT EXISTS documents (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    loan_id      INTEGER,
    -- ============================================================
    -- doc_number IS GENERATED IN APPLICATION CODE AS
    --     SELECT MAX(doc_number) + 1 FROM documents WHERE loan_id = ?
    -- with no lock, no UNIQUE constraint, and no transaction around the
    -- read and the insert. Two uploads on the same loan within the same
    -- second get the same doc_number. That is LOAN-2604, open.
    --
    -- Adding a UNIQUE(loan_id, doc_number) index would surface the
    -- collisions as hard errors instead of duplicates. That was
    -- proposed and rejected in 2021 on the grounds that it "would break
    -- uploads", which is true and also the point.
    -- ============================================================
    doc_number   INTEGER,
    -- 'ID', 'INCOME', 'DISCLOSURE', 'OTHER'. Free text. The UI dropdown
    -- offers four values; the partner path writes whatever the dealer
    -- put in the XML.
    kind         TEXT,
    -- Filename on disk, relative to a path that is configured in
    -- conf/loanapp.ini and also hardcoded in two PHP files with a
    -- different value. Some rows point at the 2018 path, some at the
    -- 2021 path. There is no way to tell which without trying both.
    filename     TEXT,
    bytes        INTEGER,
    uploaded_by  TEXT,
    uploaded_at  TEXT
);

CREATE TABLE IF NOT EXISTS decision_overrides (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    loan_id      INTEGER,
    from_tier    TEXT,
    to_tier      TEXT,
    -- Reason codes come from a list in a 2018 email. The list is not in
    -- the repo. Observed values include 'MANUAL', 'POLICY', 'EXCEPTION',
    -- 'OTHER', '4', and ''. Nothing validates them.
    reason_code  TEXT,
    override_by  TEXT,
    override_at  TEXT
);

-- Note: overriding a tier writes a row here but does NOT recompute or
-- rewrite loans.apr. So an override from C to A leaves the loan priced
-- at the Tier C rate. Whether that is intended has never been
-- established; Loan Ops handles it by asking someone to run
-- tools/reprice_loan.php, which uses admin.php's formula and therefore
-- produces a number apply.php would never have quoted.

-- No index on documents.loan_id. The loan detail screen scans the whole
-- table for every page load. See 007_indexes_proposed.sql.
