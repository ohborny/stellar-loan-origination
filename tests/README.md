# tests/

We should write tests.

**Last updated:** 2025-04-02 (avaldez)

## What's here

| File | Covers | Assertions |
|---|---|---|
| `test_strings.php` | `lib/Support/strings.php` | 13 |
| `test_money.php` | `lib/Support/money.php` | 16 |

That's it for unit tests. Two files, string and money helpers, no coverage
of anything that decides or prices a loan.

```bash
php tests/test_strings.php
php tests/test_money.php
```

## Integration tests

`tests/integration/` contains tests that exercise multiple components
working together against real fixtures (the schema, the rate table, the
partner XML mapper).

| File | Covers | Assertions |
|---|---|---|
| `integration/test_db_integration.php` | Schema (sql/) + DB layer (includes/db.php) | 30 |
| `integration/test_pricing_integration.php` | RateEngine + XmlRateTableLoader + conf/rates.xml | 40 |
| `integration/test_partner_xml_integration.php` | partner_normalize_field_names + map_partner_v1 | 42 |

```bash
php tests/integration/test_db_integration.php
php tests/integration/test_pricing_integration.php
php tests/integration/test_partner_xml_integration.php
```

The DB integration test backs up and restores `data/loans.db` so the demo
database is not affected. The pricing test uses a `FrozenClock` for
deterministic results. The partner test documents a known bug in the
field-name normalization chain (the Northgate `last4 -> ssn_last4` rename
mangles already-normalized `ssn_last4` elements from other dealer formats).

There is no runner, no `composer.json` install, no PHPUnit. Each file
carries its own four-line `assert_eq()` and exits non-zero on failure.
Composer was never installed on any host that runs this application —
`composer.json` exists in the root because Bluewater added it in 2024, and
`vendor/` has never existed.

## Why there aren't more

Not because nobody wanted them. The blocker is data, and it has been the
same blocker since 2018.

1. **No staging environment.** There is a host called `stg-loanapp01`
   serving a build from about 2021. Nobody knows what schema it has and
   nobody has access to find out (`sql/README.md`).
2. **Production data cannot be copied down.** Applicant records hold SSN
   last-four in the clear and self-reported annual income. Under the data
   handling policy those fields cannot leave the internal network or reach
   a non-production environment without a documented masking process. No
   masking process was ever documented for LoanApp, so no extract was ever
   approved.
3. **The synthetic dataset was never built.** It was scoped in 2018, 2021
   and 2023. The 2021 attempt stalled on what "realistic" income and
   credit-score distributions are, which turned out to be a Risk question,
   and Risk had no owner for it. Audit raised this as AUD-2020-09; it was
   deferred with a 2021-12-31 date.
4. **No environment matches production anyway.** Migration `003` is
   missing and its contents are unknown, so a database built from `sql/`
   is demonstrably not production.

The consequence is circular and worth stating plainly: the highest-value
tests are the ones that would pin down the APR calculations, and those
need boundary-case loans across all four tiers, both products and both
surcharge thresholds. Typing six rows in by hand does not get you there.
So the part of the system that is known to be wrong is the part with no
tests, and it stays that way because testing it needs the data that policy
will not let us have and nobody will fund the alternative.

## What is not tested

Everything that matters:

- The four APR implementations (`tier_to_apr()`, `recompute_apr()`,
  `calc_apr()`, `RateEngine::price()`). None of them.
- Tier determination and DTI.
- The partner XML mapper and its field-rename chain.
- The funding extract, including the LOAN-2388 retry path.
- Anything involving the database. There is no fixture database and the
  tests above deliberately do not touch `data/loans.db`.

## Note, avaldez, 2025-04

I added one test to `test_money.php` this week — the
`total_of_payments()` short-term case. Writing the assertion took ten
minutes. Working out how to run the file took two days:

- `php` on the app host is 5.6 and the `lib/Support/` files are fine under
  it, but my laptop has 8.x and the two disagree about a couple of
  warnings, so "passes" depends on where you run it.
- `tests/` is excluded from the deploy rsync (`docs/RUNBOOK.md` section 2
  does not exclude it, but the actual command people paste does), so the
  tests are not on any server.
- Nothing in the repository says how to run them. The instructions above
  are ones I worked out and then wrote down, which is the whole reason
  this section exists.

I also tried to add a test for `total_of_payments()` over an 84-month
term. It fails. It is commented out in `test_money.php` with a note. I do
not know whether the test is wrong or the function is wrong, and finding
out means deciding whether a fraction of a cent on a disclosure matters,
which is not my call.

If you are reading this because you have been asked to "add some tests":
start with the synthetic dataset. Everything else is downstream of it.
