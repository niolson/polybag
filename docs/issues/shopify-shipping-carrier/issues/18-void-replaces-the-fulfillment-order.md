# A Shopify-side void replaces the fulfillment order, and the shipment keeps the dead ID

Status: ready-for-agent

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
