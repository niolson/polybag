# An international Shopify purchase fails when the box weighs less than its contents

Status: done — 2026-09-09

Repo: `polybag`

## Problem

Shopify refuses an international label whose `totalWeight` is **below the sum of the item
weights on its own customs declaration**, and reports it as `UNKNOWN_ERROR` — no field, no
code, nothing an operator or a log reader can act on.

PolyBag sends the weight of the packed box, read from the scale. Shopify builds the customs
declaration from **its own product catalogue**. When the catalogue's weights add up to more
than the box weighs, every international purchase fails, after the box is taped shut.

Isolated by sending PolyBag's exact production input and varying one field at a time: the
same input with `totalWeight` raised to the declared sum bought a label, and dropping
`originAddress` or `packageInfo` changed nothing. So both are innocent and the cause is
exactly the weight relation. The same shipment sells fine in the Shopify admin, which names
the condition properly — *"Items and package do not add up to total weight"* — where the API
gives only `UNKNOWN_ERROR`.

**Domestic purchases are unaffected**, which is why this went unseen: no customs
declaration, no item weights to contradict.

## Why PolyBag's existing fix cannot be used

PolyBag already handles this condition for its own carriers, and handles it **in the
direction Shopify forbids**. `requiresCustomsWeightOverride()` detects
`sum(customsItems) > packageData.weight`, prompts the operator, and
`withScaledCustomsWeights()` scales the declared item weights *down* until they fit the box.
That works because PolyBag builds the customs items.

On the Shopify path there is nothing to scale — the declaration is assembled on Shopify's
side — so the scaling runs over an array nobody sends and changes nothing Shopify sees. The
constraint is inverted: **Shopify requires `totalWeight >= sum(item weights)`**, and the
only lever PolyBag holds is the total.

## What shipped — detect and withhold, with a tolerance and an escape hatch

`ShopifyShippingLabelService::totalWeightFor()` runs before the mutation:

| Relation | What is sent |
|---|---|
| box ≥ declared | the scale reading — the normal case, and the physically expected one |
| short by ≤ 0.1 lb | the declared sum, rounded **up** to two decimals |
| short by > 0.1 lb | nothing — `ShopifyDeclaredWeightException`, before the mutation |

The 0.1 lb band answers a question review asked and the issue did not. A packed box
outweighs its contents, so *any* shortfall is somebody being imprecise; within 1.6 oz that
somebody is the scale, and the nudge costs nothing real because the carrier rounds up to the
next ounce anyway. Beyond it the catalogue is describing goods that are not in the box, and
no arithmetic makes that true. Rounded up, never to nearest.

**The escape hatch sends the scale weight, unchanged.**
`ShipRequest::withDeclaredWeightOverride()` waives the refusal, not the reading — nothing is
over-declared by insisting, and no untrue weight reaches a customs form. What it buys is the
case PolyBag cannot see: a catalogue corrected in the Shopify admin between the refusal and
the retry. Raising `totalWeight` to the declared sum on the operator's confirmation was
considered and not built — for the observed package it means buying 2.29 lb of postage for a
0.15 lb parcel, and printing 2.29 lb on the customs form.

**Read live, not from the snapshot.** The sum comes from
`lineItems.nodes.lineItem.variant.inventoryItem.measurement.weight`, deliberately not from
`FulfillmentOrderLineItem.weight`, which is a snapshot taken when the order was placed: a
merchant who corrects a weight in response to a refusal has to be able to retry
successfully, and the snapshot would keep refusing.

`null` keeps meaning "cannot ask" — a fulfillment order Shopify no longer returns, or a
line-item page that did not fit, proceeds at the scale weight. Grounding shipments over a
paging limit is a worse failure than the one this prevents.

## The three questions

**1. The relation is `>=`, confirmed by purchase.** 6.11 lb of declared goods in an 8.5 lb
box — 2.39 lb of packaging over the declaration — bought first time. Fragile goods are the
case that makes this matter: a lot of packaging around not much product is exactly the
parcel an `==` reading would have grounded. The free oracle from `02` could not have
answered it, because Shopify validates the ship date before the weight.

**2. Yes, the existing prompt fired — and it was useless.** `ImportReferenceResolver` writes
the imported weight onto the `Product`, so the offending package's own products carried
Shopify's exact numbers. `requiresCustomsWeightOverride()` saw 2.29 > 0.15 and asked, the
operator confirmed, the scaling changed nothing Shopify sees, and the purchase still failed
— a confirmation that changes nothing, followed by a failure for the reason the operator
thought they had just resolved. It now returns false for any blind purchase.

**3. In `ShopifyShippingLabelService`, where the constraint lives** — a third option this
issue did not list. It has to be before the mutation, because a failed purchase closes the
fulfillment order and forces a repoint (`18`); the connector is already open there; and only
an international destination pays for it. The workflow's part is to catch the exception and
turn it into a prompt rather than a shipping error, beside `MissingDeclaredValueException`.

## Comments

- **2026-09-09** — found while answering `01`'s question 6. One caution for anyone re-running
  this: **every attempt, failed or successful, closes the fulfillment order** and Shopify
  creates a replacement (`18`). Three accumulated on one order in an afternoon, and a stale
  stored ID turns every subsequent probe into `FULFILLMENT_ORDER_INVALID` — which reads
  exactly like a result and is not one.
- **2026-09-09, review** — the live catalogue read was raised as a P1: `lineItem.variant` is
  gated behind `read_products`, absent from
  `ShopifyFulfillmentOrderActivationService::REQUIRED_SCOPES`, and `activate()` returns early
  for an already-activated source — so a store that predates this would fail every
  international purchase on a GraphQL access error. **The read now degrades instead of
  failing**: it is the only query in the service that does not throw on a GraphQL error, and
  the asymmetry is the point — every other one is load-bearing, while this one only decides
  whether to *withhold*. Both weights now ride on one request, so a store without
  `read_products` keeps the check against order-time weights rather than losing it. Live is
  still preferred where readable. `read_products` is declared on the service's own
  `REQUIRED_SCOPES` rather than the activation list, deliberately against the letter of the
  review: the activation list is enforced on every location sync, so promoting a
  label-purchase dependency into it would start refusing location syncs to sources that only
  import orders.
- **Worth knowing for anyone chasing scopes:** Shopify ignores the OAuth `scope` parameter
  for apps with declared scopes, so the Dev Dashboard is the authority and reconnecting
  grants nothing new. The Data Source screen shows `settings.oauth_scopes` cached at connect
  time — on the development store that is the empty string while the live token holds eight
  scopes including `read_products`. A display worth not trusting.

## Still open

**PolyBag never sees a partial fulfillment.** The sum is over `remainingQuantity` on the
whole fulfillment order, which is what Shopify declares — but `13` would split that, and
this comparison would need to split with it.

## Related

- `01` — question 6, the international purchase this was found under
- `07` — the customs form the successful purchase returned
- ADR-0002 — postage bought on a seller's account, and what PolyBag is entitled to know
