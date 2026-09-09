# A void makes the next import create a duplicate shipment for the same order

Status: done — 2026-09-09

Repo: `polybag`

## Problem

`ShopifySource` keys a shipment on the **fulfillment order** GID:

```php
'source_record_id' => $fulfillmentOrder['id'],
```

and `ShipmentBatchWriter` dedupes on it:

```php
Shipment::upsert($rowsToWrite, ['data_source_id', 'source_record_id'], $updateColumns);
```

A void replaces the fulfillment order (`18`) — Shopify closes the old one permanently and
creates a new one for the same line items at the same location. **The replacement carries a
GID the import has never seen, so it is imported as new work**, and the order now has two
shipments.

Observed 2026-09-09 on order #1241, after a label was voided in the Shopify admin and the
Shopify data source was re-imported:

| | Shipment 6769 | Shipment 6771 |
|---|---|---|
| `shipment_reference` | `#1241` | `#1241` |
| `metadata.shopify_order_id` | `gid://shopify/Order/1000000000001` | **the same** |
| `source_record_id` | `…/FulfillmentOrder/2000000000001` | `…/FulfillmentOrder/2000000000002` |
| Status | `shipped` | `open` |
| Packages | 208 | none |

One order, one parcel's worth of goods, two shipments in the queue.

**It gets worse once the synchronizer catches up.** `packages:sync-shopify-fulfillments`
un-ships package 208 when it sees `LABEL_VOIDED`, returning 6769 to unshipped — so the queue
then holds **two open shipments for one order**, one of which can never buy a label because
its stored fulfillment order is the closed one (`18`). Packing both produces one failure and
one success, and no signal that they were the same goods.

## Relationship to `18`

Same root cause — a fulfillment order is not a stable identity for an order's shipping work —
seen from the other end. `18` is the **purchase** path keeping a dead ID; this is the
**import** path treating the replacement as a new order.

They are worth fixing together, because the obvious fix here also discharges `18`:
**recognise the replacement as the same shipment and repoint `source_record_id` and
`metadata.shopify_fulfillment_order_id` at it.** `18`'s comment records that re-pointing the
stored ID by hand restored the purchase path immediately with no other change — so an import
that repoints on its own leaves nothing for `18` to fix.

Fixing `18` alone does **not** fix this one: refreshing the ID in `applyVoid()` corrects the
purchase path, but the next import still sees an unrecognised GID and still creates 6771.

## The hard part

A fulfillment order GID was not a careless choice, and the fix is not "key on the order
instead". One Shopify order legitimately has **several** fulfillment orders — split across
locations, or split by `fulfillmentOrderSplit` (`13`) — and each is genuinely separate
shipping work. Keying on `shopify_order_id` would collapse those into one shipment and lose a
real distinction.

So the identity has to distinguish *a replacement* from *a sibling*. What separates them:

- A replacement appears when a **closed** fulfillment order for the same order and assigned
  location has the same line items; a sibling has different line items or a different
  location.
- The old one is `CLOSED` with no `supportedActions`, which is the same test `18` names for
  finding the live one.

Neither is a documented supersession link — Shopify exposes no "replaces" edge — so this is
inference from state, and it should fail toward creating a shipment rather than silently
merging two that are not the same. A wrongly-merged shipment loses goods; a wrongly-duplicated
one is visible.

## Options

- **Repoint on import.** Before inserting a fulfillment order that is new to us, look for an
  existing shipment on the same order and location whose stored fulfillment order is now
  closed and whose line items match; if found, update that shipment's `source_record_id` and
  metadata instead of inserting. Discharges `18` as a side effect.
