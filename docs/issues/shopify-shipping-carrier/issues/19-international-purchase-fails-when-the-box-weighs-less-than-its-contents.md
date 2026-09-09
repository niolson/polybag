# An international Shopify purchase fails when the box weighs less than its contents

Status: done — 2026-09-09

Repo: `polybag`

## Problem

Shopify refuses an international label whose `totalWeight` is **below the sum of the item
weights on its own customs declaration**, and reports it as `UNKNOWN_ERROR` — no field, no
code that names the cause, nothing an operator or a log reader can act on.

PolyBag sends the weight of the packed box, read from the scale. Shopify builds the customs
declaration from **its own product catalogue**. When the catalogue's weights add up to more
than the box weighs, every international purchase fails, after the box is taped shut.

Observed 2026-09-09, package 207, order #1240, a Canadian destination:

| | |
|---|---|
| `totalWeight` PolyBag sent | 0.15 lb |
| Sum of Shopify's line-item weights | 2.29 lb (1.76 + 0.53) |
| Result | `PURCHASE_FAILED` / `UNKNOWN_ERROR`, three times — `auto` and explicit `ups_shipping:11` |

Isolated by sending PolyBag's exact production input and varying one field at a time:

| Input | Result |
|---|---|
| Full input, `totalWeight` 0.15 lb | `UNKNOWN_ERROR` |
| Full input minus `originAddress` | `UNKNOWN_ERROR` — not the origin override |
| Full input minus `packageInfo`, `totalWeight`, `originAddress` | `PURCHASED` |
| **Full input, `totalWeight` 2.29 lb, everything else unchanged** | **`PURCHASED`** |

So `packageInfo` and `originAddress` are both innocent and the cause is exactly the weight
relation. The same shipment sells fine in the Shopify admin, which surfaces the condition as
a blocking error — *"Items and package do not add up to total weight. If the total weight is
correct, update item weight on the customs declaration accordingly."* — where the API gives
only `UNKNOWN_ERROR`.

**Domestic purchases are unaffected**, which is why this went unseen: no customs declaration,
no item weights to contradict. Packages 204 and 205 both bought domestic labels on the same
store the day before.

## Why PolyBag's existing fix cannot be used

PolyBag already handles this condition for its own carriers, and handles it **in the
direction Shopify forbids**.

`EloquentPackageShippingWorkflow::requiresCustomsWeightOverride()` detects
`sum(customsItems) > packageData.weight` on an international shipment, prompts the operator,
and `ShipRequest::withScaledCustomsWeights()` then scales the declared item weights *down*
proportionally until they fit the box. That works because PolyBag builds the customs items
and hands them to `UspsAdapter` and friends — see `UspsAdapter`'s mapping of USPS error
`160021`, the same condition named properly by a carrier that names things.

On the Shopify path there is nothing to scale. `buildPurchaseInput()` sends
`fulfillmentOrderId` and `totalWeight`; the declaration is assembled on Shopify's side from
`inventoryItem.measurement.weight`. **`withScaledCustomsWeights()` runs, scales a
`customsItems` array nobody sends, and changes nothing Shopify sees** — so the operator can
be prompted, confirm the override, and still get `UNKNOWN_ERROR`.

The constraint is inverted: **Shopify requires `totalWeight >= sum(item weights)`**, and the
only lever PolyBag holds is the total.

## Options

- **Detect and withhold.** Compute the sum before offering Shopify postage and refuse with a
  message that names the real cause. The weights are readable on the fulfillment order
  PolyBag already queries — `lineItems.nodes.lineItem.variant.inventoryItem.measurement.weight`
  — so this costs one field on an existing request. Verified: the query returns 1.76 + 0.53
  and the admin's own *"Reset to 2.29 lb"* agrees exactly.
- **Raise `totalWeight` to the declared sum.** Makes the purchase succeed, and is a real
  decision rather than a fix: it over-declares the parcel against the scale, pays postage on
  weight that is not there, and tells the carrier something untrue. Not to be done silently,
  and arguably not at all.
