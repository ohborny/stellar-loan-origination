LoanApp Data Dictionary
=======================

Compiled by:   jchen, 2018-09 (tables 1-4)
Extended:      mpatel, 2019-08 (rate_history, documents)
Extended:      tnguyen, 2023-07 (partner tables, hurriedly)
Annotated:     avaldez, 2025-03 (the `???` entries are mine)
Last updated:  2025-03-14

Source: assembled by reading the schema off the live database and asking
whoever was still here. It is **not** derived from `sql/`, because `sql/`
does not match production (migration 003 is missing — see `sql/README.md`).
Where this document and `sql/` disagree, neither is authoritative.

Conventions used below:

| Marking | Means |
|---|---|
| *purpose unknown* | the column exists, nobody could say what it is for |
| *believed unused* | nothing found that reads it, but the search was not exhaustive |
| *populated by ?* | rows have values; the writer was not identified |
| **[NOT IN CURRENT SCHEMA]** | documented here historically; not present when last checked |
| **[UNDOCUMENTED]** | present in the database, no description available |

All timestamp columns are `TEXT`, holding ISO-8601 strings produced by PHP
`date('c')` in **server local time with no offset normalisation**. The Perl
batch layer reads them as UTC. This is LOAN-2811 and it affects every table
below. It is not repeated per column.

---

## applicants

Applicant identity and the underwriting inputs. One row per application;
there is no de-duplication, so a repeat applicant has multiple rows.

| Column | Type | Description |
|---|---|---|
| `id` | INTEGER PK | autoincrement |
| `name` | TEXT | Full name, single field. Unbounded in SQLite, but truncated to 30 characters on write by `trunc30()` on the partner path and by `tools/import_legacy_csv.php`. Also passed through `normalize_name()`, which lower-cases then title-cases, so `MCDONALD` is stored as `Mcdonald`. The stored value is the mangled, truncated one. |
| `ssn_last4` | TEXT | Last four digits, stored as text so leading zeros survive. In the clear. Occasionally holds nine digits, because the partner mapper stores whatever the dealer XML contained. |
| `annual_income` | REAL | Self-reported. `0` is a valid stored value (LOAN-1188). Defaults to `45000` on the partner path when the element is absent, with nothing on the record indicating that a default was applied. |
| `credit_score` | INTEGER | Bureau score at decision time. Nullable. `0` appears in imported rows. |
| `existing_debt` | REAL | Named "existing debt". The form labels it "existing monthly debt (annualized)". `calculate_dti()` divides it directly by `annual_income`. Whether a given row holds a monthly or an annual figure depends on what the applicant typed. |
| `created_at` | TEXT | see the note on timestamps above |
| `email` | TEXT | **[NOT IN CURRENT SCHEMA]** Documented here since 2018. Not present when last checked. The notification path reads the recipient from somewhere else; I have not found where. — avaldez |
| `phone` | TEXT | **[NOT IN CURRENT SCHEMA]** as above |
| `coapplicant_id` | INTEGER | **[NOT IN CURRENT SCHEMA]** 2017 co-applicant feature, cut. `FLAG_COAPPLICANT_INTAKE` still exists in `conf/feature_flags.php`. The flag comment says the columns are "still in the schema... somewhere". They are not in this table. |

## loans

The loan record. One row per application that was not abandoned before the
decision step.

