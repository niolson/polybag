# Quote direct-carrier rates behind an opaque identifier, restored server-side at purchase

Status: done — shipped 2026-09-18; every rate the Ship page lists is a `ShippingOffer`, direct rates included, and a direct purchase restores carrier, service, price and metadata from the row

Repo: `polybag`

## Parent

[ADR-0002](../../../adr/0002-postage-source-split.md) decision 4 gave an Amazon or
Shopify offer an opaque `offerId`, with everything that can buy the label kept on the
`ShippingOffer` row so that the browser names an offer and restates nothing. Direct
carrier rates — USPS, FedEx, UPS quoted from a `CarrierAccount` — got no such row, because
no purchase authority is issued at quote time and there was nothing to protect.

Raised by code review of `packaging-form-and-carrier-identity/01` (2026-09-16), twice.

## Problem

A direct-carrier rate crosses the Ship page as Livewire state and comes back through
`RateResponse::fromArray()`. Everything the purchase then sends is restated by the
browser: carrier, service code, price, and the metadata the adapter reads with no
fallback — USPS's `mailClass` / `rateIndicator` / `processingCategory`, FedEx's
`serviceType` / `isOneRate` / packaging, UPS's `serviceCode`. `RateQuoteLogger::markSelected()`
matches the logged quote on carrier and service code only, so it cannot tell USPS
variants apart and cannot be used as the server's copy.

Two consequences today, neither new:

- The recorded cost is whatever the browser said the price was.
- Any label the account can buy is buyable by editing state, whether or not it was
  ever quoted for this Package.

And one that ADR-0005 makes sharper. `CarrierAdapterInterface::packagingRequirementFor()`
classifies a rate's packaging requirement from the same metadata the ship body sends, so
the purchase-time packaging check is *consistent* — no metadata can classify as the
shipper's packaging while buying the carrier's — but not *authoritative*: it re-derives
from browser state rather than from something the server quoted. The reviewer's remedy
was to persist, cache or sign the quoted rate behind an opaque identifier and classify
the server-restored rate, which is exactly what `rateFromOffer()` already does for an
offer.

## Shape

Extend the offer model to direct carriers: every rate `ShippingRateService` returns gets
a `ShippingOffer` row with no purchase authority and an opaque id; the browser sends the
id; `buyPostage()` restores carrier, service, price and metadata from the row exactly as
it does for an Amazon offer, and the packaging check runs on the restored rate.
`markSelected()` then matches on the id rather than on carrier and service code.

## Decisions (triage 2026-09-18)

Each of the questions the ticket opened with, and what was found when it was looked at.

**Same table.** A direct rate is a `ShippingOffer` with `postage_source = CarrierAccount`,
`carrier_account_id` set and `purchase_context` null. Nothing else changes shape:
`OfferStore::issue()` already takes a `carrierAccountId`, `accountNoLongerResolves()`
already handles one, `PostageSourceDispatcher::sellerFor()` already routes a
`CarrierAccount` offer to the carrier adapter, and `rateFromOffer()` already restores
everything. The "no authority" state is a null column, not a second table with its own
purge, archive hook and `DemoReset` entry.

**Redeem, but do not let a timeout strand the package.** Today a consumed offer whose
purchase timed out is settled on the next attempt by `settleEarlierPurchases()` asking the
seller through `RecoversUnresolvedPurchase` — which only Amazon implements, because only
Amazon has an idempotent purchase call. A seller that cannot answer leaves the package
refusing every purchase with *Earlier Purchase Unresolved*, and there is no UI for that
state; clearing it means editing the row. None of USPS, FedEx or UPS can implement the
contract (FedEx's `customerTransactionId` and UPS's `transId` are echoed, not
deduplicated; USPS has nothing), so for direct rates that state would be terminal after a
single dropped connection — a regression from today's *try again*.

`redeem()` is still wanted: its conditional `UPDATE` is the only thing that stops a
double-click or two workstations buying the same direct label. `withBlindPurchaseLock()`
covers blind purchases only; a direct purchase is protected by nothing but the
`status === Shipped` read, which races. So: redeem for the atomic claim, and in the
`RequestTimeOutException` catch, when the seller is not a `RecoversUnresolvedPurchase`,
call `recordFailure($offer, 'timeout')` so the package stays buyable — today's behaviour
plus the claim. A seller that *can* recover keeps the strict block. An admin action for
the unresolved state is a separate issue, since even Amazon's recovery can return `null`
indefinitely (`16`).

