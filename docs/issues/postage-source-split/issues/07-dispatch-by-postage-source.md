# Dispatch void, manifest and tracking by postage source

Status: done — PR #164

Repo: `polybag`

## Problem

`TrackingService::refreshPackage()` and `EloquentPackageLabelWorkflow` both dispatch on
`carrierRegistry->get($package->carrier)` — asking the *carrier* who to call for an operation
that belongs to whoever **bought** the label. Only the purchaser can void a label or manifest
it, and after April 2026 only the purchaser is entitled to USPS tracking data.

See ADR-0002 decision 3 and its entitlement note.

## What to build

Route void, manifest eligibility and tracking through the postage source recorded in `01`.

Include `ShopifyLabelVoidSynchronizer::candidates()` in this audit. It still selects packages
with `carrier = ShopifyAdapter::CARRIER_NAME`; once Shopify purchases record the physical
carrier of record, that query silently stops polling Shopify-bought labels. Candidate
selection must use `postage_source = postage_data_source` and the relevant Shopify data
source identity instead of the carrier string.

Tracking is **not a fallback chain**, but Shopify is not a dead end either. We hold no USPS
entitlement for a Shopify-bought label, so "try the carrier next" would be a paid request we
are not authorized to make — that prohibition stands whatever Shopify answers. Shopify does
answer, though: `Fulfillment.displayStatus` carries `CARRIER_PICKED_UP`, `IN_TRANSIT`,
`OUT_FOR_DELIVERY`, `ATTEMPTED_DELIVERY`, `DELAYED`, `DELIVERED` and `NOT_DELIVERED`, with
`inTransitAt`, `deliveredAt` and `estimatedDeliveryAt` beside it, populated by Shopify for
every tracking company on its supported-carriers list — which covers all four carrier codes it
sells postage from. `isVoidedInShopify()` already selects that field and throws away
everything but `LABEL_VOIDED`/`CANCELED`. Carrier dispatch applies to `carrier_account`
provenance only. (ADR-0002 originally called these labels "terminally untrackable"; the
2026-09-03 amendment revises that.)

Fold the tracking read into the existing void-sync query rather than adding a second call: same
connector, same fulfillment, same 15-minute poll. Match by tracking number exactly as the void
sync does, and keep its rule — an unmatched fulfillment is *no answer*, never a status.

The `FulfillmentDisplayStatus` → `TrackingStatus` mapping is lossy and should stay lossy.
Statuses are stage-level with no scan locations, the timeline moves on Shopify's polling
cadence, and nothing maps to `TrackingStatus::Returned`. `deliveredAt` is a real timestamp and
may populate `packages.delivered_at`; `estimatedDeliveryAt` is a prediction and must not.

ADR-0002 decision 3 also names `legacy_unknown` here, but that value was removed in PR #158:
it routed to carrier dispatch exactly as `carrier_account` does, so it was one branch under
two names. There is one branch now.

## Acceptance criteria

- [x] Void dispatches on postage source; a Shopify label still returns its "cancel in the
      Shopify admin" failure
- [x] The synchronizer selects a Shopify-bought package whose carrier of record is USPS; it
      does not identify Shopify postage by carrier name — **already true when this slice
      started**, `02` moved `candidates()` onto `postage_source` along with
      `isShopifyShipped()`. Covered by "never checks packages that were not shipped through
      Shopify"
- [x] Tracking dispatches on postage source; a Shopify-bought label resolves through its
      fulfillment, and no carrier request is ever made for one
- [x] The Shopify tracking read reuses the void-sync query and its tracking-number match; an
      unmatched fulfillment records no status
- [x] An empty `Fulfillment.events` connection reads as a normal result, not a failure
- [x] Packages backfilled by `02` keep working — they carry `carrier_account`, so carrier
      dispatch covers them with no separate legacy branch
- [x] Manifest eligibility asks the postage source, not the carrier
- [x] Existing tracking and label-workflow tests pass

## Open question: check `Fulfillment.events` on a real Shopify Shipping fulfillment

**Tracked as of 2026-09-04 in `shopify-shipping-carrier/01`, questions 7 and 8; moved
2026-09-08 to `shopify-shipping-carrier/17`, which keeps the numbers.** Both were pointed at
`01` when written but never recorded there, so they lived only in this closed issue; `01`
then split out the questions needing a real store shipping a real parcel, and these are two
of them. `01` did establish that the connection is not structurally empty — a purchase
writes a `LABEL_PURCHASED` node — and that the lifecycle starts at `FULFILLED` rather than
`LABEL_PURCHASED`, so the mapping's assumed starting point was wrong even though the stored
status was right.

