# Internal Audit — Loan Origination Platform ("LoanApp")

**Report reference:** IA-2020-114
**Engagement:** Application controls review, consumer lending origination
**Fieldwork:** 2020-02-03 to 2020-03-27
**Report issued:** 2020-05-08
**Distribution:** CIO, Head of Consumer Lending, Head of Risk, Application Support
**Classification:** Internal — Confidential

**Prepared by:** Internal Audit (lead auditor: R. Sandoval)
**Engineering contact:** mpatel
**Business contact:** Consumer Lending Operations

---

## 1. Scope and approach

Internal Audit reviewed the application-level controls over the LoanApp
loan origination platform, covering:

- underwriting rule governance and change control
- pricing (rate table) governance
- segregation of duties over decision overrides
- change management over application code and database schema
- evidence and audit trail sufficiency

Approach: interviews with Engineering, Application Support and Consumer
Lending Ops; walkthrough of the application intake and decision flow;
inspection of source code, configuration and database schema; sample
testing of 40 originations from 2019.

**Scope limitations.** Internal Audit did not perform a security
assessment (a separate penetration test was commissioned and is scheduled
for 2021), did not review the Perl batch layer beyond confirming its
existence, and did not review the vendor interface to core banking.

## 2. Summary of conclusion

The platform originates and prices consumer loans without a documented,
approved and version-controlled statement of the underwriting and pricing
rules it applies. The rules exist only as application source code. There
is no change control over rate tables, no migration tracking over the
database schema, and no evidence trail sufficient to demonstrate that the
rules applied to a given origination were the rules approved by Risk.

Internal Audit's overall opinion is **Unsatisfactory**.

The most significant issue is not any individual defect but the absence of
a source of truth: where the code and management's understanding of the
policy disagree, there is no document that can settle it.

## 3. Findings

Severity: **H** high, **M** medium, **L** low.

| ID | Finding | Severity | Status | Owner | Remediation due |
|---|---|---|---|---|---|
| AUD-2020-01 | No change control over rate tables | H | Open | Engineering | 2021-03-31 |
| AUD-2020-02 | Underwriting rules exist only as application code | H | Open | Risk / Engineering | 2021-06-30 |
| AUD-2020-03 | Original risk policy document could not be located | H | Open — no action | Risk | 2021-06-30 |
| AUD-2020-04 | Tier D operating without a policy owner | H | Open | Consumer Lending | 2021-03-31 |
| AUD-2020-05 | No database migration tracking | M | Open | Engineering | 2021-09-30 |
| AUD-2020-06 | No segregation of duties on decision overrides | M | Open | Consumer Lending | 2021-03-31 |
| AUD-2020-07 | Pricing calculation implemented in more than one place | M | Open | Engineering | 2021-12-31 |
| AUD-2020-08 | Audit trail insufficient for reconstruction | M | Open | Engineering | 2021-09-30 |
| AUD-2020-09 | No test environment with representative data | M | Deferred | Engineering | 2021-12-31 |
| AUD-2020-10 | Credentials embedded in source | M | Risk accepted | Engineering | n/a |
| AUD-2020-11 | Reporting extracts unrestricted | L | Open | Engineering | 2021-09-30 |
| AUD-2020-12 | No formal deployment record | L | Open | Application Support | 2021-03-31 |

### AUD-2020-01 — No change control over rate tables (High)

Base rates by underwriting tier are hardcoded as constants in application
source (`public/apply.php`, `public/admin.php`). They are amended by hand
by an engineer when Finance circulates a new rate sheet by email. There is
no ticket, no approval, no four-eyes check, and no record of the change
other than source control history — and source control history for this
application begins in 2019, the repository having been migrated from a
decommissioned SVN instance whose history was not exported.

A `rate_history` table exists in the schema and contains five rows, all
dated 2017. Nothing in the application writes to it.

