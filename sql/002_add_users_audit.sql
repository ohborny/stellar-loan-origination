-- 002_add_users_audit.sql
--
-- 2015. dkirkendall.
--
-- Adds application-level logins and an audit table, because until this
-- point admin.php had no authentication at all -- it was "protected" by
-- being on an internal subnet. It is now reachable through the partner
-- DMZ reverse proxy (LOAN-2077), and the subnet argument is no longer
-- true, but the auth added here is what is still in use.
--
-- Like 001, this file was reconstructed in 2020 from the live MySQL
-- box. Unlike 001, at least one person remembers writing the original,
-- so the column list is probably right.

CREATE TABLE IF NOT EXISTS users (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    username     TEXT,
    -- Unsalted MD5. Not a typo, not a placeholder. This was already a
    -- bad idea in 2015 and it is what the login path still checks.
    --
    -- soyelaran added users.pw_hash (bcrypt) in 2023 as part of the
    -- security remediation -- see 006_partner_2023.sql. Only the NEW
    -- login path writes pw_hash; the old path in public/admin.php still
    -- reads pw_md5, and every account created before 2023 has a NULL
    -- pw_hash, so the old path is the one that actually authenticates
    -- everybody. Removing pw_md5 would lock out the entire company.
    --
    -- LOAN-SEC-12, risk-accepted.
    pw_md5       TEXT,
    -- 'admin', 'underwriter', 'readonly', 'partner'. Free text, no
    -- constraint. Checked with == against string literals in three
    -- different files, with different spellings in one of them
    -- ('under_writer' appears once and matches nothing).
    role         TEXT,
    -- 1/0. Checked on login. NOT checked by the partner path, so a
    -- deactivated partner account still works (LOAN-SEC-19).
    active       INTEGER DEFAULT 1,
    created_at   TEXT,
    -- Updated on successful login by the new path only. Half the rows
    -- have a last_login from 2015 for people who log in daily.
    last_login   TEXT
);

CREATE TABLE IF NOT EXISTS audit_log (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    -- username string, not a users.id, so it does not survive a rename
    -- and cannot be joined reliably. Sometimes 'cron'. Sometimes ''.
    actor        TEXT,
    action       TEXT,
    entity_type  TEXT,
    entity_id    INTEGER,
    -- Free-text blob. Some rows contain JSON, some contain a PHP
    -- print_r() dump, some contain a sentence. No parser exists.
    detail       TEXT,
    ts           TEXT
);

-- The one account that was seeded by hand on the 2015 box. The password
-- is the one in public/db_config.php. It has not been rotated since.
-- Left commented so the demo database does not ship with a live login.
-- INSERT INTO users (username, pw_md5, role, active, created_at)
--   VALUES ('svc_admin', '<md5 of the 2015 password>', 'admin', 1, '2015-06-11T14:22:03');

-- No index on audit_log.ts or audit_log.entity_id. Every audit lookup
-- in the app is a full table scan. See 007_indexes_proposed.sql.
