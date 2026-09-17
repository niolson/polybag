# Find out which FedEx sandbox rate requests come back intact

Status: done
Category: research
Type: manual
Repo: **`polybag`**

## Parent

`docs/issues/fedex-sandbox-rate-testing/PRD.md`

## Problem

`FedexAdapter::buildRateApiRequest()` throws away the caller's rate request in sandbox
mode and sends FedEx's US-domestic docs example instead. The comment above it gives the
reason:

> The FedEx sandbox returns truncated (unparseable) JSON for most request shapes. The
> example payload from the FedEx developer docs is the one known request that produces a
> valid, complete response from the sandbox API.

"Most request shapes" is the whole difficulty. Nobody has characterised which shapes fail
or why, so the workaround had to be total — replace everything — and a total replacement
makes international rating unreachable in sandbox, since the destination is overwritten
before the request is sent.

This cannot be reasoned out from the code. It needs real requests to the sandbox and a
record of what comes back.

`ready-for-human` because it needs FedEx sandbox credentials on a carrier account, and
because `sandbox_mode` is a shared cross-carrier toggle that should be flipped by hand —
turning it on moves UPS, USPS and Amazon at the same time.

## Before starting

- Rate quotes are read-only. No postage is bought and nothing is spent.
- The two international rate fixtures already in the repo are FedEx's own documented test
  cases. Start from those rather than composing new payloads — a request the sandbox was
  built to accept is the one useful control.
- `FedexTestCaseRunner` sends fixtures straight through `FedexConnector`, so it bypasses
  `buildRateApiRequest()` entirely. That is the harness for this work. It currently has no
  rate branch, so getting a rate fixture to execute is step one and is small.
- Capture raw payloads to `.scratch/` — gitignored, and rate responses carry addresses.

## What to answer

1. **What does "truncated" actually look like?** Capture a failing response verbatim.
   Is it valid JSON cut short, a partial body with a 200, a content-length mismatch, or a
   gateway-level truncation? This decides whether it is detectable and retryable rather
   than something to route around.
2. **What distinguishes a request that succeeds from one that does not?** Vary one thing
   at a time from the known-good docs example: payload size, number of requested service
   codes, `rateRequestType`, presence of `requestedPackageLineItems` detail, special
   services, and the international/domestic split. The goal is a rule, not a list.
3. **Does either committed international rate fixture come back intact, sent verbatim?**
   This is the gate. If FedEx's own international test cases answer properly, the sandbox
   is usable for international rating and the adapter's blanket override is the only thing
   in the way.
4. **Does the sandbox rewrite addresses or force domestic services on a request that
   matches a canned case?** Compare what was sent against what the response describes.
5. **Can `buildRateApiRequest()`'s override be narrowed** to the shapes that actually
   fail, rather than every request? If yes, sketch the condition.

## Acceptance criteria

- [x] `FedexTestCaseRunner` executes a rate fixture — a `Rates` branch in its request-type
      match, keyed off `requestType`, alongside the existing shipment branches
- [x] The two international rate fixtures run verbatim against the sandbox, and their
      `supported` / `skip_reason` fields are updated to reflect what actually happened
- [x] Raw request and response pairs captured to `.scratch/`, including at least one
      truncated response
- [x] Questions 1–5 answered in this file's `## Comments`
- [x] A recommendation recorded for `buildRateApiRequest()`: narrow the override, keep it
      as is, or remove it in favour of the runner path
- [x] If the override stays, its comment is updated to say what is now known about which
      shapes fail — it did not stay; the docblock on `buildRateApiRequest()` now carries
      what is known instead

## Out of scope

- Restoring fabricated international rates. A mock answers whether our parsing works on a
  payload we wrote, not whether FedEx accepts the request, and the second is the question.
- Production FedEx international rating, which is unaffected by any of this — the override
  is sandbox-only.
- `tests/External/Fedex/`, which currently holds nothing but a `.gitkeep`, so
  `composer run test:fedex-reference` runs an empty suite. Worth its own issue once there
  is something reproducible to assert.

