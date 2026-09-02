
# Loan Origination Portal ("LoanApp")

Meridian Trust Financial. Built ~2013 by a contractor (Halbrook Systems
Group), extended piecemeal since by at least eight people, six of whom
have left the company. Runs on an internal PHP/Apache box.

Originally intended for personal unsecured loans up to $25,000; extended
in 2019 to auto loans up to $75,000 and in 2021 to home improvement
loans, both times because "it already had the workflow." A HELOC product
was added to the intake form in 2021 and never launched; the form still
offers it.

## How to run (local demo)

```
php -S localhost:8000 -t public/
```

- `http://localhost:8000/apply.php` — application intake
- `http://localhost:8000/login.php` — underwriter login
- `http://localhost:8000/admin.php` — underwriter queue
- `http://localhost:8000/loan_detail.php?id=26` — loan detail

The demo database (`data/loans.db`) is seeded with 26 applications
spanning every tier and status. **Submit a $30,000, 48-month loan and
then look at `admin.php`** — the queue will show `NO - DRIFTED` in the
Match column. That is issue #1 below, live.

## Architecture

There isn't one. There is a `docs/ARCHITECTURE.md` that describes a
three-layer design with a `LoanService` and a `PricingService`; neither
was ever built. What actually exists:

```
public/        14 files. PHP and HTML in the same files. SQL built by
               string concatenation. Two of them contain their own
               copy of the underwriting logic.
includes/      The shared layer, sort of. Half of these are forwarding
               shims added in 2024 so that older require paths still
               resolve. config.php forwards to config_loader.php;
               functions.php forwards to three files in lib/Support/.
lib/           Created in 2024 for the Bluewater contractors' namespaced
               rewrite. The old procedural files were moved in alongside
               it, so this directory now holds two unrelated codebases:
               2013 procedural PHP and 2024 PSR-4 PHP, in the same tree.
               The 2024 half is not called by anything.
partner/       The 2023 dealer integration. SOAP-ish XML endpoint behind
               a single shared login. This is the part that is reachable
               from outside the corporate network.
batch/         Perl. Nightly reconciliation and the funding extract.
               Written 2016 by someone who left in 2020.
cron/          Shell wrappers around the Perl. No `set -e`.
sql/           Migrations 001-007. 003 is missing; it was applied
               directly to prod and never committed.
conf/          Three config formats (ini, xml, php defines) that
               disagree with each other and with the code.
tools/         One-off incident scripts that were kept and re-run.
tests/         Two files, 29 assertions, all against string and money
               helpers. See tests/README.md for why there are no others.
```

## Known issues

See `docs/KNOWN_ISSUES.md`. The headline one:

**The APR quoted to the applicant and the APR the underwriter sees can
disagree**, because the rate calculation is implemented independently in
five places that have drifted apart since a 2019 hotfix was applied to
one of them. On a $30,000 / 48-month loan the discrepancy is 40 basis
points. This has been true for six years.

`docs/archive/EMAIL_THREAD_LOAN1341.txt` is the 2019 email thread that
produced it. It is a normal, reasonable thread. Everyone in it is doing
their job. That is what makes it worth reading.

## Why this is expensive to change

- **No tests, and no way to write them.** No staging environment with
  realistic data. SSNs and income can't leave the internal network per
  policy, and no synthetic dataset has ever been built — it has been
  scoped three times and funded zero times.
- **The business rules are the code.** Credit tiers, rate formulas and
  surcharge thresholds are hardcoded in presentation files, duplicated
  across five of them, and sourced from no system of record. The
  original risk policy document these rules were meant to encode could
  not be located during the 2020 audit. This code *is* the policy now,
  and nobody can say whether it is the policy Risk intends.
- **Nothing can be verified before it ships.** There is one environment
  and it is production. `hsg_env_is_prod()` returns `true`
  unconditionally, which is accurate.
- **The dead modernization makes it worse, not better.** Bluewater's
  2024 `lib/` layer is well-written, well-documented, and probably
  correct. Switching it on (`FLAG_USE_RATE_ENGINE`) would change the APR
  on every loan in the system, so it can't be switched on without
  someone signing off on the new numbers, and the person who would sign
  off wants to know which of the current five numbers is right first.
  Nobody can answer that. So the good code sits next to the bad code and
  the bad code keeps running.
- **The exposure changed without the code changing.** A 2021 pen test
  found SQL injection (`LOAN-SEC-07`) and it was risk-accepted as
  "internal network only." In 2023 the app was put behind a
  partner-facing reverse proxy to unblock a dealer launch. The 2021
  findings were never re-run against the new exposure. See
  `docs/PARTNER_PORTAL.md`.
- **Institutional memory is one person.** See the "who to ask about
  what" table in `docs/ONBOARDING.md`; most of the rows are former
  employees.

## Modernization notes

See `../MODERNIZATION_TARGETS.md`, `docs/archive/REWRITE_PROPOSAL_2022.md`
(abandoned mid-sentence), and `lib/Pricing/README.md` (the contractors'
handoff doc for work that was never integrated).

---

*Last meaningful update to this README: 2019. The sections above were
added in 2025 by A. Valdez, who is currently the only person who has
made a change to this repository in eighteen months.*


# stellar-loan-origination
A legacy loan origination app for Stellar banking. 