| Column | Type | Description |
|---|---|---|
| `id` | INTEGER PK | autoincrement. **This is the reference exposed to dealers** by the partner status endpoint, unhashed and sequential. |
| `applicant_id` | INTEGER | No foreign key. Points at rows that no longer exist in some cases. |
| `amount` | REAL | Principal requested. Float, from 2013. No upper bound enforced in `apply.php`; `conf/loanapp.ini` says 50000 and the auto product goes to 75000. |
| `term_months` | INTEGER | Requested term. Not validated in `apply.php`. Values above 84 exist. |
| `apr` | REAL | **System of record for pricing.** The APR as computed at application time by `tier_to_apr()` in `public/apply.php`. This is the number that reaches the signed disclosure. `admin.php` recomputes a different number for display and does not write it here. A tier override does not update this column. |
| `tier` | TEXT | `A`, `B`, `C`, `D`, or the literal string `DECLINE`. Not constrained. Import tooling writes `''`. Four possible values; most readers handle two. |
| `status` | TEXT | Free text. Observed: `APPROVED`, `DECLINED`, `IN_REVIEW`, `PENDING_DOCS`, `FUNDED`, `CLOSED`, `IMPORTED`, `ok`. The last one came from a 2017 script that no longer exists. |
| `notes` | TEXT | Free text, no format, entered by underwriters. Before the override screen existed (2018) this is where overrides were recorded, in prose. **Parsed by at least one report** — the pipeline report does a substring match on it to derive something, and I have not worked out what the match is looking for. Do not assume this column is safe to reformat. |
| `created_at` | TEXT | see timestamps note |
| `funded_at` | TEXT | **[NOT IN CURRENT SCHEMA]** documented in the 2018 version of this file. The funding batch sets `status = 'FUNDED'` and records nothing else on the loan row. There is no per-loan funding timestamp anywhere. |
| `product_code` | TEXT | **[NOT IN CURRENT SCHEMA]** Referenced in the comment block of `sql/004_tier_d_pilot.sql` as something migration 003 may have added and that "no longer exists anywhere". Reports derive the product from amount and term instead (`derive_product()` in `public/reports/pipeline.php`), which is a guess. |

## users

Operator logins. 31 rows.

| Column | Type | Description |
|---|---|---|
| `id` | INTEGER PK | |
| `username` | TEXT | No uniqueness constraint. |
| `pw_md5` | TEXT | Unsalted MD5. The legacy login path checks this and it is what authenticates almost everybody. LOAN-SEC-08 / LOAN-SEC-12. |
| `pw_hash` | TEXT | bcrypt, added 2023. NULL for 28 of 31 rows. Written only by the v2 login path, which is reachable from a form that is not linked in the navigation. |
| `role` | TEXT | `admin`, `underwriter`, `readonly`, `partner`. Free text. Compared with `==` against literals in three files, with the spelling `under_writer` appearing once and matching nothing. `viewer` is passed to `require_role()` by the report pages and is not a value that appears in this column. |
| `active` | INTEGER | 1/0. Checked by the interactive login. **Not** checked by the partner path, so a deactivated partner account still authenticates. |
| `created_at` | TEXT | |
| `last_login` | TEXT | Written by the v2 path only, and by a concatenated `UPDATE` in the legacy path whose result is not checked. Roughly half the rows show a 2015 timestamp for people who log in daily. |

## audit_log

Largest table in the database. No indexes.

| Column | Type | Description |
|---|---|---|
| `id` | INTEGER PK | |
| `actor` | TEXT | Username string, not a `users.id`, so it does not survive a rename and cannot be joined reliably. Sometimes `cron`. Sometimes `unknown` (the partner shared login sets neither session key). Sometimes `''`. |
| `action` | TEXT | Verb. No controlled vocabulary. `LOGIN`, `LOGIN_FAILED`, `DECISION`, `OVERRIDE`, and a long tail. |
| `entity_type` | TEXT | `loan`, `user`, `applicant`, `document`. Also blank. |
| `entity_id` | INTEGER | id within `entity_type`. Zero where the caller did not have one. |
| `detail` | TEXT | Free-text blob. Some rows are JSON, some are a PHP `print_r()` dump, some are an English sentence. No parser exists. Contains unmasked SSN values in some rows — `mask_ssn()` was applied to screens only. |
| `ts` | TEXT | see timestamps note |

## documents

Uploaded document metadata. Rows and files on disk are not reconciled.

| Column | Type | Description |
|---|---|---|
| `id` | INTEGER PK | |
| `loan_id` | INTEGER | No FK. No index (see `sql/007_indexes_proposed.sql`). |
| `doc_number` | INTEGER | Per-loan sequence, generated in application code as `MAX(doc_number)+1` with no lock, no transaction and no unique constraint. Two uploads in the same second collide. LOAN-2604, open. Collisions are silent; the second file overwrites the first on disk. |
| `kind` | TEXT | `ID`, `INCOME`, `DISCLOSURE`, `OTHER` from the UI dropdown. The partner path writes whatever the dealer sent. |
| `filename` | TEXT | Name on disk, relative to an upload root that is configured in `conf/loanapp.ini` and also hardcoded, with a different value, in two PHP files. Some rows resolve under the 2018 path and some under the 2021 path; there is no way to tell which without trying both. Extensions are stripped by `slugify()`. |
| `bytes` | INTEGER | Size as reported at upload. Not re-checked against disk. |
| `uploaded_by` | TEXT | Username string. Not escaped on write (concatenated INSERT). |
| `uploaded_at` | TEXT | see timestamps note |
| `sha256` | TEXT | **[NOT IN CURRENT SCHEMA]** proposed in 2021 so that a document could be verified against the row. Never added. |