- **Fix the catalogue.** The true defect in the observed case is Shopify product weights that
  do not describe the goods. PolyBag cannot write them and should not — that is the merchant's
  catalogue.

Options one and three are complementary; the middle one needs a deliberate decision and
probably an explicit operator confirmation if it happens at all.

## What to answer

1. **Is the relation `>=` or `==`?** Only `<` is observed to fail and `==` observed to
   succeed. Whether Shopify tolerates a box heavier than its contents — it must, since
   packaging has weight — is untested, though the admin's amber-versus-pink warning pair
   implies over is advisory and under is blocking.
2. **Does the existing customs-weight prompt fire on the Shopify path today?** If
   `customsItems` is populated for a Shopify package, the operator is being asked to confirm
   an override that cannot work, which is worse than not asking.
3. **Where does the check belong** — beside `requiresCustomsWeightOverride()` as a second
   arm of the same gate, or in `ShopifyAdapter` where the constraint actually lives?

## Acceptance criteria

- [x] An international Shopify purchase whose box weighs less than Shopify's declared item
      weights fails **before** the offer is claimed, with a message naming the weights
- [x] The message states both numbers and where the item weights come from, so the operator
      knows the fix is in the Shopify catalogue and not on the scale
- [x] A domestic Shopify purchase is unaffected by the check
- [x] The existing customs-weight override prompt does not fire on the Shopify path, or fires
      only where it can do something
- [x] A test covers the failing relation with real numbers, against a faked fulfillment order

## Blocked by

Nothing. The condition is reproducible on the development store and the fix is local.

## Related

- `01` — question 6, the international purchase this was found under
- `07` — the customs form the successful purchase returned
- `18` — the fulfillment order churn this ran into repeatedly while retrying
- ADR-0002 — postage bought on a seller's account, and what PolyBag is entitled to know

## Comments

### 2026-09-09 — found while answering `01`'s question 6

Not looked for. `01`'s international test order was created to see whether a `CUSTOMS_FORM`
document comes back, hit `Missing harmonized system code` first — a separate precondition,
fixed by setting country of origin and HS code on the two variants in the Shopify admin —
and then failed with `UNKNOWN_ERROR` on every attempt.

The free oracle from `02` cleared the ground before any of the isolation work: a probe with a
past ship date returned `SHIPPING_DATE_IN_THE_PAST`, so a rate had resolved and the failure
was downstream of rate selection. Enumerating the Canada pairs then showed rates for
`ups_shipping:11/07/08/65` and `dhl_express:P`. The admin sold the same shipment as UPS
Standard for $20.94, which ruled out the store, the destination and the carrier and left only
what PolyBag was sending.

**One caution for anyone re-running this.** Every failed attempt, and every successful one,
closes the fulfillment order and Shopify creates a replacement (`18`). Three accumulated on
this one order in an afternoon, and a stale stored ID turns every subsequent probe into
`FULFILLMENT_ORDER_INVALID` — which reads exactly like a result and is not one. Re-read the
open fulfillment order between attempts.

### 2026-09-09 — done: detect and withhold, with a tolerance and an escape hatch

**Option one, plus a band.** `ShopifyShippingLabelService::totalWeightFor()` runs before the
mutation and resolves the total weight three ways:

| Relation | What is sent |
|---|---|
| box ≥ declared | the scale reading — the normal case, and the physically expected one |
| short by ≤ 0.1 lb | the declared sum, rounded up to two decimals |
| short by > 0.1 lb | nothing — `ShopifyDeclaredWeightException`, before the mutation |

The 0.1 lb band answers the question this issue did not ask and the review did. A packed box
outweighs its contents, so *any* shortfall is somebody being imprecise; within 1.6 oz that
somebody is the scale, and the nudge costs nothing real — the carrier rounds up to the next
ounce regardless. Beyond it the catalogue is describing goods that are not in the box, and no
arithmetic makes that true. Rounded **up**, never to nearest: two decimals standing in for a
sum reached through a unit conversion have to stay at or above it.

