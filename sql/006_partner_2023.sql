-- 006_partner_2023.sql
--
-- 2023. tnguyen, with the pw_hash column contributed by soyelaran.
--
-- Everything the dealer partner network needed, plus a couple of tables
-- that were added at the same time because it was the first migration
-- anyone had written in five years and people piled on.
--
-- Written under launch pressure ("unblocking the dealer launch").
-- Applied to prod on 2023-06-08, to the demo/dev database whenever
-- somebody remembered, and to staging never -- staging did not exist
-- at that point and still doesn't, really. See sql/README.md.

-- ---------------------------------------------------------------------
-- Raw partner submissions.
--
-- The dealer network posts XML to partner/ through a reverse proxy in
-- the partner DMZ, behind ONE shared login (LOAN-SEC-19, open). There
-- is no per-dealer identity; dealer_code is self-asserted in the XML
-- payload, so it is whatever the dealer's own system says it is.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS partner_submissions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    dealer_code  TEXT,
    -- The complete inbound XML, stored verbatim and forever. It
    -- contains full applicant detail including whatever PII the dealer
    -- chose to send, which in practice includes full SSNs from at least
    -- two dealers whose mapping we never corrected. Nothing redacts it,
    -- nothing expires it. This was raised at launch and deferred.
    raw_xml      TEXT,
    applicant_id INTEGER,
    loan_id      INTEGER,
    received_at  TEXT,
    -- 'RECEIVED','PARSED','ERROR','REPROCESSED'. Rows stuck in
    -- 'RECEIVED' are submissions that never got picked up, because the
    -- sweep job (batch/partner_sweep.pl) was never written -- see
    -- cron/run_nightly.sh job 4. Reprocessing is manual.
    status       TEXT,
    error_text   TEXT
);

-- ---------------------------------------------------------------------
-- Credit bureau pull cache.
--
-- cached_until was intended to prevent re-pulling within 30 days. The
-- application checks it with a string comparison against a locally
-- formatted date, and the values in here were written by a different
-- code path with a different format, so the comparison is unreliable
-- and duplicate pulls happen. Each duplicate pull is billable.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bureau_pulls (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    applicant_id  INTEGER,
    bureau        TEXT,
    score         INTEGER,
    pulled_at     TEXT,
    raw_response  TEXT,
    cached_until  TEXT
);

-- ---------------------------------------------------------------------
-- Outbound communication log. Written by the notification path; the
-- `ok` flag is set from the return value of a mail call that returns
-- true whenever the message was handed to the local MTA, which is not
-- the same as delivered. Every row says ok=1.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS comm_log (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    loan_id    INTEGER,
    channel    TEXT,
    template   TEXT,
    recipient  TEXT,
    sent_at    TEXT,
    ok         INTEGER
);

-- ---------------------------------------------------------------------
-- Funding batch headers, written by batch/funding_extract.pl.
--
-- There is no unique constraint on batch_date, which is why the
-- duplicated 02:15 crontab entry can produce two COMPLETE rows for the
-- same day with different loan counts. LOAN-2388.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS funding_batches (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    batch_date    TEXT,
    loan_count    INTEGER,
    total_amount  REAL,
    status        TEXT,
    run_by        TEXT,
    completed_at  TEXT
);

-- ---------------------------------------------------------------------
-- apr_variance.
--
-- Target of batch/nightly_reconcile.pl. It records, every night, that
-- the three APR implementations disagree.
--
-- Nothing has ever SELECTed from this table. No report, no screen, no
-- alert. It has been growing since 2016. `acknowledged` defaults to 0
-- and no code path sets it to 1, because the UI that was going to let
-- someone acknowledge a variance was never built.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS apr_variance (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    loan_id       INTEGER,
    apr_apply     REAL,
    apr_admin     REAL,
    apr_batch     REAL,
    delta_bps     INTEGER,
    detected_at   TEXT,
    acknowledged  INTEGER DEFAULT 0
);

-- ---------------------------------------------------------------------
-- soyelaran, 2023: bcrypt column alongside the 2015 unsalted MD5.
--
-- NULLABLE, deliberately, because backfilling was impossible (you
-- cannot derive a bcrypt hash from an MD5 without the plaintext) and
-- forcing a password reset for the whole company was not approved.
--
-- Result: the new login path writes pw_hash on next successful login,
-- but the OLD path in public/admin.php checks pw_md5 first and returns
-- on a match, so most users never reach the new path and pw_hash stays
-- NULL indefinitely. Partial remediation. LOAN-SEC-12 stays open.
-- ---------------------------------------------------------------------
