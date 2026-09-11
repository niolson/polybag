# Split Shopify fulfillment orders to buy one label per package

Status: needs-triage

Repo: `polybag`

## Blocked by

PolyBag has no multi-package packing workflow. Nothing here is actionable until it does.
Spun out of `06`, which stops the second Shopify purchase from being attempted at all.

## The question

Shopify's `fulfillmentOrderSplit` mutation splits one fulfillment order into several by
line item, and each resulting fulfillment order buys its own label. On paper that makes a
Shopify shipment genuinely multi-package. Whether it is worth doing needs at least:

- Does a split fulfillment order behave like an ordinary one for purchase, voiding, and
  the fulfillment-state polling `ShopifyShippingLabelService` does? The void path reads
  `fulfillmentOrder(id:)` and inspects `fulfillments.nodes`, assuming one per shipment.
- `shopify_fulfillment_order_id` lives on `shipments.metadata`. Splitting moves it to the
  **package**, or to a mapping table — a schema change and a migration for existing rows.
- What the merchant sees in their admin afterwards, and whether a split is reversible if
  the packer changes their mind mid-pack.
- What happens when the split fails halfway: some line items moved, a box already taped.
- Whether the answer is *not* splitting — one Shopify label for the first package and
  carrier-account postage for the rest, which is what `06` enforces today.

`19` adds one more: its declared-weight check sums `remainingQuantity` over the whole
fulfillment order, and a split would have to split that comparison with it.

## Why this is blocked

Multiple packages per shipment is a schema capability with no workflow on top of it:

- **The data model supports it.** `Shipment hasMany Package`, and
  `updateShippedStatus()` sums packed quantities across shipped packages, holding the
  shipment `Open` until all items are covered. That method was written for splitting.
- **The pack flow half-supports it.** `resumeForShipment()` takes the oldest `Unshipped`
  package or creates one, so returning to `/pack/{id}` after shipping a box creates a
  second package.
- **Packing validation forbids the split.** With `packing_validation_enabled` on (the
  default), every shipment item must be packed into that *single* package. A partial box
  cannot ship.
- **The UI has no memory.** Resuming rebuilds `packingItems` from the current draft only,
  so the second pass shows every line at 0 packed. No "add another package" control exists
  anywhere.
- **Batch ship refuses outright**, and **rating is single-package too** — every adapter
  reads `$request->packages[0]`.

So a packer reaches two packages on one shipment only by turning off validation and
navigating back to the pack URL unaided. Designing Shopify's split against that is
designing against an accident.

## Comments

- **2026-09-08** — reviewed in the sequencing pass. Nothing about the label purchases in
  `01` changes this: the blocker is PolyBag's side, not Shopify's. Left `needs-triage`
  rather than scheduled.
