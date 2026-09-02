Rate Change Procedure
=====================

Document owner:  mpatel (Mihir Patel), Engineering
Contributors:    kmoore (Finance), dkirkendall (Application Support)
Created:         2019-08-05
Last updated:    2019-11-18
Review cycle:    annual
Next review:     2020-08

Purpose
-------

To describe how base rates and surcharges are changed in LoanApp when
Finance issues a new rate sheet, so that the change is applied once, in
one place, with a record of who did it and why.

Prior to 2019 rate changes were made by editing PHP source directly. As
of the LOAN-1341 follow-up work, pricing configuration lives in
`conf/rates.xml` and **rate changes no longer require a code change or a
deploy.** This document replaces the informal process.

Scope
-----

In scope:

- Base rate per underwriting tier (A, B, C, D)
- Term surcharge schedule
- Large-loan surcharge schedule
- Effective dates

Out of scope:

- Tier thresholds (credit score / DTI cut-offs). Those are underwriting
  policy, not pricing. Changing them is a policy change and needs Risk.
- Fees. LoanApp does not price fees.


Roles
-----

| Role | Who | Responsibility |
|---|---|---|
| Requester | Finance (currently kmoore) | Issues the rate sheet by email |
| Implementer | Engineering on-call | Edits `conf/rates.xml` |
| Verifier | Application Support | Confirms the new rates render |
| Approver | Finance | Confirms the rate sheet matches what they sent |

There is no formal change ticket for a rate change. It is treated as a
configuration change. (An earlier draft of this document required a
LOAN ticket per change; that requirement was dropped in review because
"rates move faster than change control".)


Procedure
---------

### Step 1 — Receive the rate sheet

Finance emails the new numbers to the ops alias. The subject line is
usually "rates effective Monday" or similar. Save the email; it is the
only authorisation record.

Confirm the email states:

- the new base rate for each tier
- the effective date
- whether surcharges change (usually they do not)

If any of the three is missing, go back to Finance. Do not infer.

### Step 2 — Edit `conf/rates.xml`

All pricing configuration is in `conf/rates.xml`. Open it and update the
`base_rate` and `effective_date` attributes for each tier:

```xml
<tiers>
    <tier code="A" base_rate="0.0649" effective_date="2019-09-01" entered_by="mpatel"/>
    <tier code="B" base_rate="0.0899" effective_date="2019-09-01" entered_by="mpatel"/>
    <tier code="C" base_rate="0.1249" effective_date="2019-09-01" entered_by="mpatel"/>
    <tier code="D" base_rate="0.1899" effective_date="2019-09-01" entered_by="mpatel"/>
</tiers>
```

Rules for editing this file:

- Rates are **decimals, not percentages.** 6.49% is `0.0649`. Getting
  this wrong by a factor of 100 is the failure mode this format was
  chosen to avoid and it has still happened once.
- Set `entered_by` to your own username.
- Set `effective_date` to the date Finance gave you, in `YYYY-MM-DD`.
- **Do not change the `DEFAULT` tier row** (`base_rate="0.9999"`). It is
  not a rate. It is the sentinel the code falls through to for a
  declined application, and two reports filter on the literal value
  `0.9999`. Changing it breaks both.
- Update `<metadata>`: `last_reviewed`, `reviewed_by`, `source_note`
  (quote the Finance email subject), and `next_review_due`.

If the surcharge schedules change, update `<term_surcharges>` and
`<large_loan_surcharges>` in the same edit. Bands are inclusive of
`min_months` / `max_months` and of `max_amount`.

### Step 3 — Commit and deploy the config

`conf/rates.xml` is in the repository, so the change needs to be
committed and pushed to the web hosts. Follow `docs/RUNBOOK.md` section 2
for the rsync, or copy the single file if you are confident:

```bash
scp conf/rates.xml loanapp@loanapp-prod01.mtf.internal:/var/www/loanapp/conf/
scp conf/rates.xml loanapp@loanapp-prod02.mtf.internal:/var/www/loanapp/conf/
```

No Apache restart is needed. The rate table is re-read per request.

### Step 4 — Verify

1. Load `public/reports/rate_sheet.php` and confirm the printed base
   rates match the Finance email.
2. Submit a test application on `apply.php` for a mid-size loan
   (Tier B, $18,000, 36 months) and confirm the quoted APR equals the
   new Tier B base rate with no surcharge.
3. Open the same loan in `admin.php` and confirm the recomputed APR
   matches the quoted APR.
4. Print the rate sheet and give it to Ops for the wall.

### Step 5 — Record the change

Insert a row per tier into `rate_history`:

```sql
INSERT INTO rate_history (tier, base_rate, effective_date, entered_by, source_note)
     VALUES ('A', 0.0649, '2019-09-01', 'mpatel', 'finance email 2019-08-28');
```

This is the audit trail for rate changes. It is a manual insert because
nothing in the application writes to this table yet.


Sign-off checklist
------------------

Complete for every rate change and file with the Finance email.

```
Rate change:  ____________________     Effective date: ______________
Implementer:  ____________________     Date applied:   ______________

[ ]  Finance email received, states rates + effective date
[ ]  Rates are expressed as decimals, spot-checked against the email
[ ]  conf/rates.xml edited; entered_by and effective_date set
[ ]  DEFAULT (0.9999) row untouched
[ ]  <metadata> block updated
[ ]  Committed to source control
[ ]  Deployed to prod01 and prod02
[ ]  rate_sheet.php shows the new rates
[ ]  Test application quoted at the expected APR
[ ]  Verify quoted APR matches booked APR
[ ]  rate_history rows inserted (one per tier)
[ ]  Finance confirmed the sheet matches what they sent

Implementer sign-off: ____________   Finance sign-off: ____________
```

Notes on the checklist, added after the first two changes:

- "Verify quoted APR matches booked APR" is the one nobody can tick. On
  every change since this document was written, the number on `apply.php`
  and the number `admin.php` recomputes have differed for loans in the
  $25,000–$40,000 band, so step 4.3 fails and the box stays empty. Both
  changes were signed off anyway with the box blank, on the basis that
  the discrepancy predates the rate change and is therefore not caused by
  it. That is true and it is also why the box has never once been ticked.
- Nobody has ever filled in the Finance sign-off line.


Escalation
----------

If a rate change goes wrong (wrong rate live, factor-of-100 error, wrong
effective date):

1. Revert `conf/rates.xml` from source control and re-deploy the file.
2. Tell Finance immediately. Loans quoted at the wrong rate during the
   window are already on signed disclosures and cannot be silently
   re-priced; that is a Compliance conversation, not an engineering one.
3. Note the window (start and end timestamps) — someone will ask.


Open items
----------

- `rate_history` is populated by hand and therefore mostly is not. There
  are five rows in it, all from 2017. Automating the insert is a small
  change that has not been scheduled.
- Nothing validates the contents of `conf/rates.xml` on load. A typo
  produces a silently wrong rate rather than an error.
- Effective dating is stored but not enforced; the file is read as
  "current" regardless of the dates in it.

---

*mpatel, Engineering, 2019-11-18.*

*This procedure was written alongside `conf/rates.xml` in the expectation
that the pricing code would be reading that file by Q4 2019.*