## decision_overrides

Underwriter tier overrides. Populated.

| Column | Type | Description |
|---|---|---|
| `id` | INTEGER PK | |
| `loan_id` | INTEGER | |
| `from_tier` | TEXT | Tier before the override. |
| `to_tier` | TEXT | Tier after. Note that `loans.tier` is updated but `loans.apr` is not, so the loan keeps the old tier's rate. |
| `reason_code` | TEXT | Two incompatible vocabularies in one column. The override form writes `RC01`–`RC07`. The (uncalled) Bluewater decision code expects `OVR_COMPENSATING_FACTORS`, `OVR_DOC_VERIFIED`, `OVR_POLICY_EXCEPTION`, `OVR_BUREAU_STALE`. The canonical list came from a 2018 email that is not in the repo. Also observed: `MANUAL`, `POLICY`, `EXCEPTION`, `OTHER`, `4`, `''`. |
| `override_by` | TEXT | Username. There is no second-approver column, and policy requires a second approver for overrides of two or more tiers. AUD-2020-06. |
| `override_at` | TEXT | see timestamps note |

## rate_history

Intended as the audit trail for rate changes. Contains 5 rows, all seeded
in 2017 by `sql/004_tier_d_pilot.sql`. **Nothing in the application writes
to it.** The hardcoded rates have been hand-edited at least four times
since (2019, 2021, 2023 twice) with no rows added.

| Column | Type | Description |
|---|---|---|
| `id` | INTEGER PK | |
| `tier` | TEXT | Includes a `DECLINE` row holding 0.9999, which is not a rate — it is the `switch` default. |
| `base_rate` | REAL | Decimal, not percent. |
| `effective_date` | TEXT | Stored, never enforced. Nothing reads this table. |
| `entered_by` | TEXT | Free text. Some rows say `finance email`. |
| `source_note` | TEXT | Free text. The 2017 Tier D row reads `pilot ends 2018-03 -- REMOVE AFTER`. |

## partner_submissions

Raw inbound dealer submissions.

| Column | Type | Description |
|---|---|---|
| `id` | INTEGER PK | |
| `dealer_code` | TEXT | **Self-asserted in the payload.** Nothing validates it against a list. Codes nobody recognises have appeared in the volume report. |
| `raw_xml` | TEXT | The complete inbound document, verbatim and retained indefinitely. Contains whatever PII the dealer chose to send, which in practice includes full SSNs from at least two dealers. Nothing redacts it, nothing expires it. |
| `applicant_id` | INTEGER | Set on successful mapping. |
| `loan_id` | INTEGER | Set on successful mapping. |
| `received_at` | TEXT | see timestamps note |
| `status` | TEXT | `RECEIVED`, `PARSED`, `ERROR`, `REPROCESSED`. Rows left at `RECEIVED` were never picked up: the sweep job (`batch/partner_sweep.pl`) was never written. |
| `error_text` | TEXT | Vendor-facing message plus, in some rows, a PHP warning. |

## bureau_pulls

Credit bureau response cache. Each duplicate pull is billable.

| Column | Type | Description |
|---|---|---|
| `id` | INTEGER PK | |
| `applicant_id` | INTEGER | |
| `bureau` | TEXT | Bureau identifier. One value in practice. |
| `score` | INTEGER | Parsed score. |
| `pulled_at` | TEXT | see timestamps note |
| `raw_response` | TEXT | Full bureau response, retained. Same PII considerations as `partner_submissions.raw_xml`. |
| `cached_until` | TEXT | Intended to prevent a re-pull within 30 days. Compared as a **string** against a locally formatted date, and the values in the column were written by a different code path in a different format, so the comparison is unreliable and duplicate pulls happen. |