**The escape hatch sends the scale weight, unchanged.** `ShipRequest::withDeclaredWeightOverride()`
waives the refusal, not the reading — nothing is over-declared by insisting, and no untrue
weight reaches a customs form. What it buys is the case PolyBag cannot see: a catalogue
corrected in the Shopify admin between the refusal and the retry. The alternative — raising
`totalWeight` to the declared sum on the operator's confirmation — was considered and not
built, for the reason this issue gives: for package 207 it means buying 2.29 lb of postage
for a 0.15 lb parcel and printing 2.29 lb on the customs form.

**Read live, not from the snapshot.** The sum comes from
`lineItems.nodes.lineItem.variant.inventoryItem.measurement.weight`, as this issue proposed,
and deliberately not from `FulfillmentOrderLineItem.weight`, which the import already asks
for and which is a snapshot taken when the order was placed. The declaration is built at
purchase time from the catalogue, so a merchant who corrects a weight in response to a
refusal has to be able to retry successfully — the snapshot would keep refusing. The
conversion table is now shared: `ShopifySource::poundsFrom()`, one copy for both readers.

`null` keeps meaning "cannot ask". A fulfillment order Shopify no longer returns, or a
line-item page that did not fit, proceeds at the scale weight rather than withholding —
grounding shipments over a paging limit is a worse failure than the one this exists to
prevent.

## The three questions

**1. `>=`. Confirmed by purchase, not inferred.** Shipment 6763, order #1236, a Canadian
destination: 6.11 lb of declared goods (2.67 × 2 + 0.77, live catalogue and order snapshot
agreeing exactly) shipped in an 8.5 lb box — **2.39 lb of packaging over the declaration** —
bought `PURCHASED` on the first attempt as UPS Standard (`ups_shipping:11`), $29.07, tracking
`1Z28X87G6824931242`, with a `CUSTOMS_FORM` returned. So a box heavier than its contents is
accepted, which it had to be, and the tolerance in `totalWeightFor()` is only ever needed in
the one direction.

The free oracle from `02` could not have answered this: Shopify validates the ship date before
it validates the weight, so a past-date probe returns `SHIPPING_DATE_IN_THE_PAST` whatever the
weight is. It cost one real purchase, which on the development store is a test label.

Fragile goods are the case that makes this matter — a lot of packaging around not much
product is exactly the shape of parcel that would have been grounded by an `==` reading of
the constraint.

**2. Yes, it fired — and it fired on package 207.** The prompt was not bypassed; it was
useless. `ImportReferenceResolver::productIdFor()` writes the imported weight onto the
`Product`, so package 207's own products carry `weight` 1.76 and 0.53 — Shopify's exact
numbers, put there by the same import that created the fulfillment order.
`requiresCustomsWeightOverride()` saw 2.29 > 0.15 and asked; the operator confirmed;
`withScaledCustomsWeights()` scaled an array nobody sends; Shopify still said
`UNKNOWN_ERROR`. That is the worst version of this: a confirmation that changes nothing,
followed by a failure for the reason the operator thought they had just resolved.
`requiresCustomsWeightOverride()` now returns false for any blind purchase, since the seller
builds the declaration from its own catalogue and there is nothing of ours to scale.

**3. In `ShopifyShippingLabelService`, where the constraint lives** — the third option this
issue did not list, and the one the cost decides. It has to be before the mutation, because a
failed purchase closes the fulfillment order and forces a repoint (`18`); the connector is
already open there, so the extra query is one request on a path that already makes several;
and only an international destination pays for it at all. Nothing is claimed for a blind
offer — `ShippingOffer` rows exist only behind quoted rates — so "before the offer is
claimed" is satisfied by construction. The workflow's part is to catch the exception and turn
it into a prompt rather than a shipping error, beside `MissingDeclaredValueException`, which
is the same shape of thing: an operator-facing precondition, not a carrier failure.

