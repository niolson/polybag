# An international Shopify purchase fails when the box weighs less than its contents

Status: ready-for-agent

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

- [ ] An international Shopify purchase whose box weighs less than Shopify's declared item
      weights fails **before** the offer is claimed, with a message naming the weights
- [ ] The message states both numbers and where the item weights come from, so the operator
      knows the fix is in the Shopify catalogue and not on the scale
- [ ] A domestic Shopify purchase is unaffected by the check
- [ ] The existing customs-weight override prompt does not fire on the Shopify path, or fires
      only where it can do something
- [ ] A test covers the failing relation with real numbers, against a faked fulfillment order

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
