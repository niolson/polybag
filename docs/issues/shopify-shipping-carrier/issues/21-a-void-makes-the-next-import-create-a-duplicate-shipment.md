# A void makes the next import create a duplicate shipment for the same order

Status: done — 2026-09-09

Repo: `polybag`

## Problem

`ShopifySource` keys a shipment on the **fulfillment order** GID and `ShipmentBatchWriter`
dedupes on `(data_source_id, source_record_id)`. A void replaces the fulfillment order
(`18`) — so **the replacement carries a GID the import has never seen and is imported as
new work**, and the order has two shipments.

Observed after voiding a label in the admin and re-importing: two shipments for one order,
same `metadata.shopify_order_id`, different `source_record_id`, one `shipped` and one
`open`.

**It gets worse once the synchronizer catches up.** It un-ships the first package when it
sees `LABEL_VOIDED`, so the queue then holds **two open shipments for one order**, one of
which can never buy a label because its stored fulfillment order is the closed one.
Packing both produces one failure and one success, and no signal that they were the same
goods.

Reached by an ordinary sequence — void a label, run the import — which is what an operator
does when a label was bought by mistake.

## The hard part

A fulfillment order GID was not a careless choice, and the fix is not "key on the order
instead". One Shopify order legitimately has **several** fulfillment orders — split across
locations, or by `fulfillmentOrderSplit` (`13`) — each genuinely separate shipping work.

So the identity has to distinguish *a replacement* from *a sibling*, and Shopify exposes no
supersession link. This is inference from state, and it must fail toward creating a shipment
rather than silently merging two that are not the same: a wrongly-merged shipment loses
goods, a wrongly-duplicated one is visible.

## What shipped — with `18`, both ends of one root cause

**Import side.** `ShopifyFulfillmentOrderRepointer` runs once per import run, before the
batch write. It takes the fetched fulfillment orders as `ShopifyFulfillmentOrderIdentity`
values — order, assigned location, quantity per SKU — and for each one no shipment names,
looks for the single **open** shipment for the same order at the same location, holding the
same goods, whose own fulfillment order this run is **not** offering. Found, it moves
`source_record_id` and `metadata.shopify_fulfillment_order_id` onto the replacement and
audits the move; the batch write then updates that shipment instead of inserting a second.

The seam is a new `ReconcilesSupersededRecords` contract, called on the **whole fetched
set** rather than per chunk — "is the old one still on offer?" is not answerable from part
of the set, and a chunk boundary between two of an order's fulfillment orders would answer
it wrongly. A failure there is recorded as a run error and the import carries on: the cost
is a duplicate, which is visible; the cost of abandoning the run is every other shipment.

**Void side** (which is what closes `18`): `applyVoid()` re-resolves the fulfillment order
the same way, so a void with no subsequent import no longer leaves a dead ID either.

- [x] Void-then-reimport creates no second shipment
- [x] The surviving shipment can buy a label with no manual repointing
- [x] A genuine second fulfillment order — different location or goods — still imports as
      its own shipment
- [x] A `shipped` shipment is never silently repointed out from under a live package
- [x] Tests cover the void-then-import sequence, the sibling still on offer, the sibling
      with different goods, and the shipped shipment that must not move

## The three questions

**1. Line items are necessary** — though not for the reason this issue guessed. The test
standing in for "the old one is closed" is really *"the old one is no longer offered"*, and
that is equally true of a sibling that closed for its own reasons: fulfilled elsewhere,
cancelled, moved. Order plus location alone would fold that sibling's shipment into the new
arrival.

**2. A `shipped` shipment is never re-pointed.** Candidates are `Open` only. In that window
the duplicate still appears, and that is the documented lesser harm — the synchronizer
un-ships the package when it reads the void and the next import finds an open shipment to
re-point. In practice the race rarely runs, because the synchronizer polls every fifteen
minutes and usually lands first.

**3. Yes, outside an import run** — the void-side re-point above.

## Comments

- **2026-09-09** — the observed instance resolved by hand: the duplicate's predecessor
  shipment and package set to `void` with an audit row naming the shipment that superseded
  them. Both terminal states hold — `updateShippedStatus()` returns early on `Void` and
  `ShipmentBatchWriter::shouldUpdate()` refuses anything not `Open` — so a later import
  cannot resurrect it.
- **2026-09-09** — one fact that made the fix safer: **the synchronizer reads a void through
  a stale fulfillment order without difficulty.** The fulfillment stays attached to the
  fulfillment order that produced it, closed or not, so moving the stored ID to the
  replacement does not break void detection for a label bought against the old one. The
  purchase path is the only consumer that cares.
- **2026-09-09, review** — two holes, both the same mistake in two places: **treating
  absence of evidence about the goods as evidence they match.** The first implementation
  compared `shipment_items`, and `shipment_items_enabled` is a per-source setting — with it
  off, every candidate compared as an empty set and matched everything, so a sibling for
  *different goods* was re-pointed onto and the genuine new shipment suppressed in the
  upsert: exactly the silent merge this issue exists to avoid. The goods are now recorded at
  import time as `metadata.shopify_goods_fingerprint`, a hash of shippable quantity per SKU,
  which does not depend on item import running; items remain the fallback for older
  shipments, and where neither is available the shipment stays unpaired and the replacement
  imports where a person can see it. Separately, **the void path applied no goods test at
  all** and could have written a sibling's GID into `metadata.shopify_fulfillment_order_id`
  — the next label bought would have shipped the wrong parcel with nothing to show for it.
- **2026-09-09, second review pass** — both connections now distinguish *cannot ask* from
  *none*. A fulfillment order with more than 40 line items comes back `hasNextPage` with no
  readable goods, and the outer connection asks for 20 fulfillment orders without reporting
  whether that was all of them — a partial page read as complete could clear the stored ID
  for nothing, or take a wrong candidate as the only match. Neither connection paginates,
  deliberately: an order carrying more than twenty fulfillment orders is far outside what a
  void has to be resolved against, and the import pages through them properly.

## Related

- `18` — the purchase-path half, discharged by this
- `13` — `fulfillmentOrderSplit`, the case that makes the identity question non-trivial