`Fulfillment.events` would give the scan-level detail `displayStatus` cannot —
`FulfillmentEvent` carries `happenedAt`, city/province/zip, latitude/longitude and a `message`,
which is the shape `tracking_details['events']` wants. But the only documented way events are
created is the `fulfillmentEventCreate` mutation, used by apps and fulfillment services;
nothing says Shopify writes them for the shipments it tracks itself. It may well come back
empty for a Shopify Shipping label.

The docs cannot settle it. **When the first live Shopify Shipping label exists** — blocked
today on accepting the Shopify Shipping ToS through a manual purchase in the admin — query
`events(first: 50)` on its fulfillment once the parcel has actually moved, and record what
comes back:

- Populated → read it as the event feed and let `displayStatus` be the summary.
- Empty → `displayStatus` plus the three timestamps is the whole feed, and an empty connection
  must never surface as an error.

Build against `displayStatus` either way. Asking for `events` alongside it costs one field on
a query already being made, so select it now and tolerate an empty answer.

## What shipped

- `App\Services\PostageSources\PostageSourceDispatcher` — the one branch. `carrier_account`
  reaches `CarrierRegistry` exactly as before; `postage_data_source` reaches the source. It is
  deliberately a `match` on the discriminator rather than a new contract, because `08` is
  about to define the real postage-source contract and should not have to unpick a placeholder
  one first.
- `App\Services\PostageSources\ShopifyPostageSource` — void message, manifest answer, and the
  `FulfillmentDisplayStatus` → `TrackingStatus` mapping. `ShopifyAdapter::cancelShipment()` now
  reads its message from here so the string exists once.
- `ShopifyShippingLabelService::fulfillmentFor()` — the shared fetch, with `isVoided(?array)`
  split out so one poll answers both questions. The query gained `inTransitAt`, `deliveredAt`,
  `estimatedDeliveryAt` and `events(first: 50)`.
- `ShopifyLabelVoidSynchronizer` → **`ShopifyFulfillmentSynchronizer`**, and
  `packages:sync-shopify-voids` → **`packages:sync-shopify-fulfillments`**. Folding tracking in
  gave the class a second job, and a class named for voiding that also writes tracking is a
  lie a reader has to work around. Nothing outside the four files referenced either name.
  `sync()` now returns `tracked` alongside `voided`.
- `TrackingService::persistResult()` is now public as `record()`, so the synchronizer stores the
  answer it already holds instead of re-fetching it.
- `Package::scopeBoughtOnCarrierAccount()` — the manifest eligibility rule, previously repeated
  as a literal `where` in four queries. `ManifestService::createManifest()` also guards on it
  directly: the queries that feed it already filter, but the rule protects a request to USPS
  and a caller assembling its own collection must not be able to route around it.

Two things worth knowing at review time. The fifteen-minute fulfillment poll keeps
`tracking_checked_at` fresher than the four-hourly `packages:refresh-tracking` tier needs, so
Shopify packages cost one request, not two — that is why folding was worth doing rather than
just sharing a query constant. And an unknown carrier on a direct purchase now records the
failed check (`tracking_checked_at`) where it previously returned without persisting; the old
early return skipped the write, which meant a package with a bad carrier string looked
never-checked forever.

## Fixed in review

Three defects, all found before merge:

- **Tracking errors escaped `TrackingService`.** Only `CarrierUnavailableException` was caught,
  but a Shopify read reaches the network — a throttled reply raises
  `ShopifyLabelPurchaseException`, transport failures raise Saloon's. That would 500 the
  Filament refresh action and fail the queued job instead of recording a missed check. Now
  caught as `Throwable`, logged, and written down as a failed check.
- **A delivered package could stay in the fifteen-minute poll.** `candidates()` excluded only
  a non-null `delivered_at`, and Shopify reports `deliveredAt` as nullable even on a DELIVERED
  fulfillment — so such a package took `TrackingStatus::Delivered` and then kept polling for
  the rest of the 30-day window. Terminal tracking statuses are excluded too.
- **The recorded postage source could be bypassed.** `postageSourceFor()` fell back to the
  shipment's *current* import source whenever the recorded one was inactive or missing, which
  is precisely the drift the column exists to prevent: a shipment re-pointed from Shopify shop
  A to shop B would have had A's label read with B's credentials. A shipped package now
  resolves to its recorded source or to no answer. The purchase path still resolves through the
  shipment, since a package that has not shipped has no provenance to honour.

## Still unverified

Everything Shopify-side is faked. No Shopify Shipping label has ever been bought (the shop has
not accepted the Shopify Shipping ToS), so the mapping table, the claim that `displayStatus`
advances past `LABEL_PURCHASED` at all, and the `events` question below are all confirmed
against the documentation and nothing else. If `displayStatus` turns out to sit still in
practice, this slice's tracking is inert rather than wrong — the dispatch and the void
behaviour stand either way.

## Blocked by

- `02-backfill-provenance-and-gate-manifests`
