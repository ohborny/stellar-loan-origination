Proposal: LoanApp Remediation and Partial Rewrite
=================================================

Author:   mpatel (Mihir Patel), Engineering
Version:  0.4 (working draft — do not circulate)
Date:     2022-06-27
Status:   DRAFT
Tracking: LOAN-2210

> Draft 0.4. Sections 1–6 are ready for review. Section 7 (risk register)
> is complete. Section 8 (Phase 3) is where I stopped. Please read 1–6 and
> tell me whether the phasing is defensible before I spend more time on
> the back half. — MP


1. Why now
----------

LoanApp has been "too risky to touch" for long enough that the phrase has
stopped meaning anything. It means we do not change it, which means the
defects we know about compound.

The specific triggers for writing this now:

- The 2020 internal audit (IA-2020-114) issued twelve findings, four of
  them High. Every remediation date in that report has passed. Nothing
  has been remediated. The next audit cycle will re-test.
- The 2021 penetration test accepted six of seven findings on the basis
  that the application is internal-only. There is a live project to
  expose the application to a dealer network. When that ships, the
  rationale under six risk acceptances stops being true on the same day.
- We are the only team in the division with no test coverage of any kind.
- The APR drift (KNOWN_ISSUES item 1) has been live since 2019. We quote
  applicants one number and book another. I did the hotfix that caused
  it. I would like to be the person who closes it.

None of the above is new information. What is new is that the dealer
project gives us a date.


2. What this proposal is not
----------------------------

It is not a full rewrite. A green-field replacement of LoanApp is
somewhere between 18 and 30 months and requires a product owner, which we
do not have. Every previous attempt to propose one has died at the
sponsorship stage, twice (2016, 2019).

This proposal is a **strangler**: leave the application running, extract
the parts that carry the risk, and stop when the risk is gone rather than
when the code is pretty.

Explicitly out of scope:

- Replacing SQLite/MySQL. The data layer stays where it is.
- Reskinning the UI. The underwriters do not want a new screen.
- The Perl batch layer, except to point it at the extracted pricing.
- The partner integration, which is being built in parallel and which I
  have no influence over.


3. Objectives, in priority order
--------------------------------

1. **One APR implementation.** Everything else on this list is secondary.
2. A written, Risk-approved statement of the underwriting rules.
3. Rate changes as configuration, with change control.
4. Enough test coverage to make (1) safe.
5. Close the High audit findings that fall out of the above.

Note that (2) is not an engineering deliverable. It is the dependency that
has killed this work every previous time it was attempted.


4. Approach
-----------

Four phases. Each phase leaves the application working and shippable, and
each phase is independently abandonable — if we stop after Phase 2, Phase
2 was still worth doing.

| Phase | Deliverable | Effort | Elapsed |
|---|---|---|---|
| 0 | Instrumentation and shadow-run harness | 3 dev-weeks | 3 weeks |
| 1 | Extract pricing to a single implementation | 8 dev-weeks | 10 weeks |
| 2 | Rate table as configuration + change control | 4 dev-weeks | 5 weeks |
| 3 | Extract underwriting decisioning | 14 dev-weeks | 6 months+ |
| 4 | Retire duplicate code paths, close findings | 5 dev-weeks | 6 weeks |

Totals: 34 dev-weeks of engineering, roughly 11 months elapsed with one
engineer, 6–7 months with two. The elapsed figures assume Risk turnaround
which I have no basis for estimating; see the risk register.

Effort figures are mine, estimated bottom-up from a file-by-file read of
`public/` and `batch/`. They exclude Risk and Compliance effort, which is
not mine to estimate and which I suspect is the larger number.


5. Phase 0 — Instrumentation
----------------------------

Three weeks. No behaviour change. This is the phase that makes the rest
possible and it is the one most likely to be cut, so I am putting the
argument for it first.

Deliverables:

- A harness that, for any given loan, computes the APR by all three
  production formulas plus the intended formula, and diffs them. Runs
  offline against a database copy.
- Backfill that harness across every loan since 2019 and produce the
  population report: how many loans, what the distribution of the drift
  is, and what the aggregate dollar exposure is.
- A `apr_variance` reader. The nightly job has been writing to that table
  since 2016 and nothing has ever read it. There are six years of
  evidence in there.

Why this first: we currently cannot answer "how many loans are
mispriced?" Every conversation about fixing the drift stalls on that
question. Until we can answer it, nobody will authorise Phase 1, because
nobody knows whether Phase 1 creates a remediation obligation.

Risk: the answer might be large. That is not an argument for not looking.


6. Phase 1 — Extract pricing
----------------------------

Eight dev-weeks, ten elapsed.

1. Write one pricing implementation, in one place, with unit tests. It
   takes tier, amount, term, product and an effective date, and returns a
   rate plus a component breakdown. No I/O.
2. Shadow-run it: on every application and every underwriter recompute,
   compute both the legacy number and the new number, **display the
   legacy number**, and write the pair to `apr_variance`. One month
   minimum.
3. Reconcile the shadow-run output with Risk. This is a policy
   conversation: the new implementation will produce a third number, and
   somebody has to decide which of the existing numbers is correct.
