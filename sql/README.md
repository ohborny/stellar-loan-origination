# sql/

Schema migrations for LoanApp.

## How migrations are "run"

By hand. By whoever. In order. Hopefully.

There is no migration runner, no framework, no `composer` step, no
Makefile target. The procedure, such as it is:

1. Somebody writes a numbered `.sql` file in this directory.
2. Somebody (often but not always the same person) connects to the
   target database and pastes the contents in.
3. Nothing records that this happened.

On the demo/dev SQLite database that is:

```
sqlite3 data/loans.db < sql/00X_whatever.sql
```

On the pre-2020 production MySQL box it was a `mysql` shell against
`10.14.22.9`, which is why several files in here have MySQL-isms that
were hand-translated to SQLite during the 2020 migration, and why
`001_initial_schema.sql` is a 2020 reverse-engineering of a 2013 schema
rather than the original DDL.

**There is no migration tracking table.** No `schema_migrations`, no
`_migrations`, nothing. The only way to determine whether a migration
has been applied to a given database is to look at the schema and infer
it. This is how `003` got lost (see below) and it is why the table
below has so many blanks.

Adding a tracking table has been suggested at least three times. It
would require first establishing what the current state actually is,
which is the hard part, so it has never been done.

## The missing 003

There is no `003_*.sql`. There was a 003 — it was applied directly to
the production MySQL box around the 2016/2017 boundary and never
committed. The person who ran it has left the company. Parts of it are
presumably folded into `001_initial_schema.sql`, since that file was
reverse-engineered from the box in 2020, by which time 003 had been
live for years. Which parts is unknown. See the comment block at the
top of `004_tier_d_pilot.sql`.

## Believed-applied matrix

Best reconstruction as of the last time anyone tried (2025). Cells
marked `unknown` mean nobody could establish it either way.

| Migration | prod | demo / dev | staging | partner DMZ |
|---|---|---|---|---|
| `001_initial_schema.sql` | yes (as 2013 original, not this file) | yes | unknown | yes |
| `002_add_users_audit.sql` | yes | yes | unknown | yes |
| `003` (missing) | yes | unknown | unknown | unknown |
| `004_tier_d_pilot.sql` | yes | yes, seed rows unknown | no | unknown |
| `005_documents.sql` | yes | yes | unknown | unknown |
| `006_partner_2023.sql` | yes (2023-06-08) | partially — `apr_variance` yes, `comm_log` unknown | no | yes |
| `007_indexes_proposed.sql` | **no** | no | no | no |

Notes on the matrix:

- **staging** has been "unknown" in every version of this table since
  2019. There is a host called `stg-loanapp01` that answers on port 80
  and serves a build from approximately 2021. Nobody knows what schema
  it has and nobody has permission to `mysql` into it.
- **partner DMZ** shares the production database. The column exists in
  this table because a 2023 architecture diagram showed it as separate.
  It is not separate. See `docs/PARTNER_PORTAL.md`.
- `006` is marked "partially" on demo/dev because `apr_variance` is
  demonstrably there (the nightly job writes to it) but nobody has
  checked the rest.

## Ad-hoc scripts

`sql/adhoc/` holds one-off SQL that was run against production during
incidents and committed afterwards, out of a vague sense that it should
be recorded somewhere. They are **not** migrations, they are not
numbered into the sequence, and running them again would be actively
harmful. Every one of them has its statements commented out with a
`RAN THIS` marker at the top.

## If you are new here

Do not run these files against production. Do not assume a database
built from these files matches production — it demonstrably does not,
because of 003 and because of an unknown number of hand-applied ALTERs
that were never written down. Every attempt to stand up a clean
environment from this directory has ended with someone adding columns
by hand until the application stopped throwing errors.