Internal Audit could not establish, for any origination in the sample, that
the rate applied was the rate approved by Finance at that date.

**Management response (mpatel, Engineering, 2020-04-22):** Agreed. A rate
configuration file (`conf/rates.xml`) was introduced in 2019 with the
intent of moving pricing out of code. Completing that migration and adding
a written rate change procedure will be scheduled in H1 2021.
*Remediation due 2021-03-31.*

### AUD-2020-02 — Underwriting rules exist only as application code (High)

Tier assignment thresholds (credit score bands and debt-to-income limits)
are expressed as an `if/elseif` chain in `public/apply.php`. There is no
corresponding policy document, decision table, or specification held by
Risk. Comments in the source describe the thresholds as coming from "a risk
policy document that predates this system" (see AUD-2020-03).

The consequence is that the application code *is* the policy. Risk cannot
review the rules without reading PHP, and no Risk representative
interviewed during fieldwork had done so.

Internal Audit further notes that a DTI ceiling of 0.43 is applied in
`public/apply.php` while `conf/loanapp.ini` records 0.45. Neither value
could be traced to an approval.

**Management response (Head of Risk, 2020-04-30):** Partially agreed. Risk
will commission a reconstruction of the current rule set into a reviewable
policy document. Timing subject to resourcing. *Remediation due
2021-06-30.*

### AUD-2020-03 — Original risk policy document could not be located (High)

Management represented that the thresholds implemented in the platform
derive from a consumer credit risk policy approved in or around 2011,
predating the platform itself. Internal Audit requested this document on
2020-02-11, 2020-03-02 and 2020-03-19.

It was not produced. Searches were made of the Risk shared drive, the
policy repository, and the archived contents of the 2011 committee
mailbox. The document management system in use at the time was
decommissioned in 2014 and its contents were migrated selectively.

Internal Audit is therefore unable to conclude that the rules implemented
in the platform reflect any approved policy. This finding cannot be
remediated by locating the document; it can only be remediated by
approving a new one, which is AUD-2020-02.

**Management response (Head of Risk, 2020-04-30):** Noted. Risk considers
the current rule set to be operating as intended notwithstanding the
absence of the originating document. No further action proposed pending
AUD-2020-02. *Remediation due 2021-06-30.*

### AUD-2020-04 — Tier D operating without a policy owner (High)

Tier D was introduced in 2017 to support a time-limited subprime pilot
programme. The programme's approved end date was 2018-06-30. Loans
continue to be originated under Tier D pricing (18.99% base) and no
individual or committee could be identified as the current owner of the
Tier D policy.

The engineering ticket to sunset the pilot (LOAN-1502) has been open and
unassigned since 2018. `conf/loanapp.ini` retains a `[deprecated_2018]`
section recording the pilot end date and a pilot cap of $15,000; the cap
is not enforced anywhere in the application.

Internal Audit sampled 6 Tier D originations from 2019. All 6 exceeded the
pilot cap. None carried evidence of an approval outside the pilot.

**Management response (Head of Consumer Lending, 2020-05-01):** Agreed
that ownership must be established. The product is performing and Consumer
Lending does not propose to suspend originations while ownership is
determined. *Remediation due 2021-03-31.*

### AUD-2020-05 — No database migration tracking (Medium)

Schema changes are applied by hand from numbered `.sql` files by whoever
is available. There is no migrations table and no record of which changes
have been applied to which environment. Internal Audit observed that
migration `003` is absent from the repository entirely; Engineering
confirmed it was applied directly to production in 2016 or 2017 by an
individual who has since left, and that its contents are unknown.

Consequently no environment can be demonstrated to match production, and
production cannot be rebuilt from source.

**Management response (mpatel, 2020-04-22):** Agreed. Adding tracking
requires first establishing the current state of each environment, which
is itself the substantial part of the work. *Remediation due 2021-09-30.*

### AUD-2020-06 — No segregation of duties on decision overrides (Medium)