## Blocked by

None. Needs a FedEx carrier account with sandbox credentials.

## Comments

### 2026-09-05 — filed

Split out of the dead-code review in #187, which removed `getMockInternationalRates()`,
the `INTERNATIONAL_SERVICE_CODES` constant, and the two commented-out call sites that
would have returned fabricated international rates in sandbox mode. PHPStan's baseline
had been carrying entries asserting both the constant and the method were unused, so the
code had been suppressed rather than removed for some time.

The removal is not the reason this issue exists — the need it was reaching for is, and it
predates the mock. See the parent PRD for why the two layers of domestic forcing, ours
and FedEx's, are easy to mistake for one.

### 2026-09-17 — measured, override removed

`sandbox_mode` was already on locally and the FedEx carrier account carries sandbox
credentials, so this ran the same day. ~60 rate requests sent straight through
`FedexConnector` from a scratch probe (`.scratch/fedex-sandbox-rates/`, raw request and
response bytes per case), then the two fixtures through the runner.

**How the sandbox actually works.** It is a service-virtualisation layer in front of a
rating engine, and the two behave nothing alike:

- A request whose **shape** matches one of a handful of canned cases gets that canned
  body back in ~300 ms, flagged `VIRTUAL.RESPONSE` in `output.alerts`. 18 of 18 such
  requests answered, and the same shape returned byte-identical bodies every time.
  Matching is on which fields are present, not their values: postal codes, country
  codes, weights and ship dates were all varied without changing which canned body came
  back. A destination in CA or GB got the same US-domestic body as one in NY.
- Anything that matches no canned case goes to the live rating engine, which is
  **effectively down**: ~50 attempts, 2 answers, the rest `503 SERVICE.UNAVAILABLE.ERROR`
  after a constant 3.2–3.6 s, which reads as a gateway timeout in front of an engine
  that is not responding. The two answers were real quotes — today's date, the
  addresses actually sent (`98052 WA -> 10001 NY`, with a `SIGNATURE_OPTION` surcharge
  for the request that asked for one), no `VIRTUAL.RESPONSE` alert — but two samples
  say only that the engine exists and is not the virtual layer. Nothing below depends
  on it behaving any particular way.

Everything decided here rests on the canned path, which is deterministic. The live
path matters only as the place requests go when they leave the canned path: the docs
example lands there now, so did both fixtures, and so does any adapter request carrying
Saturday, One Rate, declared value or SmartPost.

Three canned bodies were seen, keyed by shape:

| Shape | Canned body | State |
|---|---|---|
| no `packagingType`, no `shipDateStamp`, `rateRequestType` of exactly one value | 60 897 bytes, 9 services, `quoteDate` 2026-09-14 | complete |
| `packagingType` + `shipDateStamp` (either `pickupType`, any packaging value, any addresses, any weight) | 46 274 bytes, 7 services, `quoteDate` 2023-07-27 | complete |
| `shipDateStamp` **without** `packagingType` | 32 199 bytes | **cut off** |

**Q1 — what "truncated" is.** The third canned body. HTTP 200, `Content-Type:
application/json`, `Content-Length: 32199`, and the body is exactly 32 199 bytes ending
mid-key at `"fuel`. Same bytes on every attempt. So it is a broken fixture stored on
FedEx's side, not a network or gateway truncation — deterministic, and not retryable.
Captured at `.scratch/fedex-sandbox-rates/24-shipdate/`.

**Q2 — what distinguishes success.** Not size, not service count, not the
international/domestic split: presence of fields. `packagingType` is the pivot. With it
and a ship date, the canned domestic body comes back complete; with a ship date and no
packaging type, the cut-off one. Anything the canned cases do not cover — `dimensions`,
a second line item, `serviceType`, `preferredCurrency`, `declaredValue`,
`shipmentSpecialServices` (Saturday, One Rate), `smartPostInfoDetail`,
`rateRequestType` with two values — leaves the virtual layer and meets the 503.
`packageSpecialServices` with `SIGNATURE_OPTION` is the one extra that reached the
engine and got a live answer today.