4. Cut over. Delete `tier_to_apr()` and `recompute_apr()`. Repoint
   `nightly_reconcile.pl` at the extracted implementation.
5. Decide what happens to already-booked loans. Also not an engineering
   decision.

Steps 3 and 5 are the schedule. Steps 1, 2 and 4 are about three weeks of
actual work between them.

The technical work here is genuinely small. I want to be clear about that
because the estimate looks large and the reason it looks large is that
most of it is waiting.


7. Risk register
----------------

| # | Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|---|
| R1 | Risk cannot ratify the underwriting rules because the 2011 policy document does not exist (AUD-2020-03) | High | Blocks Phase 3 entirely | Ratify current behaviour as-is, explicitly, as a new policy. Requires a sponsor willing to sign. |
| R2 | Phase 0 reveals a material mispricing population, creating a remediation and possibly a disclosure obligation | Medium | Could stop the whole programme | Legal and Compliance in the room before Phase 0 output is circulated, not after |
| R3 | No test data. Cannot exercise any change against representative inputs (AUD-2020-09) | Certain | Slows every phase | Build the synthetic dataset in Phase 0. Scoped three times before, built zero times. |
| R4 | Single-engineer knowledge concentration | High | Programme stops if that person leaves | Pair on every phase. Write the docs as we go. |
| R5 | The dealer/partner project changes the application underneath us mid-programme | High | Rework | Not mitigable from here. I have no visibility into that project's plan. |
| R6 | Migration 003 is unknown, so no environment matches production (AUD-2020-05) | Certain | Undermines any pre-release testing | Reconcile production schema by inspection in Phase 0 |
| R7 | Underwriters resist any change to the review screen | Medium | Adoption | Do not change the screen. Phase 1 changes a number, not a layout. |
| R8 | The 99.99% decline sentinel leaks into a customer-facing document during cutover | Low | Compliance incident | Special-case it before cutover, and test it, which requires R3 |
| R9 | Sponsorship lapses between phases | High | Programme dies half-done, leaving two implementations plus a third new one | Make each phase independently valuable. This is why Phase 0 exists. |

R9 is the one I would bet on. Two previous attempts at this work stopped
mid-way and both left the codebase worse than they found it, because they
added an implementation without removing one. `lib/` will presumably end
up as a third example if nobody wires it up.


8. Cost / benefit
-----------------

Costs are engineering effort at the blended internal rate. Benefits are
where this gets difficult.

| Item | Basis | Estimate |
|---|---|---|
| Phase 0–2 engineering | 15 dev-weeks | $ [redacted internally — blended rate x 15] |
| Phase 3–4 engineering | 19 dev-weeks | $ [as above x 19] |
| Risk / Compliance effort | not estimated | unknown |
| Contractor augmentation (optional, Phase 1) | 8 weeks, 1 FTE | quoted separately |
| **Benefit: avoided mispricing exposure** | **depends on Phase 0 population report** | **TBD** |
| Benefit: audit findings closed | 4 High, 5 Medium | qualitative |
| Benefit: cost of change reduced | ~30% of change effort is currently spent establishing what the current behaviour is | qualitative |
| Benefit: single-engineer risk reduced | see R4 | qualitative |

The TBD in the mispricing row is the whole argument and I cannot fill it
in without Phase 0. This is circular and I know it is circular: I need
Phase 0 to justify the programme, and Phase 0 is part of the programme.
The way out is to fund Phase 0 alone, as a three-week discovery, and take
the rest of the decision afterwards. That is my actual recommendation and
if this document gets read at all I would like that to be the sentence
that survives.

I have deliberately not put a number in that cell. A made-up number would
get quoted back at me for years.


9. Phase 3 — Extract underwriting decisioning
---------------------------------------------

Fourteen dev-weeks, six months elapsed, and the elapsed figure is a guess
because it depends entirely on R1.

The decisioning logic is the `if/elseif` chain in `determine_tier()` plus
`calculate_dti()` plus the stipulation matrix, which is currently spread
across the intake page, the underwriter page and a 2018 email that
contains the reason-code list. Extracting it means:

1. Reconstructing the current rules exhaustively, including the branches
   that nobody believes are reachable. The Tier D branch is reachable.
   The zero-income sentinel path is reachable. There is a fifth branch in
   the score bands that I do not think can be hit given the ordering of
   the conditions above it, but I have not been able to prove that, and
   the safest assumption is that it fires for some population we have not
   characterised.
2. Expressing those rules as a table rather than as control flow, so that
   Risk can read them without reading PHP. The table has to be able to
   express the DTI ceiling disagreement — `apply.php` uses 0.43 and
   `conf/loanapp.ini` says 0.45 — which means it has to be effective-dated,
   which means we need to know which value was live when, and I have not
   found a way to establish that from

[draft - MP left the team 2022-08, never finished]

---

*Found in mpatel's home directory on the jump box during the 2023
cleanup and moved here. Nothing in this document was ever formally
reviewed, funded, or actioned. LOAN-2210 remains open with no owner.*
