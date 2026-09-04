<!-- PR Template for LoanApp — see docs/ONBOARDING.md and docs/KNOWN_ISSUES.md -->

## Summary

<!-- What does this change do, in one or two sentences? -->

## What changed

<!-- List the files and areas modified. If this touches any of the five
     APR implementations (apply.php tier_to_apr, admin.php recompute_apr,
     nightly_reconcile.pl calc_apr, lib/Pricing/RateEngine, tools/reprice_loan.php),
     call that out explicitly — see docs/KNOWN_ISSUES.md item #1. -->

## Testing done

<!-- How did you verify this change? Be specific about what you ran.

     Unit tests (if applicable):
     ```
     php tests/test_strings.php
     php tests/test_money.php
     ```

     Integration tests (if applicable):
     ```
     php tests/integration/test_db_integration.php
     php tests/integration/test_pricing_integration.php
     php tests/integration/test_partner_xml_integration.php
     ```

     Manual testing (describe what you did in the local demo):
     ```
     php -S localhost:8000 -t public/
     ```

     If you could not test something, say why. The codebase has known
     testing constraints (no staging, no synthetic dataset, migration
     003 missing) — see tests/README.md. -->

## Impact checklist

<!-- Check all that apply. If a box is checked, add details below. -->

- [ ] Changes an APR calculation or pricing logic
- [ ] Touches a feature flag in `conf/feature_flags.php`
- [ ] Modifies the database schema or requires a new migration in `sql/`
- [ ] Changes authentication, session, or the partner endpoint security
- [ ] Modifies the deployment process or `cron/` scripts
- [ ] Touches applicant PII (SSN, income, credit score)

### APR / pricing impact
<!-- If checked: which of the five implementations does this touch?
     Does it change the APR on existing loans? Have you compared
     apply.php, admin.php, and the Perl batch output? -->

### Feature flag impact
<!-- If checked: which flag(s)? Does flipping it change customer-facing
     numbers? See the warning in conf/feature_flags.php — the precedence
     order is not what most people expect. -->

### Database impact
<!-- If checked: which migration number? Is it applied by hand or
     scripted? Remember migration 003 is missing — a fresh database from
     sql/ is NOT identical to production. -->

### Security impact
<!-- If checked: does this affect the SQL injection surface (LOAN-SEC-07),
     the unsalted MD5 auth path (LOAN-SEC-08), the shared partner login
     (LOAN-SEC-19), or the hardcoded credentials in db_config.php? -->

## Relevant tickets

<!-- Link any LOAN-*, LOAN-SEC-*, or other tracking numbers. -->

## Notes for the reviewer

<!-- Anything else the reviewer needs to know. If this is a change that
     "should be simple" but isn't because of the codebase history, explain
     why. If you are not sure whether something is correct, say so —
     institutional memory is thin and uncertainty is expected here. -->