**Q3 — the two fixtures verbatim.** Both 503 (16 of 16 direct attempts across ~90
minutes, plus runs through `fedex:run-test-cases --suite=rate`, 3 tries each). They
match no canned case and the engine was not answering. On the one runner attempt where
it did, IntegratorLAC04 got `400 COUNTRY.POSTALCODE.INVALID` for its origin — the source
gives Mexico City as `1210`, four digits where the country uses five, which looks like
a leading zero lost in FedEx's markdown. The CA case was never rejected. So the gate is
open in principle and closed in practice today; the fixtures are `supported: true` with
a note each, and the CA one will answer when the engine does. The LAC one probably needs
`01210` first, which is left for a day the engine can confirm it.

A review pass caught that the runner was *not* sending these verbatim: the normaliser's
shipment fix-ups were adding a `totalCustomsValue` to the CA case and removing
`totalWeight` from the LAC one — exactly the kind of shape change the sandbox keys on.
Rate fixtures now bypass the fix-ups (placeholders aside) and the sent payload was
diffed against the fixture file to confirm. The 16 direct probe attempts were always
verbatim; only the runner path was affected.

**Q4 — does the sandbox rewrite addresses.** The virtual layer ignores them entirely and
answers with its own lane (`US 65247 MO -> US 75063 TX` in the body the adapter's shape
matches) — established across every canned response. The live engine honoured them in
the two answers it gave, which is suggestive and no more. Both add `ORIGIN/DESTINATION.STATEORPROVINCECODE.CHANGED`
alerts, which is just the engine filling in the state we never send.

**Q5 — the override.** Removed, not narrowed. Two facts decided it:

1. The docs example it substituted — `DROPOFF_AT_FEDEX_LOCATION`, `rateRequestType:
   [ACCOUNT, LIST]`, no packaging type — no longer matches any canned case and 503s on
   every attempt. Through the adapter that meant **0 FedEx rates in sandbox mode, after
   12 s of retries**. The override had become the thing it was guarding against.
2. The request the adapter builds anyway — `packagingType`, `shipDateStamp` (which
   `ShippingRateService` always sets), `USE_SCHEDULED_PICKUP`, `rateRequestType:
   [ACCOUNT]`, a weight-only line item — matches the complete 46 274-byte canned body.
   Through the adapter with the override disabled: **7 domestic rates in 360 ms**.

The cut-off body is matched by a shape the adapter never builds (it always names the
packaging), and `extractRateDetails()` already turns an undecodable body into an empty
collection with a warning, so there is nothing left for the override to route around.
A request with Saturday delivery, One Rate, declared value or SmartPost goes to the
engine and, today, comes back empty like anything else the sandbox cannot answer — the
same result the override produced for *every* request.

`FedexSandboxInternationalRates` stays: an international destination through the
adapter's shape still gets the canned domestic body, so the stand-in is still the only
way an international service reaches the Ship page in sandbox mode. Its docblock and
`quotesInternationalLocally()`'s now say "answers this shape with a canned response"
rather than "rewrites the addresses of the canned payload", which was the wrong layer.

**Also done here.** `FedexTestCaseRunner` has the `rate` branch: it summarises services
quoted, the lane the response describes, and whether the body was canned; it creates
no `Package` for a quote; a 200 that quotes nothing is a failure (`NO_RATES`), not a
pass; and an undecodable body is reported as `UNDECODABLE_RESPONSE` with the raw bytes
saved under `--dump-payloads` rather than thrown. The normaliser sends rate payloads as
written.

**Left open.** `tests/External/Fedex/` is still empty; a reference test asserting the
canned body's shape would pin what the adapter now depends on, but the sandbox is not
under our control and a test that fails when FedEx regenerates its fixtures is a
judgement call for whoever writes it. The two rate fixtures need re-running when the
sandbox engine is answering — the note on each says how to tell.
