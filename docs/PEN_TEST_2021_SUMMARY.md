Penetration Test — Summary (Redacted)
=====================================

Engagement:      MTF-PT-2021-04, application penetration test
Target:          Loan Origination Portal ("LoanApp")
Tester:          third-party vendor (name redacted per the MSA)
Test window:     2021-04-12 to 2021-04-16 (4 working days)
Report received: 2021-05-03
Summary author:  soyelaran (Simi Oyelaran), Application Security
Summary date:    2021-05-19
Classification:  Internal — Confidential. Redacted copy; the full vendor
                 report is held by Information Security.

This is the internal summary, not the vendor report. Findings have been
paraphrased and request/response evidence removed. Where the vendor's
wording and mine differ, theirs governs.


Scope — READ THIS FIRST
-----------------------

The engagement was scoped as a **grey-box review of a three-file sample**,
not an assessment of the application. The vendor was given:

- `public/apply.php`
- `public/login.php`
- `public/disclosure.php`

plus credentialed access to the corresponding screens on a copy of the
application. Four days, fixed price.

The sample was chosen by Engineering as "the applicant-facing flow". It
was not chosen by risk, by line count, or by data sensitivity.

Consequences of the scope, which matter more than any individual finding:

- `public/loan_detail.php` was **not assessed.** It contains the same
  class of injection as LOAN-SEC-07 and was never included in the risk
  acceptance. The acceptance says "one instance"; there are at least two.
- `public/admin.php`, `public/override.php` and everything under
  `public/reports/` were **not assessed.**
- The partner/dealer endpoint (`partner/`) did not exist in 2021 and has
  never been assessed by anyone.
- The Perl batch layer was **not assessed.**

Every "risk accepted" decision below was taken against the three-file
sample. None of them were revisited when the rest of the application was
later understood to have the same issues, and none were revisited in 2023
when the exposure changed.


Findings
--------

Seven findings. Two critical, three high, two medium. Vendor severities
retained.

| ID | Finding | Severity | Disposition |
|---|---|---|---|
| LOAN-SEC-07 | SQL injection in `apply.php` | Critical | Risk accepted 2021-06 |
| LOAN-SEC-12 | Hardcoded credentials in source | Critical | Risk accepted 2021-06 |
| LOAN-SEC-08 | Unsalted MD5 password storage | High | Partially remediated |
| LOAN-SEC-09 | No CSRF protection on state-changing forms | High | Risk accepted 2021-06 |
| LOAN-SEC-10 | Unrestricted file upload | High | Risk accepted 2021-06 |
| LOAN-SEC-11 | Session fixation | Medium | Not remediated |
| LOAN-SEC-13 | Verbose error and path disclosure | Medium | Partially remediated |


### LOAN-SEC-07 — SQL injection in `apply.php` (Critical)

Application intake concatenates unsanitised `$_POST` values directly into
`INSERT` and `SELECT` statements. The vendor demonstrated extraction of
the full `applicants` table, including `ssn_last4` and `annual_income`,
from the public application form with no authentication.

`clean_input()` is applied to some fields. It trims and strips tags; it
does not escape quotes and provides no protection here.

**Risk acceptance rationale (recorded 2021-06-11):** the application is
reachable only from the internal corporate network, and an actor already
on the internal network is assessed as having other routes to the same
data. Likelihood assessed as low on that basis. Remediation would require
rewriting the intake path, which was assessed as disproportionate.
Accepted by the then Head of Application Delivery for 12 months.

The acceptance was renewed in 2022 and again in 2023. It records the
finding as affecting one file.

### LOAN-SEC-12 — Hardcoded credentials in source (Critical)

`public/db_config.php` contains a database username and password in
plaintext. The credential dates from 2015 and has not been rotated. The
same credential appears in `conf/loanapp.ini`. Ticket LOAN-204, raised in
2016 to move credentials to environment variables, was closed in 2018 as
"wontfix — works fine".

**Risk acceptance rationale (recorded 2021-06-11):** the credential grants
access to a single database on an internal host, and source access is
restricted to the engineering group. Internal network only. Accepted.

### LOAN-SEC-08 — Unsalted MD5 password storage (High)

Operator passwords are stored as unsalted MD5 in `users.pw_md5`. The
vendor recovered 19 of 31 passwords from a supplied hash sample using a
commodity wordlist in under four minutes. No password policy, no lockout
(the attempt counter in `login.php` is a local variable and has never
persisted across requests), no rate limiting.

**Remediation (mine, 2021 and 2023):** a `users.pw_hash` column and a
bcrypt login path (`login_v2()`) were added. The cutover was never
completed because it required the whole underwriter team to reset
passwords in one week and that was not approved. `pw_md5` still exists,
the legacy path still checks it first, and 28 of 31 users have a NULL
`pw_hash`.