The 2018 credit policy memo requires a second approver for any tier
override spanning two or more tiers, and for any override into Tier A. The
override screen implements no such check; a single underwriter may record
any override, with a free-text reason code that is not validated against
any list.

Internal Audit sampled 11 overrides from 2019. 4 spanned two or more
tiers. None carried a second approval.

Additionally, an override does not re-price the loan. A loan overridden
from Tier D to Tier B retains the Tier D rate.

**Management response (Consumer Lending Ops, 2020-05-01):** Agreed. A dual
control requirement will be added to the override screen.
*Remediation due 2021-03-31.*

### AUD-2020-07 — Pricing calculation implemented in more than one place (Medium)

The APR calculation is implemented independently in at least three
locations: the application intake page, the underwriter review page, and
the nightly Perl reconciliation job. Engineering confirmed the intake and
review implementations diverged following an uncoordinated 2019 change
(LOAN-1341) applied to one file only.

The platform therefore quotes one APR to the applicant and displays a
different APR to the underwriter for the same loan. Internal Audit
confirmed this on 3 of the 40 sampled originations, all in the
$25,000–$40,000 range, with differences of 15 to 55 basis points.

The nightly job records the difference to a variance table. Internal Audit
could not identify any person or process that reviews that table.

**Management response (mpatel, 2020-04-22):** Agreed. Consolidation onto a
single implementation is dependent on AUD-2020-01. *Remediation due
2021-12-31.*

### AUD-2020-08 — Audit trail insufficient for reconstruction (Medium)

`audit_log` records an actor as a free-text username rather than a user
identifier, records detail as an unstructured free-text blob in at least
three different formats, and is not written by all state-changing code
paths. Timestamps are stored as local-time strings without offset. Internal
Audit was unable to reconstruct the decision history of 9 of the 40 sampled
originations.

**Management response (mpatel, 2020-04-22):** Agreed. *Remediation due
2021-09-30.*

### AUD-2020-09 — No test environment with representative data (Medium)

There is no environment in which a change can be exercised against
representative data before release. Production data cannot be copied to a
lower environment under the data handling policy, and no synthetic dataset
has been produced.

**Management response (Engineering, 2020-04-22):** Agreed in principle.
Producing a synthetic dataset requires Risk input on realistic
distributions and is not currently resourced. Deferred.
*Remediation due 2021-12-31.*

### AUD-2020-10 — Credentials embedded in source (Medium)

Database credentials are present in plaintext in `public/db_config.php`.
The credential has not been rotated since 2015. A ticket to move
credentials to environment variables (LOAN-204) was closed in 2018 as
"wontfix — works fine".

**Management response (Engineering, 2020-04-22):** The platform is
reachable only from the internal corporate network and the account has
access to a single database. Risk accepted on that basis.
*Risk accepted. No remediation date.*

### AUD-2020-11 — Reporting extracts unrestricted (Low)

The CSV pipeline export includes SSN last-four and annual income. The
access check on the reporting pages returns successfully for a session
carrying no role, a change made in 2019 to permit an unauthenticated
scheduled extract.

**Management response (Engineering, 2020-04-22):** Agreed.
*Remediation due 2021-09-30.*

### AUD-2020-12 — No formal deployment record (Low)

Releases are performed by file synchronisation from an engineer's working
copy. No record is kept of what was deployed, by whom, or when.

**Management response (Application Support, 2020-05-04):** Agreed.
*Remediation due 2021-03-31.*

## 4. Follow-up

Internal Audit will re-test the above at the next scheduled review of the
consumer lending application estate.

---

### Follow-up status (added by Internal Audit, undated)

Re-testing was deferred in 2021 and again in 2022 as part of the audit
plan reprioritisation. No LoanApp follow-up engagement has been performed.
Every remediation date in the table at section 3 has passed. The status
column has not been updated since the report was issued.

*IA-2020-114. Retained per the records schedule.*
