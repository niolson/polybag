# UPS Saturday delivery: drop the calendar guess and the unreachable retry, read what rating returns

Status: done — 2026-09-16

Repo: `polybag`

## Problem

Two things, found in the same code and now one fix.

**The ship-time retry has never run.** `UpsAdapter::createShipment()` handles a UPS
refusal of Saturday delivery by retrying without it, gated on
`$saturdayApplied && ! $response->successful()`. That condition is never true: `UpsConnector`
sets `$tries = 3` and Saloon's `throwOnMaxTries` defaults to `true`, so a failing request
throws a `RequestException` out of `sendCreateShipment()` rather than returning a failed
response, and the adapter's outer `catch` turns it into `ShipResponse::failure()` before
either branch is reached. The `$errorMessage` block below it, which reads
`$responseData['response']['errors'][0]['message']`, is unreachable for the same reason.
Observed while fixing `24`, by writing a test for the retry that could not be made to pass;
`RetriesTransientErrors::handleRetry()` already declines to retry 4xx, so this is not the
transient path misbehaving.

**The rate path guesses eligibility from a calendar instead of asking UPS.**
`saturdayDeliveryDayMap()` hardcodes service → weekday (`01` Friday, `02` Thursday, `12`
Wednesday …), `classifySaturdayEligibility()` compares that to the ship date, and the result
decides whether `SaturdayDeliveryIndicator` goes on the shop request at all, or whether a
second request is sent and merged over the first (`parseRateResponse()`'s "mixed" branch).
None of the per-service Saturday fields UPS returns are read, and the selected rate carries
nothing that says whether it was a Saturday quote — `createShipment()` re-reads the
operator's checkbox and sends the indicator regardless of what was quoted.

## What rating actually returns

Probed 2026-09-16 against the CIE sandbox, `Shoptimeintransit` (the mode the adapter uses —
no `Shipment.Service` in the body; service filtering is client-side), a 5 lb box across a
one-day lane, on a Wednesday, Thursday and Friday ship date. Raw captures and the probe script in `.scratch/ups-saturday-rating/`.

| Sent in `ShipmentServiceOptions` | Result |
|---|---|
| nothing | Every service's weekday variant **plus a Saturday variant** of each service that can reach Saturday from that ship date (Fri: `14`, `01`, `03`; Thu: `02`; Wed: `12`). Same `Service.Code` twice. |
| `SaturdayDeliveryIndicator` | **Only** the Saturday variants. A filter, not an error. |
| `AvailableServicesOption: 1` | Byte-identical to sending nothing. |
| both | `400 111701 — The requested accessory is not allowed with the selected Delivery Day Request` |

A Saturday variant is unambiguous in the response: `TimeInTransit.ServiceSummary.SaturdayDelivery`
is `"1"` (weekday rows say `"0"`), `EstimatedArrival.DayOfWeek` is `SAT`, the arrival date is
a Saturday, `ItemizedCharges[]` carries code `300` for the surcharge, and
`SaturdayDeliveryDisclaimer` reads "Saturday Delivery is available for an additional charge."
So the day map is reproducing, less reliably, a thing the response already says per row, and
the two-request mixed dance exists to reconstruct a list UPS hands over in one call.

`AvailableServicesOption` is not the answer here: in shop mode the indicator is honoured
and the option is a no-op, and the schema's "indicator ignored" wording turns out to
describe a combination UPS rejects outright.

## The duplicate that is live today

With `saturday_delivery` **not** requested, the plain shop request on a Friday returns
`01` twice: $87.17 arriving Saturday and $66.21 arriving Monday. `extractRateDetails()`
pushes both, with identical `metadata`, and nothing downstream collapses them
(`ShippingRateService` has no `unique`/`keyBy` on service code). The Ship page shows two
"UPS Next Day Air" rows at different prices with nothing to tell them apart. Picking the
dearer one buys a Monday label at the Monday price — no money lost, but a rate the operator
was never meant to see. Observed in the sandbox; confirm against a production
`RATE RESPONSE` in the `ups-validation` log on a Friday before treating the weekday-only
rows as the only production shape, though nothing about the fix depends on it.

## What to build

One shop request, no calendar, the selected rate says what it is.

1. **`extractRateDetails()` reads `ServiceSummary.SaturdayDelivery`.** When
   `saturday_delivery` was not requested, drop rows where it is `"1"`. When it was, keep only
   rows where it is `"1"` (which is what the indicator already gives) and tag them
   `metadata['saturday_delivery'] = true`. The row's own delivery date is already Saturday,
   so the Ship page needs nothing extra to show it.
2. **`createShipment()` sends `SaturdayDeliveryIndicator` from the selected rate's
   metadata**, not `$request->hasSpecialService()`. The label then matches the quote the
   operator chose and `appliedServices` on the `ShipResponse` is truthful.
3. **Delete** from `UpsAdapter`: `saturdayDeliveryDayMap()`, the `adjustRequestForSaturday()`
   calls in `getRates()` / `prepareRateRequest()`, the mixed-mode follow-up in
   `parseRateResponse()`, the ship-time retry, and the unreachable `$errorMessage` block, so
   the adapter has one error path. `buildRateApiRequest()` keeps sending the indicator when
   requested — that is the filter. If `HasSaturdayDelivery` is then FedEx-only, leave it
   there; whether FedEx's rate response can carry the same per-row signal is a separate
   question and not this issue.
4. **The `unset()` in the dead branch** was made surgical while fixing `24` so it would not
   strip the customs invoice; with the branch gone that concern goes with it.

## Test notes

- Fixture from the Friday capture: one shop response with `01` twice, `SaturdayDelivery`
  `"1"` and `"0"`, and `12` once. Assert three outcomes of `extractRateDetails()`: not
  requested → one `01` at $66.21, no tag; requested → one `01` at $87.17 tagged; a
  response with no Saturday rows and Saturday requested → empty, no second request sent
  (`MockClient` with one response, assert one request).
- `createShipment()` with a selected rate tagged Saturday sends the indicator; untagged, with
  the request flag still set, does not. `appliedServices` follows the tag.
- The `RequestException` path is the only failure path: a mocked 400 produces a
  `ShipResponse::failure()` with the UPS message and the fake records one attempt — the test
  that could not pass before, now passing for the right reason.

## Related

- `24` — where the dead branch was found, and the surgical `unset()` that this removes
- `carrier-request-schema-validation` — the schema said `AvailableServicesOption` was the
  shop-mode Saturday switch; the wire said otherwise

## Comments

**2026-09-16 — probed, decided.** Opened as `needs-triage` with three options: revive the
retry, delete it, or leave it dead. The question "is the ship-time retry the right place to
learn Saturday is unavailable?" led to the rating API, and the probe above showed UPS
already answers per row in the one request the adapter sends. That makes option 2 the whole
of it — the retry, the day map and the second request all go, replaced by reading the
response. Moved to `ready-for-agent`; the duplicate-row finding is the part that affects
operators today.

**2026-09-16 — shipped.** `UpsAdapter` sends one shop request and reads
`TimeInTransit.ServiceSummary.SaturdayDelivery` per row in `extractRateDetails()`: without
`saturday_delivery` requested the `"1"` rows are dropped, which is what ends the duplicate
Next Day Air row on a Friday; with it requested only the `"1"` rows are kept and each carries
`metadata['saturday_delivery'] = true`. `createShipment()` sends `SaturdayDeliveryIndicator`
from that tag, not from the request flag, and `appliedServices` follows it. Deleted:
`saturdayDeliveryDayMap()` and the `HasSaturdayDelivery` trait use (now FedEx-only, untouched),
the `adjustRequestForSaturday()` calls, the mixed-mode follow-up in `parseRateResponse()`,
the ship-time retry and the `$errorMessage` block it fed. The one failure path is now a
`catch (RequestException)` that reads `response.errors.0.message` off the thrown response,
the way `trackShipment()` already did — so a mocked 400 produces `ShipResponse::failure()`
with UPS's wording after exactly one attempt, the test that could not be written under `24`.
Tests in `UpsAdapterTest` under "Saturday delivery — read off the rate response", built from
the Friday capture. Not done: the production `RATE RESPONSE` check on a Friday that the
duplicate-row section suggests; nothing in the fix depends on it.
