LoanApp — Application Architecture
==================================

Author:        mpatel (Mihir Patel)
Created:       2021-02-19
Last updated:  2021-03-04
Status:        DRAFT — circulated to the platform group, not formally reviewed
Audience:      engineers joining the LoanApp team

> This document was written to give new joiners a mental model of the
> system. It describes the layering we agreed to at the January 2021
> platform review. Where the code does not match, the code is expected to
> converge on this document, not the other way round.


1. Overview
-----------

LoanApp is a three-layer application: a **presentation layer** (thin PHP
pages under `public/`), a **service layer** (`LoanService`, `PricingService`,
`DecisionService`), and a **data layer** (repository classes over SQLite,
formerly MySQL). Cross-cutting concerns — configuration, session, audit —
are provided by the `includes/` support modules and injected into the
services at construction time.

The key architectural property is that **no business rule lives above the
service layer**. A page under `public/` collects input, hands a request
object to a service, and renders whatever comes back. Pricing rules,
tiering rules and eligibility rules are the service layer's business and
nothing else's.


2. Layer diagram
----------------

```
   +--------------------------------------------------------------+
   |                      PRESENTATION LAYER                       |
   |                                                              |
   |   public/apply.php      public/admin.php    public/reports/  |
   |   public/loan_detail.php public/override.php                 |
   |                                                              |
   |   * form handling, HTML rendering, no business logic         |
   |   * NO SQL, NO rate math, NO tier math                       |
   +---------------------------+----------------------------------+
                               |
                               |  request / result objects only
                               v
   +--------------------------------------------------------------+
   |                        SERVICE LAYER                          |
   |                                                              |
   |   LoanService      -- application intake, status transitions |
   |   PricingService   -- THE single APR implementation          |
   |   DecisionService  -- tiering, DTI, stipulations             |
   |   DocumentService  -- upload, numbering, retrieval           |
   |                                                              |
   |   * stateless, constructor-injected dependencies             |
   |   * the only place underwriting policy is expressed          |
   +---------------------------+----------------------------------+
                               |
                               |  repository interfaces
                               v
   +--------------------------------------------------------------+
   |                         DATA LAYER                            |
   |                                                              |
   |   LoanRepository      ApplicantRepository   AuditRepository  |
   |   RateTableRepository DocumentRepository                     |
   |                                                              |
   |   * prepared statements only                                 |
   |   * schema knowledge does not escape this layer              |
   +---------------------------+----------------------------------+
                               |
                               v
                     +---------------------+
                     |   SQLite / MySQL    |
                     |   data/loans.db     |
                     +---------------------+

   Batch layer (batch/*.pl) sits alongside, not underneath, and is
   expected to call the service layer over a thin CLI shim once the
   shim is written.
```


3. The service layer in detail
------------------------------

### 3.1 `PricingService`

`PricingService::priceApplication()` is the **single** APR implementation.
It takes tier, principal, term and product code, and returns a priced
result with a component breakdown (base rate, term surcharge, large-loan
surcharge). It reads its rate table through `RateTableRepository`, which
reads `conf/rates.xml`, which is the system of record for pricing.

Nothing else in the codebase computes an APR. That is the point of the
class. Any future rate change is a config change, not a code change.

### 3.2 `LoanService`

`LoanService` owns the application lifecycle:

| Method | Responsibility |
|---|---|
| `submitApplication()` | validate, decision, price, persist, audit |
| `recomputeApr()` | re-price an existing loan (used by the underwriter screen) |
| `applyOverride()` | tier override + mandatory re-price + dual control |
| `markFunded()` | idempotent status transition, keyed on batch id |

`recomputeApr()` is important: it exists so that the underwriter screen
and the application screen cannot diverge, because they call the same
method. This closes out the class of defect described in
`docs/KNOWN_ISSUES.md` item 1.

`markFunded()` being idempotent is what closes LOAN-2388.

### 3.3 `DecisionService`

Tier determination, DTI, and the stipulation matrix. Consumes a policy
table rather than an `if/elseif` chain, so that Risk can review the policy
without reading PHP.


4. Data layer
-------------

Repositories expose collection-oriented methods (`findById`,
`findByStatus`, `save`) and nothing else. Callers never see a `PDO`
handle and never see a table name. All statements are prepared. The
schema in `sql/` is the authority; `sql/README.md` documents the
migration sequence.


