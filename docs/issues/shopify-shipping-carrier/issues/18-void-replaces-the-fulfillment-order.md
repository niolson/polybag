# A Shopify-side void replaces the fulfillment order, and the shipment keeps the dead ID

Status: done — 2026-09-09

Repo: `polybag`

## Problem

When a Shopify Shipping label is voided, Shopify does not reopen the fulfillment order it
was bought against. It **closes that one permanently and creates a replacement**. Observed
on both packages `01` voided:

```
order status: UNFULFILLED
  FulfillmentOrder/…806   CLOSED  []              <- closed by an earlier admin purchase
  FulfillmentOrder/…726   CLOSED  []   <== stored <- closed by PolyBag's purchase
  FulfillmentOrder/…414   OPEN    [CREATE_FULFILLMENT, REPORT_PROGRESS, HOLD]
```

`ShopifyFulfillmentSynchronizer::applyVoid()` returns the package to `unshipped` and
strips the four label identifiers, but nothing updates
`shipment.metadata.shopify_fulfillment_order_id`, which still names the closed one. So
after a void:

- `ShopifyShippingLabelService::canPurchaseFor()` is true — there *is* a stored ID
- `ShopifyAdapter::shipmentAlreadyBoughtALabel()` is false — `applyVoid()` cleared the markers
- the Shopify offer is therefore shown to the packer again, and
- the purchase fails with `FULFILLMENT_ORDER_INVALID` — *"The fulfillment order is not
  fulfillable"* — after the box is taped shut

Which is precisely the failure mode `06` withdrew the offer to prevent, arrived at from
the other direction.

**This contradicts shipped code's own docblock.** `shipmentAlreadyBoughtALabel()` explains
why `Void` status is deliberately not disqualifying:

> voiding reopens the fulfillment order and strips the markers in the same write, so a
> shipment whose only previous label was voided can buy another one

The second half is true and the first is not. The *order* becomes fulfillable again; the
fulfillment order does not. Nothing was wrong at the time it was written — it had never
run against a real void, which is the same gap `01` closed for the un-ship path.

## What to do

- On void, re-resolve the shipment's fulfillment order rather than leaving the dead ID in
  place. The order's `fulfillmentOrders` connection carries the replacement, and the one
  to take is the `OPEN` one — `supportedActions` containing `CREATE_FULFILLMENT` is the
  honest test of fulfillability, rather than status alone.
- Decide between refreshing it in `applyVoid()` and clearing it so the purchase path
  re-resolves. Clearing is the smaller change and fails closed — the offer withdraws
  itself, because `canPurchaseFor()` goes false — but it gives up re-shipping through
  Shopify without a re-import. Refreshing keeps the workflow whole and is what the
  docblock already promises.
- Correct that docblock either way. It is the reason a voided shipment is allowed back
  into the offer list at all, so it needs to say what actually happens.
- A `null` here must keep meaning "don't know", per `fulfillmentFor()`'s existing
  discipline: an order with no open fulfillment order is not the same as one whose
  replacement has not been read yet.

## Notes

- `orderOpen` is **not** the lever. The order behind a voided label is not closed
  (`closed: false`) and reopening it changes nothing — the closure is per fulfillment
  order, not inherited from the order.
- There is no mutation that reopens a closed fulfillment order. `fulfillmentOrderOpen`
  applies to *scheduled* ones, which is a different state.
- Found while probing `02`, from the other end: a purchase attempt against one of the
  voided packages returned `FULFILLMENT_ORDER_INVALID`, which is what prompted looking at
  the fulfillment orders at all.

## Comments

### 2026-09-09 — observed a third time, on a void done by hand

The label `02` bought was voided in the Shopify admin, and the same thing happened again:
the fulfillment order it was bought against went `CLOSED`, a fresh `OPEN` one appeared on
the order, and the shipment kept the dead ID. Three for three, across two packages voided
by the synchronizer's path and one voided by hand.

It also showed what the failure looks like from the outside, which is worth recording
because it is not obvious: every subsequent probe came back `FULFILLMENT_ORDER_INVALID`
rather than a rate answer. Read quickly, a screen of those looks like the *service codes*
went bad — the reply says nothing about the fulfillment order being the stale half. Anyone
debugging a Shopify purchase that suddenly fails for every service should check the stored
ID against the order's `fulfillmentOrders` before suspecting anything else.

