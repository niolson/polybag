# A void makes the next import create a duplicate shipment for the same order

Status: ready-for-agent

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

- [ ] Voiding a label and re-importing does not create a second shipment for the same order
- [ ] The surviving shipment can buy a label without any manual repointing
- [ ] A genuine second fulfillment order — different location, or different line items — still
      imports as its own shipment
- [ ] A shipment that is currently `shipped` is not silently repointed out from under a live
      package
- [ ] A test covers the void-then-import sequence against faked fulfillment orders, with the
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