**Expiry: end of the quoted ship day, plus a staleness check on the package.** Nothing
about a direct rate moves intra-day — FedEx and UPS fuel surcharges change weekly, on the
ship date; demand surcharges on announced dates; USPS twice a year. And nothing sits: the
Ship page re-quotes on `mount()`, so a package revisited next week is re-quoted; the local
data has 145 of 155 shipped packages bought within an hour of their first quote and one
after a week. The stale case is a tab left open across a package edit, and the Ship page
already keys its rate cache on `package.updated_at` and `shipment.updated_at` for exactly
that reason. The offer stores the same pair at issue and `inspect()`/`redeem()` reject
when either has moved, so the rate cache's staleness rule is the offer's staleness rule
and the row is trustworthy as the price for *this* package, not just *a* price.
`expires_at` is the end of the quoted ship date in the location's timezone; not minutes.

**Issue in `ShippingRateService::getShippingRates()`, next to `logRates()`.** One loop
that no adapter can bypass and a new adapter cannot forget, and the `rate_quotes` row and
the offer are written together so they can point at each other. The cost is a
`carrierAccountId` on `RateResponse` that each direct adapter fills from the account
`ResolvesCarrierAccount` gave it — `ShippingRateService` never sees the account today —
and rows on the batch and auto-ship paths that nothing restores from (about 3.6 per
package on the local data, on a 7-day purge). Amazon already issues on those paths from
inside `getRates()`; it can keep doing so, or move to the shared loop, whichever is
smaller.

**Trust is decided by entry point, not by a flag.** `ship()` has one caller, the Ship
page; `autoShip()` is called by `GenerateLabelJob`, Pack and Manual Ship. So `ship()`
requires `selectedRate->offerId` and refuses a rate without one, and `autoShip()` keeps
passing server-built rates through `buyPostage()` unrestored. A rule's pre-selected rate
from `resolvePreSelectedRate()` never passes through `getShippingRates()` and so carries
no id — but it only reaches `buyPostage()` through `autoShip()`, which is the trusted
side. "Restore must be optional" becomes "restore is required on the only path the
browser reaches".

**`rate_quotes` stays; the offer points at its row.** The two tables are different
things: `rate_quotes` is the analytics log (long retention, one row per rate ever
offered, `selected` flag feeding *Rate Comparison* and the questions the log was made
for — what the other options would have cost), `shipping_offers` is the transactional
record. Add a nullable `shipping_offers.rate_quote_id`, set in the shared loop, and
`markSelected()` becomes an update by that id. `logRates()`' bulk `insert` becomes one
that returns ids. Folding the tables was considered and rejected: it would force one
retention policy onto two purposes, which is what the offers migration comment warned
against. Logging quotes on the paths that *do not* rate-shop (a rule's pre-selection, a
blind purchase) so the log can answer "was that too expensive?" is its own issue (`17`).

**Metadata is sufficient and bounded — checked.** Every key each adapter reads at ship
time is a key it wrote at quote time, and each array is two to four flat strings:
USPS `mailClass` / `processingCategory` / `rateIndicator` / `destinationEntryFacilityType`;
FedEx `serviceType` / `packagingType` / `isOneRate`; UPS `serviceCode` / `packagingCode`
/ `saturday_delivery`. Nothing reads off the raw carrier response. `rate_metadata` is
already a `json` column, and each adapter's `classifyPackaging()` runs off the same keys,
so re-classifying the restored metadata through `packagingRequirementFor()` is the
authoritative check the reviewer asked for, with no new field.

**Re-quote hygiene: nothing.** 144 of 164 local packages were quoted once; the rest a
handful of times. With the `updated_at` check above, a superseded price is already caught
when it is the package that changed, and the purge handles the rest.

## What to build

1. `RateResponse` gains `?int $carrierAccountId`, carried through `toArray()` /
   `fromArray()`. `UspsAdapter`, `FedexAdapter`, `UpsAdapter` and `FakeCarrierAdapter`
   set it from the account they resolved. Amazon leaves it null (its offers name a data
   source instead).
2. `shipping_offers` gains `rate_quote_id` (nullable FK, `nullOnDelete`),
   `package_updated_at` and `shipment_updated_at` (nullable timestamps). `OfferDraft`
   carries the three.
