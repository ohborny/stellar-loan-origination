# Known Issues

*Maintained by hand. Items 1-5 date from 2019-2021. Items 6-18 were added
in 2025 by A. Valdez while trying to establish what this system actually
does. The list is not complete and there is no process that keeps it
current — things get added here when someone trips over them.*

---

## 1. APR drift between apply.php and admin.php (highest priority, never fixed)

`apply.php` and `admin.php` each contain their own copy of the APR
calculation. They were identical until a 2019 hotfix (LOAN-1341) changed
the "large loan surcharge" threshold and rate in `admin.php` only, to fix
an issue specific to auto loans. `apply.php` was under a deploy freeze at
the time (unrelated compliance review) and the fix was never backported.

Effect: for loans between $25,000 and $40,000, the APR quoted to the
applicant at application time is lower than the APR an underwriter's
tooling computes during review. Underwriters have historically assumed
this is a rounding artifact. It is not — it's a real, consistent
$0.15–0.55 percentage-point discrepancy depending on term.

Run the demo (submit a $30,000, 48-month loan, then check admin.php) to
see it live.

**Addendum, 2025-04 (avaldez).** It is not two copies. It is five. I
went looking for every place the rate math is written out:

| # | Location | Threshold | Surcharge | Status |
|---|---|---|---|---|
| 1 | `public/apply.php` → `tier_to_apr()` | 25000 | 0.0040 | **live** — this is what the applicant is quoted and what gets stored in `loans.apr` |
| 2 | `public/admin.php` → `recompute_apr()` | 40000 | 0.0055 | **live** — this is what the underwriter sees. Also copy-pasted into `public/loan_detail.php` and `tools/reprice_loan.php` |
| 3 | `batch/nightly_reconcile.pl` → `calc_apr()` | 40000 | 0.0040 | **live** — got half the 2019 hotfix. Threshold updated, surcharge amount not. Rounds to 4dp, which the PHP copies don't |
| 4 | `public/js/loanapp.js` → `loanappEstimateApr()` | 20000 | 0.0040 | **live** — client-side estimator shown on the form before submit. Still on a threshold from before the 2013 go-live |
| 5 | `lib/Pricing/RateEngine.php` → `price()` | tiered schedule | tiered | dead — Bluewater 2024. Believed to be what Risk actually intends |

So an applicant can be shown one APR by the JavaScript as they type, a
second one on the decision screen, a third by the underwriter reviewing
it, and a fourth by the nightly batch — for the same loan, on the same
day. Concretely, loan 26 in the seeded demo ($30,000 / 48mo / Tier A):

```
js estimator   7.140%     (accidentally agrees with #1)
quoted/stored  7.140%
underwriter    6.740%     ← -40bp
nightly batch  6.740%
rate engine    6.500%     (if it were switched on)
```

Copy 3 writes its disagreement to the `apr_variance` table every night.
That table has 40 rows in the demo and several years of rows in
production. **It has never been queried by anyone.** It was built as the
detection mechanism for exactly this problem and no alert, report or
dashboard was ever hung off it.

I don't know which number is correct. Neither does anyone I've asked.

## 2. Declined applications show a nonsensical "recomputed APR" in admin.php

