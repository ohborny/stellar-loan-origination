-- 007_indexes_proposed.sql
--
-- 2024. bwc-contractor (Bluewater Consulting).
--
-- ============================================================
-- NEVER APPLIED. TO ANY ENVIRONMENT.
-- ============================================================
-- Bluewater profiled the slow screens during their 2024 engagement and
-- produced this file. It is the only artifact of that engagement that
-- anyone on the current team considers actionable.
--
-- It went to a DBA review in September 2024. The review approved the
-- indexes on their merits and asked for a maintenance window, on the
-- grounds that building an index on `loans` and `audit_log` would lock
-- writes for "some minutes" against tables that had never been vacuumed
-- and had accumulated every abandoned application since 2020 (because
-- batch/stale_app_purge.pl has been disabled since LOAN-1440).
--
-- The maintenance window was never scheduled. It was raised in the
-- change-management meeting three times in late 2024, deferred each
-- time behind the dealer-network work, and then the Bluewater contract
-- ended mid-engagement (LOAN-3002) and nobody was left who owned it.
--
-- The slow queries are all still slow. Every one of the comments below
-- describes a screen that people wait on today.
--
-- If you are the person who finally applies this: it is safe (CREATE
-- INDEX IF NOT EXISTS only, no data changes), but do it off-hours,
-- because of the table sizes described above.

-- The underwriter queue in public/admin.php joins loans to applicants
-- and orders by loans.id DESC with no WHERE clause at all -- it pulls
-- every loan ever created, on every page load, and paginates in PHP.
-- The index helps the join; the missing WHERE is a code problem.
CREATE INDEX IF NOT EXISTS idx_loans_applicant_id ON loans (applicant_id);

-- Status filtering: funding_extract.pl selects WHERE status='APPROVED',
-- the queue filters by status, stale_app_purge.pl (disabled) filters by
-- a status IN list. All full scans today.
CREATE INDEX IF NOT EXISTS idx_loans_status ON loans (status);

-- Date-range reporting. Note that created_at is a TEXT ISO string in
-- server local time (LOAN-2811), so range queries against it are
-- lexicographic and subtly wrong across DST boundaries. The index makes
-- the wrong answer arrive faster.
CREATE INDEX IF NOT EXISTS idx_loans_created_at ON loans (created_at);

-- Applicant lookup by name is a LIKE '%...%' in the search box, which
-- this index cannot help. Included anyway because the DBA asked for it
-- and arguing seemed unproductive.
CREATE INDEX IF NOT EXISTS idx_applicants_name ON applicants (name);

-- Document list on the loan detail screen. Currently a full scan of
-- documents on every page load.
CREATE INDEX IF NOT EXISTS idx_documents_loan_id ON documents (loan_id);

-- NOT a unique index, deliberately. UNIQUE(loan_id, doc_number) is what
-- would actually fix LOAN-2604, and it was rejected in 2021 because it
-- would turn the existing silent collisions into visible upload
-- failures. This one just makes the read fast.
CREATE INDEX IF NOT EXISTS idx_documents_loan_docnum ON documents (loan_id, doc_number);

-- Audit lookups. audit_log has no index at all and is the single
-- largest table in the database.
CREATE INDEX IF NOT EXISTS idx_audit_log_entity ON audit_log (entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_audit_log_ts ON audit_log (ts);

-- Partner reprocessing screen filters on status, then dealer_code.
CREATE INDEX IF NOT EXISTS idx_partner_submissions_status ON partner_submissions (status);
CREATE INDEX IF NOT EXISTS idx_partner_submissions_dealer ON partner_submissions (dealer_code);

-- Bureau cache check. The check itself is unreliable (see
-- 006_partner_2023.sql) but it runs on every application.
CREATE INDEX IF NOT EXISTS idx_bureau_pulls_applicant ON bureau_pulls (applicant_id, pulled_at);

-- apr_variance is append-only and never read, so an index on it is
-- pure write overhead. Bluewater included it for completeness. If you
-- apply this file, consider not applying this line.
CREATE INDEX IF NOT EXISTS idx_apr_variance_loan_id ON apr_variance (loan_id);