Partially remediated. In practice the MD5 path is still the one that
authenticates almost everybody. Removing `pw_md5` would lock out the
company.

### LOAN-SEC-09 — No CSRF protection on state-changing forms (High)

No form in the application carries an anti-CSRF token. The vendor
demonstrated a forged application submission against the intake form.

The vendor noted that the login form itself is lower risk (there is no
authenticated action worth forging a login into), but that the
underwriter-facing forms would be exploitable — while noting those forms
were out of scope and untested.

**Risk acceptance rationale (recorded 2021-06-11):** exploitation requires
an authenticated operator to visit an attacker-controlled page from the
corporate network. Assessed as low likelihood. Internal network only.
Accepted.

The out-of-scope forms the vendor flagged include the tier override
screen, which changes loan pricing. It has no token today.

### LOAN-SEC-10 — Unrestricted file upload (High)

Document upload accepts any file type and any size up to the PHP limit.
There is no extension allowlist, no MIME validation, and no content
inspection. The stored filename is derived from `slugify()`, which strips
the dot as well as the extension, so files are written without an
extension.

The vendor's own note: the missing extension means an uploaded PHP file is
not directly executable under the current Apache handler configuration,
and this is the only reason the finding is High rather than Critical. It
is an accident of a string helper, not a control.

The upload directory is inside the web root.

**Risk acceptance rationale (recorded 2021-06-11):** uploads require an
authenticated underwriter session, the stored files are not executable in
the current configuration, and the application is internal only. Accepted.

Since 2023, dealers upload documents through the partner channel behind a
single shared login. Nobody has re-assessed this finding against that.

### LOAN-SEC-11 — Session fixation (Medium)

The session identifier is not regenerated on privilege change.
`session_regenerate_id(true)` is absent from both login paths, and the
session name and cookie are set without `HttpOnly` or `Secure`. An
attacker who can set a session cookie in a victim's browser retains a
valid session after the victim authenticates.

**Disposition:** I wrote the fix during the 2021 remediation sprint and
did not ship it. `session_regenerate_id(true)` in the legacy path drops
the session before the redirect on the classic ASP-era pattern
`nav.php` relies on, and testing it properly needed a second person and a
browser I did not have. The call is present, commented out, in both
`legacy_login()` and `login_v2()`, with a `// SEC:` note saying not to
uncomment one in isolation.

Not remediated. No ticket was opened, which is my error.

### LOAN-SEC-13 — Verbose error and path disclosure (Medium)

Unhandled conditions returned PHP warnings including absolute filesystem
paths and, in one case, a fragment of a SQL statement. `debug = 1` is set
in `conf/loanapp.ini` in production.

**Partial remediation:** PDO was already set to `ERRMODE_SILENT` (a 2017
change made for a different reason), which suppresses most database error
text at the cost of failing silently everywhere. `debug = 1` is still set,
because it is the only application-level visibility we have during an
incident. Accepted on that basis.


Pattern
-------

Six of the seven findings were accepted or deferred, and five of those
cite the internal network. The rationale is the same sentence each time.
It was written once, in 2021, and copied.

The findings themselves are ordinary for a 2013 PHP application. What
carries the risk is that the acceptances are load-bearing on a single
network assumption, and the assumption is recorded nowhere except in the
acceptances themselves — so nothing was watching it.


Closing note — added 2023-08-30 (soyelaran)
-------------------------------------------

I am adding this because I do not think anyone will otherwise connect the
two documents.

In 2023 the application was exposed to the dealer partner network through
a reverse proxy in the partner DMZ, behind a single shared login
(LOAN-2077; see `docs/PARTNER_PORTAL.md`). No new API was built — the
existing web tier is what is exposed.

**The internal-network assumption that every risk acceptance above rests
on is no longer true.** Specifically:

- LOAN-SEC-07's injection is in the intake path, and the intake path is
  what the dealer channel submits into.
- LOAN-SEC-09's CSRF exposure now includes forms reachable from outside
  the corporate perimeter.
- LOAN-SEC-10's upload path is now used by dealers.
- The acceptance rationale for LOAN-SEC-12 says "internal host". The
  database has not moved, but what can reach the application has.

The 2021 findings were not re-tested against the new exposure. The
acceptances were not re-evaluated; LOAN-SEC-07 and LOAN-SEC-12 were
renewed in 2023 on the original rationale, by which point the rationale
was factually wrong. I raised this at the time.

No follow-up action was recorded. No re-test has been commissioned. No
ticket exists for revisiting the acceptances.

— soyelaran, 2023-08-30

---

*MTF-PT-2021-04 (redacted summary). Full vendor report held by
Information Security. Last edited 2023-08-30.*
