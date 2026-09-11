# Stop writing `trackingCompany` into `packages.service`

Status: done — landed with `postage-source-split/11`

Repo: `polybag`

## Problem

`ShopifyAdapter::createShipment()` put a value that was never confirmed into the confirmed
position. The defect moved once before it was fixed: originally `trackingCompany` — a
**carrier** name in the service column — and by the time it was picked up,
`$request->selectedRate->serviceName`, the preference we *asked* Shopify for recorded as
though it were what came back.

ADR-0003 decision 5 and action item 7.

## What shipped

`createShipment()` records `service: null`, `serviceEvidence: Unknown`, and the selection
as `requestedService` — null for the `auto` code, since asking for nothing is not a
preference. The packages table shows the requested value as a description under the blank
service column, so the gap reads as a fact rather than as missing data.

The carrier half was already correct when this was picked up: the carrier Shopify picked
is the carrier of record. `16` later found that value is Shopify's internal code and had
to be translated; `11` later filled the service in by inference.

- [x] The carrier from `trackingInfo.company` is recorded as the carrier
- [x] `packages.service` is left null rather than filled with a carrier name
- [x] Nothing downstream assumes `service` is populated — the channel export gates on
      evidence rather than on the value being present
- [x] `ShopifyAdapterTest` asserts the service is not set

## Consequences carried forward

- `postage-source-split/02`'s backfill reads `metadata.shopify_tracking_company` and
  treats `service` as a fallback. That fallback is dead weight for new rows, though it
  still describes historical ones.
- The requested side is `requested_service` (raw code in
  `metadata.shopify_requested_service_code`), and the only thing it can be compared
  against is `carrier` — Shopify reports no service to disagree about.

## Related

- `postage-source-split/11` — the service provenance model this writes into
- `11` — determining the service after the fact
- `16` — the carrier strings this records turned out not to normalize