3. `ShippingRateService::getShippingRates()` writes each `rate_quotes` row and, for every
   rate without an `offerId` already, issues a `ShippingOffer` against it and returns the
   rate with `offerId` set. A rate that already carries one — Amazon issues its own inside
   `getRates()`, holding purchase tokens the shared loop never sees — keeps its offer, and
   the loop stamps that offer's `rate_quote_id` so `markSelected()` can find its row too.
   `RateQuoteLogger::logRates()` returns the ids it inserted. The order matters: the
   packaging filter runs first (unchanged), then the log, then the offers, so a rate never
   offered never holds an id. The ship date each carrier is quoted for is read once, before
   the carrier calls, and the same date closes the offer's window — read again afterwards,
   a pickup cutoff or an End of Day run in between would give the offer a later day than
   the price was quoted for.
4. `OfferStore::inspect()` and `redeem()` reject an offer whose stored
   `package_updated_at` / `shipment_updated_at` no longer match the package, with a
   rejection that asks for a re-quote (new `OfferRejection` case, e.g. `PackageChanged`).
5. `EloquentPackageShippingWorkflow::ship()` refuses a `selectedRate` with no `offerId`
   (a `PackageShippingResult::offerUnavailable` asking for a re-quote, never a purchase).
   `autoShip()` today delegates to `ship()`, and a rule's pre-selected rate from
   `resolvePreSelectedRate()` deliberately has no id, so the refusal cannot sit in the
   shared body: the locks and the purchase move to a private `purchase()` that both entry
   points call, `ship()` checks for the id before calling it, and `autoShip()` calls it
   directly. Unattended behaviour is unchanged.
6. `buyPostage()` already restores from the offer when there is an id; direct rates now
   take that branch. In the `RequestTimeOutException` catch: if `$offer !== null` and the
   adapter is not a `RecoversUnresolvedPurchase`, `recordFailure($offer, 'Timed out; the
   carrier did not answer')` before returning the existing result.
7. `RateQuoteLogger::markSelected()` takes the offer (or its `rate_quote_id`) and updates
   by primary key. The carrier + service-code match goes.
8. `selectedRateIndex()` keeps its carrier + service-code fallback: a rule's pre-selected
   rate arriving at `prepareRates()` still has no id of its own and must find its row in
   the list.

## Tests

- Feature: a direct USPS rate quoted through `getShippingRates()` carries an `offerId`;
  the offer row has `postage_source = CarrierAccount`, the account id, null
  `purchase_context`, the rate's metadata, a `rate_quote_id` pointing at the logged
  quote, and `expires_at` at the end of the ship day.
- Feature: `ship()` with a `RateResponse` whose `offerId` is null is refused before any
  adapter is called. `autoShip()` with the same rate buys.
- Feature: `ship()` with a tampered `RateResponse` (price, `serviceCode`, `mailClass`
  changed) buys what the offer row says, and `packages.cost` is the row's price.
- Feature: editing the package between quote and purchase is rejected as
  `PackageChanged`; the Ship page re-quotes.
- Feature: two concurrent purchases of one direct offer — second is `AlreadyConsumed`.
- Feature: a `RequestTimeOutException` from `FakeCarrierAdapter` leaves the offer
  resolved as failed and the package buyable on retry; the same from a
  `RecoversUnresolvedPurchase` fake leaves it unresolved and blocks.
- Feature: two USPS variants of one mail class, second selected — only the second
  `rate_quotes` row is `selected`.
- The ten existing test files that build `PackageShippingRequest(selectedRate: …)`
  against `ship()` either issue an offer first (a factory state on `ShippingOfferFactory`
  for a direct rate) or move to `autoShip()`, whichever the test is actually about.

## Acceptance criteria

- [x] Every rate the Ship page lists has an `offerId`, direct or resold.
- [x] A direct purchase from the Ship page sends the id and nothing the server reads;
      carrier, service, price and metadata come off the row.
- [x] The purchase-time packaging check classifies the restored rate.
- [x] `markSelected()` marks exactly the quoted variant.
- [x] A carrier timeout on a direct purchase does not strand the package.
- [x] Batch ship and auto-ship behave as before.
- [x] `PurgeData` needs no change (direct offers are unconsumed or resolved, so they age
      out; a consumed-unresolved direct offer cannot exist after step 6).

## Not this issue

- The packaging check's *consistency* invariant — that each classifier reads exactly the
  fields the ship body sends and refuses unknown ones — lives on the interface docblock;
  `packaging-form-and-carrier-identity/05` honoured it for USPS on 2026-09-16.