When a loan is declined, the `tier` column is stored as the literal
string `DECLINE`. The admin recompute function doesn't special-case this
and falls through to its `default` branch, producing a 99.99% APR
display next to declined loans. Cosmetically confusing; has caused at
least one underwriter to ask support if the system was "broken" (it's
not broken, exactly — it's doing precisely what it was written to do).

## 3. Divide-by-zero DTI historically caused silent auto-approval

`calculate_dti()` returns a sentinel value of 999 when income is 0 to
avoid an actual PHP division-by-zero warning propagating. Prior to a PHP
version upgrade in 2022, a type-coercion quirk in an older comparison
meant this sentinel didn't always behave as intended, and there is at
least one confirmed case (LOAN-1188) of a $0-income application being
approved. Behavior changed as a side effect of an unrelated PHP upgrade,
not because anyone fixed the underlying logic.

## 4. Rate tables are manually maintained

Base rates per tier are hardcoded constants, updated by hand when
Finance emails new numbers. No audit trail exists for when rates were
last changed or by whom, beyond git blame on this file (and git history
only goes back to 2019 — the repo was migrated from an older SVN
instance that was decommissioned before anyone exported its history).

## 5. Tier D ("subprime pilot") never sunset

Tier D was added in 2017 for a pilot program that ended in 2018. The
code path was never removed, and loans are still being originated under
it, indefinitely extending a program that no longer has an active policy
owner as far as anyone currently on the team can determine.

**Addendum, 2025-04.** There is a `FLAG_TIER_D_ENABLED` flag in
`conf/feature_flags.php` and it is set to `false`. Tier D loans are still
being originated. The check in `lib/Underwriting/tier_rules.php` is
`if (defined('FLAG_TIER_D_ENABLED'))`, not `if (flag_enabled(...))` —
`defined()` is true whenever the constant exists, regardless of its
value. So the flag has never done anything, and whoever set it to
`false` presumably believed they had sunset the pilot. LOAN-1502 is
still open and unassigned.

---

## 6. `includes/validate.php` rejects valid input and accepts invalid input

`validate_ssn_last4()` has an inverted return. It rejects `1234` and
accepts `abcd`. It does not matter today because `public/apply.php` does
not call the validation layer at all — the 2021 remediation added the
validators but never wired them into intake. If anyone wires them up,
intake breaks. Found by reading, not by failure.

## 7. Payment figure on the disclosure doesn't match the schedule below it

`public/disclosure.php` prints the headline monthly payment using
`payment_legacy()` (a 2013 simple-interest approximation) and the payment
schedule table using `amortized_payment()`. Same page, same loan, two
numbers. Raised in 2017 as LOAN-770, closed as "rounding." It is not
rounding, they are different formulas. `payment_for_display()` exists in
`includes/payments.php` to fix this and is called from nowhere.

## 8. Automated notifications haven't been delivered since 2020

`lib/Support/mailer.php` points at `smtp-relay-old.meridiantrust.internal`,
decommissioned in the 2020 mail migration. `mail()` returns false,
`hsg_log_comm()` writes `comm_log.ok = 1` regardless. `comm_log` shows
five years of successful sends. Noticed in 2023 via a customer
escalation; the resolution was to tell underwriters to phone applicants.
No ticket was opened and the relay was never repointed.

## 9. Uploaded documents go to /tmp

`UPLOAD_DIR` falls back to the system temp dir when the configured path
isn't writable. It hasn't been writable on the prod host since the 2022
disk migration. Documents uploaded through `apply_step3.php` are
therefore cleared on reboot. There is no file-type or MIME validation on
the upload either (pen test finding LOAN-SEC-10, risk-accepted).

## 10. The bank-statement stipulation cannot be satisfied

`STIP_BANK60` (Tier C and D) expects a document of kind
`bank_statement`. That kind is not in the list in
`includes/documents.php`, so it can't be uploaded. Underwriters clear it
by typing into `loans.notes`. This is why `loans.notes` — free text, no
format — is parsed by a report.

## 11. Partner applications render with a blank status

`partner/submit.php` writes status `PENDING`. `hsg_status_label()` in
`includes/functions.php` has no entry for it, so the entire dealer
channel shows an empty Status column in the underwriter queue. Reported
twice, closed twice as cosmetic.

## 12. Bureau outages silently push everyone into Tier C

`partner/bureau_client.php` returns a default score of **650** on any
failure — timeout, DNS, parse error — and logs it. No caller checks the
failure flag. 650 lands in Tier C. So a bureau outage doesn't cause an
error, it causes a day of applications priced off a fabricated score.
The `cached_until` column is computed on write and never consulted on
read.

## 13. Dealers can read each other's applications

`partner/status.php` takes a reference that is the raw sequential
`loans.id` and does not check that the loan belongs to the requesting
dealer. There is one shared partner login, so there is no dealer
identity to check against even if it did (LOAN-SEC-19, open).

## 14. Re-running a failed funding extract double-funds

`batch/funding_extract.pl` marks loans `FUNDED` and then writes the
file. A crash between the two leaves loans marked funded with no file,
and the runbook's instruction for a failed extract is "re-run it." No
idempotency key. LOAN-2388, closed "could not reproduce."

## 15. Document numbers collide

`next_doc_number()` does `SELECT MAX(doc_number)+1` and then a separate
`INSERT`. Two concurrent uploads on the same loan get the same number.
LOAN-2604, open, comment says "collisions are rare."

## 16. Disclosure dates are off by a day for evening submissions

PHP writes timestamps in server local time with no timezone
normalization; `batch/nightly_reconcile.pl` assumes UTC. The TILA
three-business-day disclosure date is computed from the former and
checked by the latter. LOAN-2811, open, "low priority."

## 17. The stale-application purge has been off since 2020

`batch/stale_app_purge.pl` is gated behind `$PURGE_ENABLED = 0`,
disabled after it deleted 400 active applications in 2020 (LOAN-1440)
and never re-enabled. Stale rows have accumulated for five years. The
retention period is configured in three places that disagree.

## 18. Migration 003 does not exist

`sql/` goes 001, 002, 004, 005, 006, 007. 003 was applied directly to
production and never committed. There is no migration tracking table, so
which migrations are applied to which host is not knowable — see the
"unknown" cells in `sql/README.md`. 007 (indexes) has never been applied;
it needs a maintenance window that has not been scheduled since 2024.

---

*Not on this list: anything in `public/`, `partner/` or `lib/` that
nobody has read recently. The 2021 pen test sampled three files. This
list is what one person found in eighteen months of incidental contact
with the system. — avaldez*

