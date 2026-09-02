# LoanApp — New Engineer Onboarding

Written by: soyelaran (Simi Oyelaran)
Date: 2021-06-14
Lightly edited: avaldez, 2025-02-11 (see addendum at the end — I have not
rewritten the body, only added notes)

Welcome. This is the loan origination portal. It is older than most of the
people who work on it and it is the system of record for money going out
the door, so please read this whole page before you change anything.

Expect your first week to be reading, not writing.

---

## 1. What you are looking at

| Directory | What's in it |
|---|---|
| `public/` | The web tier. Pages, forms, HTML, and a lot of business logic that should not be there. |
| `includes/` | Support modules — db, session, audit, config, validation. 2016-era. |
| `lib/` | Two things: 2013 helpers that got moved here in the 2024 reorg, and a namespaced `Meridian\` layer from a contractor that is not wired up. |
| `partner/` | The dealer network XML endpoint (2023). |
| `batch/` | Perl. Nightly reconciliation, funding extract, stale purge. |
| `cron/` | Shell wrappers and the crontab as installed. |
| `conf/` | `loanapp.ini`, `rates.xml`, `feature_flags.php`. |
| `sql/` | Migrations, applied by hand. Read `sql/README.md` first. |
| `tools/` | One-off scripts that became load-bearing. |
| `docs/` | This. |

Read in this order:

1. `README.md`
2. `docs/KNOWN_ISSUES.md` — item 1 is the thing you need to understand
   about this system before anything else
3. `public/apply.php` top to bottom
4. `public/admin.php` — specifically `recompute_apr()`, and compare it
   line by line with `tier_to_apr()` in `apply.php`
5. `docs/PARTNER_PORTAL.md`
6. `sql/README.md`

Do not start with `docs/ARCHITECTURE.md`. It describes a design, not this
codebase.

## 2. Local setup

You need PHP with the SQLite PDO driver, and Perl with `DBI` and
`DBD::SQLite` if you want to run the batch jobs.

```bash
# 1. get access
#    Ask IT for the "LoanApp Developers" AD group. This grants the git repo
#    and the jump box. Allow two days.

# 2. VPN
#    You need the corporate VPN (Cisco AnyConnect profile "MTF-DEV") to
#    reach git.mtf.internal. Install from the software portal.

# 3. clone
git clone git@git.mtf.internal:loanapp.git
cd loanapp

# 4. run the setup script
./setup.sh
#    This creates data/, builds a local SQLite database from sql/, seeds
#    it with the dev dataset, and writes a data/.env for you.

# 5. serve it
php -S localhost:8000 -t public/

# 6. open http://localhost:8000/apply.php
```

Notes on the above:

- Step 4: **there is no `setup.sh` in the repository.** I have looked in
  the repo, in the wiki, and in my own home directory on the jump box. I
  think it existed on dkirkendall's machine. What it did, per this
  document, was create `data/`, run the `sql/` files in order, seed data,
  and write a `.env`. You can do the first two by hand:

  ```bash
  mkdir -p data data/logs data/outbound data/uploads
  for f in sql/00*.sql; do sqlite3 data/loans.db < "$f"; done
  ```

  Skip `sql/007_indexes_proposed.sql` — it has never been applied
  anywhere and applying it locally means your local behaviour differs
  from production.

- Step 5: several pages `require_once` files under `includes/` that are
  not all present (`includes/config.php`, `includes/functions.php`,
  `includes/auth.php`, `includes/documents.php`). `apply.php` and
  `admin.php` work. Some of the others will fatal until you stub them.
  Nobody has cleaned this up because in production those files exist on
  the box and were never committed.

- Step 2: the AnyConnect profile was retired when the network team moved
  to the new client in 2022. Ask Ops which one is current. The name in
  this document is wrong and I have left it wrong because I do not know
  the new one.

## 3. The seed dataset

There isn't one.

This is the single biggest thing that makes working on LoanApp slow, so
here is the full story rather than a one-liner.

Applicant records contain SSN (last four, stored in the clear) and annual
income. Per the data handling policy those fields cannot leave the internal
network and cannot be copied to a non-production environment without a
documented masking process. No masking process was ever documented for
LoanApp, so no production extract was ever approved.

The alternative was a synthetic dataset — a few thousand fake applicants
spanning all four tiers, both products, the surcharge boundaries, and the
`DECLINE` path. It was scoped three times (2018, 2021, 2023) and never
built. The 2021 attempt is the one I ran; it stalled on the question of
what "realistic" income and credit-score distributions are, which turns
out to be a Risk question and Risk had no owner for it.

Practical consequence: you will be testing against a handful of rows you
typed in by hand, which means you will not hit the boundary cases, which
means the boundary cases are where the bugs are. Type in at least these:

| Amount | Term | Score | Income | Debt | Why |
|---|---|---|---|---|---|
| $18,000 | 36 | 760 | 90,000 | 20,000 | Tier A, no surcharges |
| $30,000 | 48 | 700 | 95,000 | 30,000 | **the APR drift band** |
| $60,000 | 60 | 700 | 120,000 | 40,000 | above both thresholds |
| $25,000 | 49 | 600 | 60,000 | 24,000 | Tier D, term boundary |
| $10,000 | 36 | 540 | 50,000 | 10,000 | DECLINE / 99.99% |
| $5,000 | 24 | 700 | 0 | 0 | zero income (LOAN-1188) |

## 4. Conventions you will notice and should not "fix"

- `hsg_` prefixes. Halbrook Systems Group, the 2013 build contractor.
- `$GLOBALS['...']` used to pass state between functions in the same file.
- `array()` rather than `[]`, `==` rather than `===`.
- `@` before calls to suppress errors.
- SQL built by concatenation in `public/`, prepared statements in
  `includes/db.php`. Both patterns appear in the same file in places.
- `// SEC:` markers — mine. They mark what the 2021 remediation did and,
  more often, what it did not do.