## funding_batches

Headers for the daily funding extract.

| Column | Type | Description |
|---|---|---|
| `id` | INTEGER PK | |
| `batch_date` | TEXT | **No unique constraint.** The duplicated 02:15 crontab entry can produce two `COMPLETE` rows for the same date with different loan counts. LOAN-2388. |
| `loan_count` | INTEGER | Loans in the file. Not reconciled against the file. |
| `total_amount` | REAL | Sum of principal. |
| `status` | TEXT | `RUNNING`, `COMPLETE`, `FAILED`. A row can read `COMPLETE` when no file was produced, because the extract falls back to a local directory and exits zero. |
| `run_by` | TEXT | `cron`, or a username when run by hand. |
| `completed_at` | TEXT | see timestamps note |
| `batch_id` | TEXT | **[NOT IN CURRENT SCHEMA]** This is the idempotency key that would let core banking de-duplicate a re-run, described as a "ten-line change" in `batch/funding_extract.pl` since 2018. It does not exist. `conf/loanapp.ini` has `idempotency_enabled = 1`, which is read by nothing. |

## comm_log

Outbound notifications.

| Column | Type | Description |
|---|---|---|
| `id` | INTEGER PK | |
| `loan_id` | INTEGER | |
| `channel` | TEXT | `EMAIL`. `FAX` appears in rows up to 2019; the fax gateway was turned off that year and `FLAG_FAX_DELIVERY` is still defined as true. |
| `template` | TEXT | Template name. `disclosure_2016.tpl` predominantly. |
| `recipient` | TEXT | Address as sent. *populated by ?* — I have not identified where the address comes from, given there is no `applicants.email` column. — avaldez |
| `sent_at` | TEXT | see timestamps note |
| `ok` | INTEGER | Set from the return of a mail call that succeeds whenever the message was handed to the local MTA. **Every row is 1.** It is not evidence of delivery. |

## apr_variance

Output of `batch/nightly_reconcile.pl`. It records, every night, that the
three production APR implementations disagree.

**Nothing has ever SELECTed from this table.** No report, no screen, no
alert. It has been growing since 2016. It is described in
`docs/archive/REWRITE_PROPOSAL_2022.md` as "six years of evidence".

| Column | Type | Description |
|---|---|---|
| `id` | INTEGER PK | |
| `loan_id` | INTEGER | |
| `apr_apply` | REAL | Recomputed with `apply.php`'s formula (threshold 25000, surcharge 0.0040). |
| `apr_admin` | REAL | Recomputed with `admin.php`'s formula (threshold 40000, surcharge 0.0055). |
| `apr_batch` | REAL | The batch job's own `calc_apr()` (threshold 40000, surcharge 0.0040 — the partial 2019 port), `sprintf`'d to 4 decimals. Matches neither of the other two end to end. |
| `delta_bps` | INTEGER | Difference in basis points, as an **integer**. A sub-1bp drift records as 0, so the job's mismatch count undercounts. Reconciliation output, **not currently reviewed by anyone.** |
| `detected_at` | TEXT | see timestamps note |
| `acknowledged` | INTEGER | Defaults to 0. No code path sets it to 1 — the screen that was going to let someone acknowledge a variance was never built. Every row in the table is unacknowledged. |

---

## Tables referenced elsewhere and not present

| Name | Referenced by | Status |
|---|---|---|
| `schema_migrations` | `sql/README.md` (as an absence) | never created. Migration state is inferred by inspecting the schema. AUD-2020-05. |
| `loan_products` | a 2017 comment | *purpose unknown*, never found |
| `stipulations` | `lib/Underwriting/stipulations.php` docblock | the matrix is a PHP array, not a table |

## Known gaps in this document

- Columns marked **[NOT IN CURRENT SCHEMA]** were documented against an
  earlier database and have not been removed from this file, because in
  several cases the column may exist in production and not in the demo
  database (or the reverse) and nobody can check production any more.
- There are columns in the live database that are not in this document.
  The last person to attempt a full column-by-column diff was jchen in
  2019; her note said "come back to this" and she left in 2020.
- Nothing here has been verified against `sql/`. See the header.

— avaldez, 2025-03-14