Re-pointing `shipment.metadata.shopify_fulfillment_order_id` at the open one restored the
purchase path immediately, with no other change — which is the evidence that refreshing
the ID is a sufficient fix, and that nothing else about the shipment is invalidated by a
void.

### 2026-09-09 — the churn also reaches the import, and that changes the fix

Voiding a label and running the Shopify import again — an ordinary sequence, and what an
operator does when a label was bought by mistake — **creates a duplicate shipment**. Filed as
`21`.

`ShopifySource` sets `source_record_id` to the fulfillment order GID and `ShipmentBatchWriter`
dedupes on it, so the replacement this issue documents arrives as a record the import has
never seen and is inserted as new work. Order #1241 now has shipments 6769 and 6771, same
`shopify_order_id`, different fulfillment orders.

**This should change what gets built here.** The options above both repair the purchase path
and leave the import untouched, so the duplicate still appears. The import-side fix in `21` —
recognise a replacement and repoint the existing shipment — covers both, because re-pointing
`shipment.metadata.shopify_fulfillment_order_id` is exactly what the comment above found
sufficient to restore purchasing.

So: **read `21` before implementing either option here.** Fixing this issue alone is not
wrong, but it is half a fix for one root cause, and the half that leaves a duplicate in the
packing queue.

### 2026-09-09 — done, with `21`, and both halves shipped

Read `21` for the whole of it. What landed here:

**Refreshing, not clearing** — the option this issue preferred, and the docblock's promise
kept. `applyVoid()` now re-resolves the shipment's fulfillment order after it un-ships the
package, from the order's own `fulfillmentOrders(includeClosed: false)`, keeping those
whose `supportedActions` carry `CREATE_FULFILLMENT` and that are assigned to the shipment's
location. `supportedActions` rather than status, exactly as this issue names it: a
fulfillment order can be open and still unfulfillable, on hold or assigned to a third-party
fulfillment service.

Clearing is still the answer to one case — *no* fulfillable fulfillment order, which means
the order cannot be shipped through Shopify at all. There the stored ID goes and
`canPurchaseFor()` withdraws the offer on its own, which is the fail-closed behaviour this
issue wanted from clearing without giving up the re-ship path in the case that has one.
Several fulfillable fulfillment orders is no answer and the ID is left as it is. A failure
to ask changes nothing: the void itself is already recorded, and the import-side re-point
covers it on the next run.

`source_record_id` moves with the metadata ID, which is the half that discharges this
issue's reach into the import: left behind, it names the same dead fulfillment order and
the next import reads the replacement as work it has never seen. It moves only when it
still names the fulfillment order being replaced, and only when no other shipment of that
source is already keyed on the replacement — `(data_source_id, source_record_id)` is
unique.

**The docblock is corrected.** `ShopifyAdapter::shipmentAlreadyBoughtALabel()` no longer
says voiding reopens the fulfillment order. It says what happens: Shopify closes it and
creates a replacement, and what makes a voided shipment buyable again is `applyVoid()`
stripping the markers *and* re-pointing at that replacement — or clearing the stored ID
when there is none, so the offer never reaches the packer.

`null` keeps meaning "don't know". `fulfillableFulfillmentOrderIds()` returns `null` for a
question that cannot be asked — not a Shopify shipment, no stored order ID, an order
Shopify no longer returns — and an empty list only as a real answer.

### 2026-09-09 — corrected after review: the replacement has to be the right goods

The first implementation took the order's single fulfillable fulfillment order as the
replacement, having filtered only on `supportedActions` and the assigned location. Those
are the tests this issue named, and they are not sufficient: an order split at one location
leaves siblings that pass both and are for different goods. Writing one of those into
`metadata.shopify_fulfillment_order_id` points the purchase path at another shipment's work,
which is a worse outcome than the stale ID this issue was filed about — a stale ID fails
loudly with `FULFILLMENT_ORDER_INVALID`, where a sibling's ID buys a label and ships the
wrong parcel.

So the candidates now carry the goods they are for, and the synchronizer keeps only the ones
whose fingerprint matches what the shipment records and that no other shipment already
names. See `21`'s last comment for the fingerprint itself. Where nothing matches, the branch
this issue already specified applies unchanged: clear the stored ID, let the offer withdraw
itself, and leave the import to re-point the shipment when it can see more.