- `// @deprecated` on functions that are still the only implementation.
  `payment_legacy()` in `lib/Support/money.php` is the example.

Rule of thumb: if a comment says "don't change this", find out why before
changing it. Roughly half the time there is a real reason.

## 5. Who to ask about what

| Topic | Ask |
|---|---|
| Original build, `hsg_*` functions, anything from 2013–2014 | rwhitfield (Ray Whitfield, HSG contractor) |
| Deploys, cron, the batch host, Apache | dkirkendall (Dave Kirkendall) |
| Perl batch layer, funding extract, reconciliation | jchen (Joanna Chen) |
| APR drift, LOAN-1341, `conf/rates.xml` | mpatel (Mihir Patel) |
| Security findings, the 2021 pen test | soyelaran (me) |
| Rates, rate sheets | kmoore (Finance) |
| Underwriting policy, tier thresholds | Risk — no named contact, ask your manager |
| Tier D / the subprime pilot | nobody. LOAN-1502 has been unassigned since 2018. |
| The database, MySQL, backups | the DBA team |
| Partner / dealer network | tnguyen (Thanh Nguyen) |

## 6. Things to be careful with

- **`conf/feature_flags.php`.** `FLAG_USE_RATE_ENGINE` changes every APR
  in the system if you turn it on. Read the comment above it. Do not flip
  flags on a shared environment.
- **`public/db_config.php`.** Do not move it. Several files include it by
  relative path and moving it broke production in 2019.
- **`batch/funding_extract.pl`.** It marks loans `FUNDED` before it writes
  the file. Do not run it against a database anyone cares about.
- **`tools/import_legacy_csv.php`.** It inserts applicants with no
  validation and it is how several orphaned rows got there.
- **`hsg_fix_dates()`** in `includes/legacy_compat.php`. Called from
  everywhere. I have read it four times and I could not tell you what all
  of its branches are for.

## 7. First tasks

Good starter tickets, in increasing order of danger:

1. Add validation to a form field. Contained, visible, low risk.
2. Fix a display bug on `loan_detail.php`.
3. Write a test. See `tests/README.md` — and read the reason there aren't
   any before you promise anyone a test suite.
4. Anything touching APR. Do not take this as a first ticket. Ask.

---

## Addendum — avaldez, 2025-02-11

I have been the only engineer on LoanApp since 2024. I am adding notes
rather than rewriting because I do not have enough confidence about the
2013–2021 history to change what Simi wrote.

What has changed since 2021:

- Section 5 is almost entirely former employees. Ray's firm (HSG) no
  longer exists. Dave left in 2018, Joanna in 2020, Mihir in 2022, Simi
  in 2023, Thanh moved to another team in 2024. kmoore retired. The DBA
  team was outsourced and the vendor will not touch SQLite.
- There is also a 2024 contractor layer (`lib/Pricing`,
  `lib/Underwriting/DecisionService.php`, `lib/autoload.php`) that is not
  called from anywhere. Bluewater Consulting. Their handoff docs are
  `lib/Pricing/README.md`, and they are the best-written documents in
  this repository, which tells you something.
- `setup.sh` is still missing. The VPN client is still wrong. There is
  still no seed dataset.

So the honest version of section 5 is: **I am the only person left who has
touched this. Ask me, but I probably don't know either.** When I don't
know, I add a `// ???` comment where I looked, so at least the next person
knows the question has been asked.

Two things I would ask of you, whoever you are:

1. When you work something out, write it down here. Not in a ticket, not
   in chat — here.
2. Do not delete a comment you don't understand. That is most of what is
   left of the institutional memory.

— avaldez
