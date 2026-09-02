# Meridian\Pricing

Bluewater Consulting — LoanApp modernization, work package 2 (Pricing).
Handoff document, revision C, 2024-10-28.

## What this package does

A single, testable, effective-dated APR calculation for LoanApp.

| Class                | Responsibility                                                   |
|----------------------|------------------------------------------------------------------|
| `RateEngine`         | Computes APR from tier, amount, term and product.                 |
| `PriceRequest`       | Immutable, self-validating pricing inputs.                        |
| `PriceResult`        | Immutable result with a full component breakdown and `explain()`. |
| `RateTableSource`    | Interface for effective-dated base rates.                         |
| `XmlRateTableLoader` | Reads `conf/rates.xml`. Caching, tolerant parser.                  |

The engine applies a **banded** surcharge schedule (term: 0-36 / 37-48 / 49-60 /
61+; principal: <=25000 / <=40000 / >40000) and rounds the result half-up to
three decimals. The three implementations already in production do neither.

## How to integrate it

The plan agreed at the 2024-09-12 checkpoint. **None of the four steps was
executed before the engagement ended.**

1. **Register the autoloader.** Require `lib/autoload.php` from
   `public/db_config.php`, which every entry point already includes. Blocked:
   registering it collides with `lib/Underwriting/tier_rules.php`; see the note
   in `lib/autoload.php`.
2. **Shadow-run.** Behind `FLAG_USE_RATE_ENGINE`, compute both the legacy APR
   and the engine APR on every application, display the legacy one, and write
   the pair to `apr_variance`. Run for one full month.
3. **Reconcile.** Have Risk sign off on the variance report, and decide what
   happens to loans already booked at a different rate. This is a policy
   decision, not an engineering one.
4. **Cut over.** Flip the flag, delete `tier_to_apr()` and `recompute_apr()`,
   and repoint `batch/nightly_reconcile.pl` at the engine's output rather than
   its own `calc_apr()`.

Step 3 is the real work. Steps 1, 2 and 4 are a week between them.

## Known divergences from legacy behavior

Decimal rates. Δ in basis points, engine minus legacy. Every row differs from
at least one production implementation; **no row matches all three**.

| Sample loan                          | `apply.php` | `admin.php` | `nightly_reconcile.pl` | `RateEngine` | Δ vs apply | Δ vs admin |
|--------------------------------------|-------------|-------------|------------------------|--------------|-----------:|-----------:|
| Tier A, $18,000, 36 mo, PERSONAL     | 0.0649      | 0.0649      | 0.0649                 | **0.065**    |     +1.0   |     +1.0   |
| Tier B, $30,000, 48 mo, AUTO         | 0.0964      | 0.0924      | 0.0924                 | **0.096**    |     −4.0   |    +36.0   |
| Tier B, $60,000, 60 mo, AUTO         | 0.0989      | 0.1004      | 0.0989                 | **0.100**    |    +11.0   |     −4.0   |
| Tier C, $52,000, 72 mo, AUTO         | 0.1364      | 0.1379      | 0.1364                 | **0.138**    |    +16.0   |     +1.0   |
| Tier D, $25,000, 49 mo, PERSONAL     | 0.1924      | 0.1924      | 0.1924                 | **0.195**    |    +26.0   |    +26.0   |
| `DECLINE` (any amount/term)          | 0.9999      | 0.9999      | 0.9999                 | **1.000**    |     +1.0   |     +1.0   |

Notes on the rows:

- Row 1 is the base case, and it still moves. Three-decimal rounding alone
  shifts 6.49% to 6.500%. There is no "unaffected" population.
- Row 2 is the loan the discovery report opened with: the applicant is quoted
  0.0964 on `apply.php` and re-quoted 0.0924 the moment an underwriter opens
  the file in `admin.php`. The engine agrees with neither.
- Row 4 is where the LOAN-1341 hotfix is visible: `admin.php` picked up the
  0.0055 surcharge, the Perl batch picked up only the threshold change.
- Row 5 is a Tier D loan. `lib/Underwriting/DecisionService.php` does not
  produce Tier D at all, so if both packages are adopted together this row
  cannot occur — see LOAN-1502.
- Row 6: rounding the 99.99% decline sentinel yields 100.000%. Cosmetic, but it
  will appear in adverse-action letters. Special-case it before cutover.

## Status

Engagement ended **2024-11** with integration incomplete. Nothing in this
package is called from anywhere in the application; `FLAG_USE_RATE_ENGINE`
remains off. Tracked as LOAN-3002 (abandoned).
