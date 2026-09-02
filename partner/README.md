# Partner / dealer integration notes

tnguyen, 2023. LOAN-2077.

These are my working notes from the dealer launch. They are not a design
doc — there wasn't one. If you are picking this up, read
`docs/PARTNER_PORTAL.md` first for how the exposure happened, then
`partner/endpoint.php`'s header comment, then this.

**Last updated 2023-09.** I am the only person who has ever onboarded a
dealer. If I'm not around, the steps below are all there is.

## What's here

| File | What it does |
|---|---|
| `endpoint.php` | The only file the DMZ proxy is allowed to reach. Raw XML POST in, hand-built envelope out. |
| `xml_map.php` | Partner XML → internal applicant/loan shape. The rename chain lives here. |
| `submit.php` | Writes `partner_submissions`, then re-implements `apply.php`'s intake insert. |
| `status.php` | GetStatus by reference. |
| `auth.php` | Shared secret, IP allowlist, rate limiter, logging. |
| `wsdl/loanapp.wsdl` | Hand-written. Describes what we meant, not what we do. |
| `bureau_client.php` | jchen's 2017 bureau client. Nothing in the dealer path calls it. It's here because this directory used to be called `integrations/`. |

## Onboarding a new dealer

Six steps. None of them are automated. Allow half a day, plus however long
the dealer's vendor takes.

1. **Get their egress IP range.** Ask for a CIDR; they will send you a
   sentence. Add an entry to `$GLOBALS['partner_ip_allowlist']` in
   `partner/auth.php` and deploy. (The allowlist doesn't actually enforce
   the mask — see the comment on `partner_ip_allowed()` — and the check is
   non-blocking anyway since 2023-07-02, so this step is theatre. Do it
   anyway so the next person sees who is supposed to be connecting.)

2. **Email Thanh for the shared secret.** There is one secret for the whole
   dealer network and no way to issue a per-dealer one. I send it in a
   password-protected zip with the password in a second email, which is not
   a control, it is a ritual. If I'm gone, it's the
   `PARTNER_SHARED_SECRET` define in `partner/auth.php`.

3. **Pick a dealer code** and write it down in the table below. Nothing
   validates it — `endpoint.php` takes whatever the payload says — so this
   list is documentation, not configuration.

4. **Get a sample payload from their vendor** and diff their field names
   against `partner_normalize_field_names()` in `xml_map.php`. Add a
   `str_replace` line for anything new. Watch the ordering: longer names
   have to be replaced before shorter ones they contain, or you get
   nonsense like `nameFullName`. There is no test for this; I check by
   hand with a scratch script.

5. **Send them `wsdl/loanapp.wsdl`** and tell them, explicitly, in writing,
   that `UploadDocument` and `CancelApplication` are not implemented, that
   `applicantName` is truncated to 30 characters despite what the schema
   says, and that they must send `annualIncome`. If you don't say all three
   they will find out the hard way; Cascade and Northgate both did.

6. **Run a test submission and then a GetStatus** against it, from their
   network, with them on the phone. Check the DMZ box's error log — that is
   the only place partner failures show up, because every response is HTTP
   200 (see `partner_soap_fault()`). If you don't watch the log, a broken
   onboarding looks exactly like a working one.

## Current dealers

| Dealer code | Dealer | Onboarded | Notes |
|---|---|---|---|
| `CASCADE01` | Cascade Auto Group | 2023-07 | Biggest volume. Bursts Monday mornings — don't tighten the rate limit without telling them. Asked for a /16 in the allowlist; got a /24 and doesn't know. |
| `NORTHGATE` | Northgate Motors | 2023-07 | Hand-built payloads, all lowercase. Changed egress IPs on a Sunday in 2023-07 and couldn't submit for 11 hours; that's why the IP check is non-blocking now. |
| `VALLEYIMP` | Valley Import Center | 2023-07 | **The reason `LIBXML_NOENT` is on.** Their DMS templates the document with XML entities and doesn't expand them. Turning the flag off breaks them until their vendor changes the template (quoted six weeks and a fee, 2023). Also sends European-formatted amounts occasionally, which parse as garbage. |
| `DEALERBRIDGE` | DealerBridge (aggregator) | 2023-08 | Submits on behalf of rooftops we have no list of. This is why there is no dealer_code allowlist. Sends its own code on submit and the rooftop's code on GetStatus, which is why the ownership check in `status.php` is commented out. |
| `RIDGELINE` | Ridgeline Powersports | 2023-11 | Powersports deals, decisioned as `AUTO`. Mostly copies Cascade's format. |

## Known gaps

Listed because they are real, not because anybody is working on them.

- **Unimplemented WSDL operations.** `UploadDocument` and
  `CancelApplication` are declared in `wsdl/loanapp.wsdl` and have no case
  in `endpoint.php`. Calling them returns the default-branch fault, which
  quotes the caller's entire request back at them.
- **One shared login for the whole dealer network** (LOAN-SEC-19, open).
  No per-dealer identity, so there is nothing to authorize against. This is
  the root cause of the next two items and of the IDOR in `status.php`.
- **No dealer_code allowlist.** Self-asserted, unvalidated. The dealer
  volume report has shown codes nobody recognises.
- **The secret has never been rotated** and cannot be rotated without a
  same-day cutover with all five dealers — there's no support for two
  valid secrets at once. It is also written into the log line by
  `partner_log()`, so it's in the log aggregator too.
- **`status.php` has no ownership check.** Reference is the raw sequential
  `loans.id`. Any holder of the shared secret can walk it and read
  applicant names, SSN last 4, amounts, tiers and decisions for every loan
  in the system, dealer-channel or not.
- **Every failure returns HTTP 200.** The proxy's monitoring has shown
  100% success since 2023-07-14. It means nothing.
- **`LIBXML_NOENT` is on** for one dealer's payload format. Deferred
  pending their re-onboarding. Two years and counting.
- **Names are truncated to 30 characters** by `trunc30()`, for a MySQL
  column that was decommissioned in 2020. `map_partner_v2()` fixes it and
  is behind `FLAG_PARTNER_XML_V2`, which is off because turning it on
  changes tiers and APRs and Risk wanted to model that first. The
  modelling was never scheduled.
- **Missing income becomes 45000.** Roughly a fifth of dealer submissions
  are priced on that number and nothing on the loan record says so.
- **The APR we quote a dealer is `apply.php`'s formula** and the APR
  `admin.php` recomputes is the post-LOAN-1341 one. On a $30k auto deal
  those differ by 40bps and the dealer channel is almost entirely $30k
  auto deals. Two dealers have asked. Both were told the first number was
  "indicative".
- **Retries are not idempotent** (LOAN-2388, closed "could not
  reproduce"). Cascade reported a double-submitted deal in 2024-03; it was
  closed as a dealer-side double-post. I don't think it was.

## Things I'd do first, if there were time

1. Per-dealer credentials. Everything else on the list gets easier once
   there is an identity to check.
2. Opaque references, which kills the IDOR.
3. Turn `FLAG_PARTNER_XML_V2` on, after somebody models the tier impact.
4. Put the 5xx responses back, once the proxy monitor is fixed.

— tnguyen, 2023-09