- **Repoint on void, and skip on import.** `applyVoid()` refreshes the ID (`18`'s option), and
  the import skips a fulfillment order whose GID is not stored but whose order already has a
  shipment at that location. Two changes, and the second still needs the sibling test.
- **Detect and flag rather than merge.** Import the duplicate but mark it as a suspected
  replacement, and let a person resolve it. Safest, and the worst of the three for a
  high-volume packing queue.

## What to answer

1. **Is line-item comparison necessary, or is order + assigned location + "the old one is
   closed" enough?** Splitting is the only case that makes it necessary, and `13` records that
   PolyBag has no multi-package packing workflow to split for yet.
2. **What happens to a shipment whose replacement is imported while it is `shipped`?** 6769
   was `shipped` at the time. Repointing a shipped shipment at a fresh fulfillment order is
   not obviously right — the synchronizer may not have un-shipped it yet, so the two writes
   race.
3. **Should this be detected at all outside an import run?** The duplicate only appears when
   someone imports. A void with no subsequent import leaves the stale ID and no duplicate,
   which is `18`'s failure alone.

## Acceptance criteria

- [x] Voiding a label and re-importing does not create a second shipment for the same order
- [x] The surviving shipment can buy a label without any manual repointing
- [x] A genuine second fulfillment order — different location, or different line items — still
      imports as its own shipment
- [x] A shipment that is currently `shipped` is not silently repointed out from under a live
      package
- [x] A test covers the void-then-import sequence against faked fulfillment orders, with the
      closed-then-replaced shape

## Blocked by

Nothing. Reproducible on the development store.

## Related

- `18` — the same root cause on the purchase path; fixing this issue's first option discharges it
- `13` — `fulfillmentOrderSplit`, the case that makes the identity question non-trivial
- `01` — question 4, being answered when this surfaced

## Comments

### 2026-09-09 — found by voiding and re-importing, which is a normal thing to do

Not looked for, and reached by an ordinary sequence: void a label in the Shopify admin, run
the import again. That is what an operator does when a label was bought by mistake.

**Two shipments for order #1241 with the same `shopify_order_id`** and different
`source_record_id`s, which is the whole bug in one line. The GID differing is not a data
problem — it is Shopify correctly reporting that the old fulfillment order is gone and a new
one exists.

Worth stating plainly because it changes how `18` should be scheduled: `18` reads as a
purchase-path repair, and on its own it is. But the fulfillment order churn it documents has a
second consequence that reaches the import, and the fix that covers both is an import-side
one. Whoever picks up `18` should read this first.

### 2026-09-09 — the observed instance cleaned up, and one fact that makes the fix safer

Order #1241 resolved by hand: shipment 6769 and package 208 set to `void`, with an audit row
naming the reason and the shipment that superseded them. 6771 remains as the single live
shipment. Both terminal states hold — `Shipment::updateShippedStatus()` returns early on
`Void`, and `ShipmentBatchWriter::shouldUpdate()` refuses any shipment that is not `Open`, so
a later import cannot resurrect it. The old fulfillment order is closed and will not be
returned by the import query either.

**The synchronizer reads a void through a stale fulfillment order without difficulty.** Before
the cleanup, `packages:sync-shopify-fulfillments` was run against 6769, whose stored
fulfillment order had been closed and replaced. It found the void anyway and un-shipped the
package correctly:

```
Checked 2 package(s): 1 voided, 1 tracked, 0 failed.
Shopify label voided outside PolyBag; package returned to unshipped  package 208
```

The fulfillment stays attached to the fulfillment order that produced it, closed or not, so
`ShopifyFulfillmentSynchronizer` is unaffected by the churn. **That matters for whoever
implements the repointing fix**: moving `shipment.metadata.shopify_fulfillment_order_id` to the
replacement does not break void detection for a label bought against the old one — but nor is
the synchronizer a reason to leave the stale ID in place, since it works either way. The
purchase path is the only consumer that cares.

### 2026-09-09 — done, with `18`, as the first option

Both ends of the one root cause, in one change. The import re-points; the void path
re-points too, so a void with no subsequent import no longer leaves a dead ID either —
which is what `18` asked for and is why it closes with this.

**Import side.** `ShopifyFulfillmentOrderRepointer` runs once per import run, before the
batch write keys off `source_record_id`. It takes the fetched fulfillment orders as
`ShopifyFulfillmentOrderIdentity` values — order, assigned location, and quantity per SKU —
and for each one no shipment of that source names, looks for the single open shipment for
the same order at the same location, holding the same goods, whose own fulfillment order
this run is **not** offering. Found, it moves `source_record_id` and
`metadata.shopify_fulfillment_order_id` onto the replacement and audits the move; the batch
write then updates that shipment instead of inserting a second one.

The seam is a new `ReconcilesSupersededRecords` contract, called from
`ShipmentImportService::import()` on the whole fetched set rather than per chunk —
"is the old one still on offer?" is not answerable from part of the set, and a chunk
boundary between two of an order's fulfillment orders would answer it wrongly. A failure
there is recorded as a run error and the import carries on: the cost is a duplicate, which
is visible; the cost of abandoning the run is every other shipment in it.

**Question 1 — line items are necessary**, though not for the reason the issue guessed.
`13` is right that PolyBag has no split-packing workflow, but the test that stands in for
"the old one is closed" is really *"the old one is no longer offered"*, and that is equally
true of a sibling that closed for its own reasons — fulfilled elsewhere, cancelled, moved.
Order plus location alone would fold that sibling's shipment into the new arrival.

**Question 2 — a `shipped` shipment is never re-pointed.** Candidates are `Open` only. In
that window the duplicate still appears, and that is the documented lesser harm: the
synchronizer un-ships the package when it reads the void and the next import finds an open
shipment to re-point. In practice the race rarely runs, because the synchronizer's own
re-point (below) usually lands first — it polls every fifteen minutes.

**Question 3 — yes, outside an import run.** `ShopifyFulfillmentSynchronizer::applyVoid()`
now re-resolves the shipment's fulfillment order from the order's own
`fulfillmentOrders(includeClosed: false)`, filtered to those whose `supportedActions`
carry `CREATE_FULFILLMENT` and assigned to the shipment's location. Exactly one is the
replacement and is taken, `source_record_id` with it — guarded against colliding with a
shipment already keyed on that GID, since `(data_source_id, source_record_id)` is unique.
None means the order cannot be shipped through Shopify at all, so the stored ID is cleared
and `canPurchaseFor()` withdraws the offer rather than failing at the bench. More than one
is left alone. A failed query changes nothing — the void is already recorded, and the next
import re-points.

Nine tests across `ShopifyImportExportTest` and `ShopifyFulfillmentSynchronizerTest`,
including the sibling that is still on offer, the sibling with different goods, and the
shipped shipment that must not move.

### 2026-09-09 — code review closed two holes in the first implementation

Both were the same mistake in two places: treating *absence of evidence about the goods* as
though it were evidence they match.

**The goods are now recorded on the shipment at import time**, as
`metadata.shopify_goods_fingerprint` — a hash of shippable quantity per SKU, written by
`ShopifySource` from the fulfillment order's own line items. The first implementation
compared `shipment_items` instead, and `shipment_items_enabled` is a per-source setting: with
it off, every candidate compared as an empty set and matched everything, so a sibling for
**different goods** was re-pointed onto and the genuine new shipment suppressed in the
upsert — precisely the silent merge this issue was written to avoid. The fingerprint does not
depend on item import running, and items remain the fallback for shipments imported before
it existed. Where neither is available the shipment stays unpaired, and the replacement
imports where a person can see it.

**The void path was applying no goods test at all.**
`fulfillableFulfillmentOrderIds()` identified candidates by location and
`CREATE_FULFILLMENT` alone, so on a split order its single result could be a sibling — and
`applyVoid()` would write that sibling's GID into
`metadata.shopify_fulfillment_order_id`, which is exactly what the purchase path reads. The
guard that was there stopped only `source_record_id` from colliding, and left the metadata
pointed at another shipment's work: the next label bought would have shipped the wrong
parcel with nothing to show that it had. It now returns
`ShopifyFulfillmentOrderIdentity` values carrying the goods, and the synchronizer keeps
only candidates whose fingerprint matches the shipment's **and** that no other shipment of
that data source already names — as its import key or in its metadata, since either is
enough for a purchase to target it. Nothing left means the stored ID is cleared, which is
what that branch already did and remains the safe answer: the offer withdraws itself and
the import re-points later if it can.

One consequence worth naming: the order query now fetches line items, so a fulfillment
order with more than 40 of them comes back with `hasNextPage` and no readable goods. That
reads as no answer rather than a wrong one — the ID is cleared and the import, which
paginates, sorts it out.

**The same treatment reached the outer connection on a second review pass.** The query asks
for 20 fulfillment orders and did not report whether that was all of them, so an order with
more would have had a partial page read as complete: the replacement could be on the page
nobody looked at and the stored ID cleared for nothing, or a candidate that would have made
the answer ambiguous left unseen and a wrong one taken as the only match. It now returns
`null` — *cannot ask*, not *none* — and nothing is touched at all, where the truncated
line-item case still clears. Neither connection paginates, deliberately: an order carrying
more than twenty fulfillment orders is far outside what a void has to be resolved against,
and the import is the path that pages through them properly.