## What shipped

- `app/Exceptions/ShopifyDeclaredWeightException.php` — carries both numbers and the message
- `ShopifyShippingLabelService::totalWeightFor()` / `declaredItemWeight()`, `DECLARED_ITEM_WEIGHT_QUERY`, `DECLARED_WEIGHT_TOLERANCE`
- `ShopifySource::poundsFrom()` — the conversion table, now shared
- `ShipRequest::$overrideDeclaredWeight` / `withDeclaredWeightOverride()`
- `EloquentPackageShippingWorkflow` — the blind-purchase guard on the customs prompt, and the catch
- `PackageShippingResult::declaredWeightOverrideRequired()` — leaves the package intact
- `Ship` page + `declared-weight-override` modal — "Try anyway at the scale weight"
- 11 tests: eight against a faked fulfillment order in `ShopifyAdapterTest`, three through the
  workflow and the Ship page in `BlindPurchaseTest`

## Still open

One thing this could not check: **PolyBag never sees a partial fulfillment.** The sum is over
`remainingQuantity` on the whole fulfillment order, which is what Shopify declares — but `13`
(one fulfillment order per package) would split that, and this comparison would need to split
with it.

### 2026-09-09 — after review: the weight read is advisory, and says so

Review raised the live catalogue read as a P1: `lineItem.variant` is gated behind
`read_products`, which is absent from
`ShopifyFulfillmentOrderActivationService::REQUIRED_SCOPES`, and `activate()` returns early
for a source already activated — so a store that predates this never re-checks and every
international purchase would fail on a GraphQL access error.

The dependency is real. The severity was the fix worth making, and it is not a scope gate.

**The read now degrades instead of failing.** This is the only query in
`ShopifyShippingLabelService` that does not throw on a GraphQL error, and the asymmetry is
the point: every other one is load-bearing — without it there is no label, or no way to find
one already bought — while this one only decides whether to *withhold*. Throwing turned a
missing grant, or a throttle, into a failed purchase on every international label: the same
failure, in the same place, after the box is taped shut, as the defect this issue was filed
about. Errors are logged with the scope named, and whatever data came back is used.

**Both weights ride on one request.** `FulfillmentOrderLineItem.weight` — the order-time
snapshot, no product scope — is now asked for beside the live catalogue value, and used per
line item when the live one is missing. Shopify nulls a denied field rather than the
response, so a store without `read_products` keeps the check against order-time weights
instead of losing it. Live is still preferred where it is readable, for the reason it was
chosen: a merchant correcting a product weight after a refusal has to be able to retry
successfully, and the snapshot still names the weight the order was placed at.

**`read_products` is declared on `ShopifyShippingLabelService::REQUIRED_SCOPES`, not the
activation list** — deliberately, against the letter of the review. The activation list is
enforced at activation *and* on every location sync
(`ShopifyLocationSynchronizer::synchronize()`, which re-asks Shopify live rather than
trusting the cached `oauth_scopes`), so promoting a label-purchase dependency into it would
start refusing location syncs to sources that only import orders and never buy postage. That
separation is what the two constants are for, and the label list is what reaches the
connect-time scope parameter.

Three tests cover the degradation: a denied `variant` traversal falling back to the snapshot,
a throttled read letting the purchase through at the scale weight, and the live value winning
over a stale snapshot.

**Worth knowing for anyone chasing this.** Shopify ignores the OAuth `scope` parameter for
apps with declared scopes, so the Dev Dashboard is the authority and reconnecting does not by
itself grant anything new. The Data Source screen shows `settings.oauth_scopes` cached at
connect time, which on the development store is the empty string while the live token holds
eight scopes including `read_products` — a display worth not trusting, and arguably worth
replacing with the live answer `fetchAccessScopes()` already returns.
