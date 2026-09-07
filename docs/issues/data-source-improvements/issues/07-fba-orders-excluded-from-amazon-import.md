# FBA orders should not import by default, and must be marked when they do

Status: done

## Problem

`AmazonSource::fetchShipments()` filters orders by `fulfillmentStatuses` only — never by who fulfills them. Amazon returns both merchant-fulfilled (MFN) and Amazon-fulfilled (FBA) orders, and the import writes both into `shipments` identically.

FBA orders are picked, packed, and shipped by Amazon. They must never appear in the packing queue: a warehouse operator has no way to tell them apart today, and packing one produces a duplicate physical shipment plus a `confirmShipment` export attempt that Amazon will reject.

Evidence from the first production historical import (1006 orders, data source 8): **24 orders came back with `fulfillment.fulfilledBy = AMAZON`** and were imported as ordinary shipments.

The order-level fulfillment block already carries the discriminator, and `mapOrderToShipment()` already stores it as `metadata.amazon_fulfilled_by` (`AMAZON` | `MERCHANT`). Nothing acts on it.

## Expected behavior

1. **Exclude by default.** Skip orders with `fulfillment.fulfilledBy === 'AMAZON'` during mapping. Amazon's `SearchOrders` may also support server-side filtering — check the v2026-01-01 model for a `fulfillmentChannels`-style query parameter before filtering client-side, since skipping server-side is cheaper and keeps `maxResultsPerPage` counts honest for historical imports (`_historical_max_orders` currently counts orders we may then discard).
2. **Make it opt-in.** Add an `settings.import_fba_orders` toggle to the Amazon Import Settings section of `DataSourceForm`, default off.
3. **Mark them clearly when imported.** If the toggle is on, FBA shipments need to be obvious in the UI — at minimum a badge on the Shipments table/view driven by `metadata.amazon_fulfilled_by`, and they should be kept out of the pack/ship flows (or blocked with an explanatory message at `/pack/{shipment_id}`).
4. **Don't export them.** `exportPackage()` should refuse an FBA order with a `PermanentExportException` rather than letting Amazon reject the `confirmShipment` call.

Open question for the maintainer: what should happen to the 24 FBA orders already imported — leave them (they are historical/shipped, so harmless), or purge them? A one-off cleanup could reuse the `metadata->amazon_fulfilled_by` filter.

## Test notes

`tests/Feature/AmazonImportExportTest.php` — `sampleAmazonOrder()` now carries a v2026-01-01 `fulfillment` block, so a test can flip `fulfilledBy` to `AMAZON` and assert no shipment is created by default, and one is created (marked) when the toggle is on. `DataSourceResourceTest.php` covers the form field. Export refusal fits alongside the existing `PermanentExportException` cases in the same file.

## Comments

**2026-08-13 (Claude):** Filed while wiring `fulfillment.fulfillmentServiceLevel` into shipping method mapping. That change also fixed `metadata.amazon_order_status`, which had been reading the v0 `orderStatus` key and was null on all 1006 imported rows; `fulfilledBy` was mapped correctly and is reliable.

**2026-09-07 (Claude):** Implemented. All four numbered requirements are in place.

**1. Excluded by default.** `AmazonSource::fetchShipments()` drops orders whose
`fulfillment.fulfilledBy` is `AMAZON`, gated on the new `import_fba_orders` setting.

The issue asked whether Amazon's v2026-01-01 `SearchOrders` supports a
`fulfillmentChannels`-style query parameter, so the filtering could happen server side.
**Not resolved, and deliberately not guessed at.** The v0 spec vendored at
`tests/Fixtures/Schemas/ordersV0.json` does carry `FulfillmentChannels`, but we call
`/orders/2026-01-01/orders` and no v2026-01-01 model is vendored anywhere in the repo to
check it against. Sending an unrecognised query parameter risks a 400 on *every* import,
which is a much worse failure than the bandwidth saved, so the filter is client-side with
that reasoning recorded in a comment at the call site. Worth revisiting if the
v2026-01-01 model is ever vendored.

The related concern — `_historical_max_orders` counting orders we then discard — **is**
fixed. The filter runs before an order is appended to `$rawOrders`, and the cap is
measured against that array, so a historical import of 1000 returns 1000 importable
orders rather than 1000 fetched ones. Pinned by a test that puts FBA orders in front of
the merchant-fulfilled ones and a merchant-fulfilled order past the cap, so it fails both
if the filter stops working and if the cap stops applying.

**2. Opt-in toggle.** `settings.import_fba_orders`, default off, in the Amazon Import
Settings section of `DataSourceForm`.

**3. Marked when imported.** `Shipment::isAmazonFulfilled()` reads
`metadata.amazon_fulfilled_by`. It drives an `FBA` badge column on the shipments table, a
warning entry on the shipment view, and — the part that matters — two hard blocks:

- `Pack::mount()` refuses the shipment with a notification and redirects to `/pack`,
  following the `isBlockedByPicking()` precedent immediately below it.
- `BatchLabelService::getIneligibilityReason()` returns `Fulfilled by Amazon (FBA)`.
  This one is not redundant with the Pack block: batch shipping builds packages from
  shipments directly and never goes through the Pack page, so blocking only Pack would
  have left the FBA orders shippable by the batch path.

**4. Not exported.** `AmazonSource::exportPackage()` throws `PermanentExportException`
before the `confirmShipment` call. `PackageExportService` puts
`_amazon_fulfilled_by` on the payload alongside `_amazon_shipment_id` — in **both**
sandbox and production, unlike the rest of the Amazon export context, because the sandbox
body is canned and would otherwise sail straight past the refusal.

The refusal is the **first** statement in the method, ahead of both of the existing
short-circuits. Review caught it sitting below them, where it did not reliably do what
its own comment claimed:

- Below `validateExportConfiguration()`, a source with missing or rotated credentials
  threw `InvalidArgumentException` first. That is not a `PermanentExportException`, and
  `PackageExportService::isPermanentFailure()` only treats that class or
  `attempts >= 32` as permanent — so an FBA package on a misconfigured source retried 32
  times for a confirmation that could never be valid. This was the real defect.
- Below the `_amazon_shipment_id` early return, an FBA order carrying an Amazon Buy
  Shipping ID was recorded as a *successful* export. That combination is a
  contradiction — Amazon does not fulfill an order we bought postage for — so the right
  response is to surface it, not to swallow it as a success.

Both orderings are now pinned by their own tests, each verified to fail if the guard
moves back down.

**On the open question — the 24 FBA orders already imported.** Left in place, and the
question is now much less pressing than when it was filed. They are historical and
already shipped, and every path that could act on one is now closed: they cannot be
packed, cannot be batch shipped, and cannot be exported, and they carry a visible badge
wherever they appear. A purge is still available if wanted —
`Shipment::where('metadata->amazon_fulfilled_by', 'AMAZON')` — but it is now a
tidiness decision rather than a safety one.

Tests: `AmazonImportExportTest` (skip by default, import-and-mark when opted in,
historical cap counts kept orders, export refusal, and the two ordering cases above),
`PackTest` (FBA blocked, MFN still packable), `BatchLabelServiceTest` (ineligibility
reason), `DataSourceResourceTest` (the toggle is Amazon-only and defaults off), and a new
`tests/Feature/Filament/ShipmentFbaIndicatorTest.php` for the badge and the view entry.
Every guard was verified to bite by temporarily disabling or reordering it and confirming
the matching test fails — the import filter, the historical cap, the export refusal, both
export orderings, and the infolist visibility. Full suite green at 2054 passing; PHPStan
and Pint clean.