- Blind purchases (`blindPurchaseOffersFor()`), which already carry no rate.
- An admin action to resolve an unresolved offer by hand — `16`.
- Logging quotes for rule-selected and blind purchases so the log can answer "was that
  too expensive?" — `17`.

## Blocked by

None. `packaging-form-and-carrier-identity/05` shipped 2026-09-16, so direct USPS
classifiers already return flat-rate requirements from browser-restated metadata; this
issue is what makes that check authoritative.

## Comments

**2026-09-18** — Triaged. The seven open questions were answered against the code and the
local data rather than in the abstract; findings are under *Decisions*. Two follow-ups
opened: `16` (unresolved-offer admin action) and `17` (shadow quoting for rule and blind
purchases). `ready-for-agent`.

**2026-09-18** — Shipped, all eight steps as written. `RateResponse` carries
`carrierAccountId`, stamped by USPS, FedEx (both parsers and the sandbox international
stub), UPS and the fake adapter — the fake now resolves an account through
`ResolvesCarrierAccount` so fake mode exercises the purchase's account check.
`shipping_offers` gained `rate_quote_id`, `package_updated_at` and `shipment_updated_at`;
`OfferStore::inspect()` / `redeem()` refuse with a new `OfferRejection::PackageChanged`,
compared against the package as the database has it, at whole-second precision, and skipped
for an offer that recorded no version. `ShippingRateService::getShippingRates()` runs one
shared loop after the packaging filter: log the quotes (now returning ids), issue a
`CarrierAccount` offer for every rate without one, point an offer the adapter issued itself
(Amazon) at its quote row. `ship()` refuses a rate with no `offerId`; `autoShip()` goes
through a new private `purchase()` — the lock and the blind-purchase lock, formerly the body
of `ship()` — so a rule's pre-selection still buys unrestored. `markSelected()` takes the
offer and updates by its `rate_quote_id`. The timeout catch resolves a claimed offer as
failed when the seller is not a `RecoversUnresolvedPurchase`.

Steps 3 and 5 above were amended on review the same day, after the code had shipped: the
first draft said `autoShip()` was "unchanged", which would have had it inherit `ship()`'s
refusal, and said nothing about an Amazon offer's quote row or about the ship date being
read twice. The amended text describes what was built; the ship-date read was the one
change the review caused — `getShippingRates()` now reads one date per carrier and hands
it to both the carrier call and the offer, proved by a test that the date is read once.

Two things worth knowing that *What to build* did not spell out:

- The end-of-ship-day expiry is computed in the location's timezone and Eloquent's datetime
  cast drops the zone on write, so `OfferStore::issue()` moves `expiresAt` into the app
  timezone first. Without that the window closed four hours early in the tests.
- The offer's `rate_metadata` also carries the packaging requirement under the same key
  Amazon's does, so the rate restored from a direct offer keeps the requirement the adapter
  stamped at quote time for display; the purchase re-check still asks the adapter, which
  reads only its own keys.

Tests: `DirectCarrierOfferTest` (quote → offer row, tampered purchase, restored-rate
packaging check, `PackageChanged` at the workflow and on the Ship page, one claim per offer,
second purchase of a spent offer, exact-variant `markSelected()`, auto-ship through rate
shopping), the timeout pair and the offer-less refusal in `OfferRedemptionOnShipTest`, the
store-level `PackageChanged` pair in `OfferStoreTest`, and `RateQuoteLoggerTest` rewritten
for id-based marking. The ten existing files that shipped a hand-built rate now issue a
direct offer through `quotedDirectly()` in `tests/Pest.php`; the one that was *about* a rate
with no offer became the refusal test.

**2026-09-18, later** — Review challenged the timeout carve-out: a timeout is not a
declined purchase, and `recordFailure()` on it lets a retry buy a duplicate. The carve-out's
premise — that no direct carrier can be asked what happened — was then checked against the
carrier specs and is wrong for two of three: USPS reprints by `X-Idempotency-Key` and UPS
recovers a label by `ReferenceNumber`, and both bill an orphan. Neither key is sent today.
`18` opened for the recovery work; `resolveTimedOutOffer()` already keys on
`RecoversUnresolvedPurchase`, so each adapter leaves the carve-out as it implements the
contract, and FedEx — which does not bill an untendered label — keeps it on purpose.