5. Cross-cutting concerns
-------------------------

| Concern | Provided by | Notes |
|---|---|---|
| Configuration | `includes/config_loader.php` | single source: `conf/loanapp.ini` |
| Session / auth | `includes/session.php` | one login path, bcrypt |
| Audit | `includes/audit.php` | every state change writes a row |
| Feature flags | `conf/feature_flags.php` | resolved through `flag_enabled()` |
| Money / dates | `lib/Support/` | shared helpers, no policy |

Configuration has a single source of truth. Where a value appears both in
`conf/loanapp.ini` and in a `define()`, the ini file wins.


6. Current state vs. target state
---------------------------------

The table below is the honest position as of this writing. "Current" is
what is deployed today; "Target" is section 2 above.

| Area | Current state | Target state | Gap |
|---|---|---|---|
| Layering | Two PHP pages (`apply.php`, `admin.php`) contain intake, tiering, pricing, SQL and HTML in one file each | Three layers, section 2 | Large |
| APR implementations | **Two** — `tier_to_apr()` in `apply.php` and `recompute_apr()` in `admin.php`. Identical except for the LOAN-1341 surcharge change. | One (`PricingService`) | Medium |
| Rate source | Hardcoded `switch` in the two pages above; `conf/rates.xml` added and not yet wired | `conf/rates.xml` via `RateTableRepository` | Small — mostly done |
| SQL | String concatenation in `public/`; prepared statements in `includes/db.php` for newer code | Repositories, prepared only | Medium |
| Products | Personal unsecured to $25k, plus auto to $75k added 2019 | Product-code driven | Medium |
| Auth | `users.pw_md5`, unsalted | bcrypt, one path | Medium |
| Batch layer | Perl, own copy of the rate math | CLI shim onto `PricingService` | Medium |
| Tests | None | Service-layer unit tests | Large |

Notes on the "current" column, added during review:

- The APR count of two is the number of implementations **in
  `public/`**. It does not count `batch/nightly_reconcile.pl`, which was
  raised in the review as "reporting only" and therefore out of scope for
  this table.
- The products row predates the home-improvement product.
- Nothing in the "current" column has moved since this table was first
  drafted, and the table itself was carried over from the 2019 platform
  review deck with the numbers updated. If a row looks optimistic, it is
  probably because it was written against the 2019 codebase and only
  lightly re-checked.


7. Migration sequencing
-----------------------

The order matters. Extracting pricing before extracting the data layer
means `PricingService` has to be given a `PDO` handle, which we then have
to take away again.

1. Introduce the repositories behind the existing pages. No behaviour
   change. (~2 sprints)
2. Extract `PricingService`. Shadow-run it against the live formula and
   diff the outputs before switching. (~2 sprints, plus a month of
   shadow-running)
3. Extract `DecisionService`. Requires Risk to sign off on the policy
   table, which requires locating the original policy document.
4. Extract `LoanService`, collapse `apply.php` and `admin.php` to
   controllers.
5. Point the Perl batch at the CLI shim, delete `calc_apr()`.

Step 3 is the schedule risk. The 2020 audit could not locate the risk
policy document (finding AUD-2020-03), so "sign off on the policy table"
currently means "ask Risk to ratify whatever the code does", which is a
different and more political exercise.


8. Things this document does not cover
--------------------------------------

- The reporting pages under `public/reports/`. They read the database
  directly and will keep doing so; they are not in scope for the layering
  work.
- `includes/legacy_compat.php`. The `hsg_*` functions are called from
  everywhere and cannot be removed until the pages that call them are
  gone. `hsg_fix_dates()` in particular is called in fourteen places and
  I have not been able to establish what all of its branches are for.
- Anything to do with the DMZ or external exposure. LoanApp is an
  internal application reachable only from the corporate network, so the
  network topology is out of scope here.


9. Open questions
-----------------

- Who owns the pricing policy? Not Engineering, but nobody in Risk has
  claimed it either.
- Does `conf/loanapp.ini` win over the `define()`s in practice? Section 5
  says it should. I have not verified it in every code path and I suspect
  the answer is "it depends on the file".
- What does `hsg_fix_dates()` do?

---

TODO: update after the partner integration.

— mpatel, 2021-03-04
