# Withdraw the Shopify offer once a shipment has a shipped package

Status: done — 2026-09-05

Repo: `polybag`

## Problem

One Shopify fulfillment order buys **one** label, and `shopify_fulfillment_order_id` is
stored on the **shipment**, not the package. Every package of a shipment therefore points
at the same fulfillment order, and a second purchase asks Shopify to fulfill something it
has already fulfilled — surfacing as a carrier-internal string (`JOB_NOT_ENQUEUED`,
`FULFILLMENT_ORDER_INVALID`) at a packing bench after the box is taped shut.

It takes a person choosing Shopify a second time on the Ship page — the offer appears only
for a client with `blind_purchase_enabled`, on a Shopify-imported shipment, and never in
any automated path. But nothing stopped them.

## What shipped

A fourth gate on `ShopifyAdapter::blindPurchaseOffers()`: no offer when another package on
the shipment is already `Shipped`, **or** carries an in-flight purchase marker. It holds at
purchase time as well as in the list, because `resolveBlindOffer()` re-derives the offers
and matches by identity, so a stale Ship page refuses with "Offer No Longer Available"
instead of reaching Shopify.

`Void` is deliberately not disqualifying. A voided label's markers are stripped by
`applyVoid()` in the same write that un-ships the package, so a shipment whose only
previous label was voided can buy another one. (`18` later corrected *why* that works: the
fulfillment order is not reopened, it is replaced, and `applyVoid()` re-points at the
replacement.)

- [x] No offers for a package whose shipment has another `Shipped` package
- [x] A `Void`-only sibling still gets the offer
- [x] A second `Unshipped` draft still gets the offer — two open drafts is not a collision
- [x] `ShopifyAdapterTest` covers all three, plus both in-flight markers
- [x] A feature test asserts the purchase path refuses too, without calling Shopify

**The packer is not told why the offer is gone**, consistent with the three existing gates.
Saying more means giving `BlindPurchaseSource` a way to report an exclusion reason, rather
than duplicating the rule outside the adapter. Worth its own issue if the silence proves
confusing at a bench.

## Comments

- **2026-09-05** — implemented. With the gate disabled the feature test fails with
  `Carrier Error` rather than a refusal, which is direct evidence the second purchase
  really was reachable — the thing this issue was guessing at.
- **2026-09-05, review** — two holes, both real, both fixed. A **status-only check missed
  in-flight siblings**: `ShopifyShippingLabelService` persists `shopify_purchase_result_id`
  and `shopify_shipping_label_id` *before* `markShipped()`, deliberately, so a label
  download that 500s does not lose a label the shop has paid for — a sibling stranded there
  is `Unshipped` and cleared the original query. And **the check was not atomic**:
  `ship()` locks on `package-purchase:{id}`, the right grain for per-package postage and
  the wrong one for a purchase against a shipment-level fulfillment order, so two packages
  took two locks and both reached Shopify. `ship()` now takes a second lock,
  `shipment-blind-purchase:{shipment_id}`, **only** when the request carries a blind offer —
  serializing carrier-account postage would refuse a second packer boxing a second parcel
  for no reason. Shipment lock after package lock, never the reverse, both without waiting.

## Note — `supportsMultiPackage()` is dead code

This issue originally said `ShopifyAdapter::supportsMultiPackage()` returns false and is
merely unenforced. Both halves are wrong. `ShopifyAdapter` has no such method — since
ADR-0002 decision 7 it implements `BlindPurchaseSource` only — and the method, declared on
`CarrierPolicy`, is consumed by **no application code at all**: the only callers are test
assertions. It either needs a consumer or needs deleting, and that is a `CarrierPolicy`
decision rather than a Shopify one.

## What this does not do

It refuses the second Shopify label; it does not produce one. Whether `fulfillmentOrderSplit`
could give a genuine label per package is `13`, blocked on PolyBag having a multi-package
packing workflow at all.
