# Split Shopify fulfillment orders to buy one label per package

Status: needs-triage

Repo: `polybag`

## Blocked by

PolyBag has no multi-package packing workflow. Nothing here is actionable until it does —
see "Why this is blocked" below for what exists today. Spun out of `06`, which stops the
second Shopify purchase from being attempted at all.

## The question

Shopify's Admin API has a `fulfillmentOrderSplit` mutation. On paper it is the right shape:
split one fulfillment order into several by line item, and each resulting fulfillment order
buys its own label. That would make a Shopify shipment genuinely multi-package rather than
one label per order.

Whether it is worth doing is a different question, and at least these have to be answered:

- Does a split fulfillment order behave like an ordinary one for label purchase, voiding,
  and the fulfillment-state polling `ShopifyShippingLabelService` already does? The void
  path in particular reads `fulfillmentOrder(id:)` and inspects `fulfillments.nodes` — it
  assumes one fulfillment order per shipment.
- `shopify_fulfillment_order_id` lives on `shipments.metadata`. Splitting means a fulfillment
  order per **package**, so the identifier moves — to `packages`, or to a mapping table. That
  is a schema change and a migration for existing rows.
- What the merchant sees in their Shopify admin afterwards, and whether a split we performed
  is reversible if the packer changes their mind mid-pack.
- What happens when the split fails halfway: some line items moved, a box already taped.
- Whether the whole thing is better answered by *not* splitting — buy one Shopify label for
  the first package and require carrier-account postage for the rest, which is what `06`
  effectively enforces today.

## Why this is blocked

Multiple packages per shipment is a schema capability with no workflow on top of it:

- **The data model supports it.** `Shipment hasMany Package`, and
  `Shipment::updateShippedStatus()` sums packed quantities across every shipped package,
  holding the shipment `Open` until all items are covered. That method was written for
  splitting.
- **The pack flow half-supports it.** `EloquentPackageDraftWorkflow::resumeForShipment()`
  takes the oldest `Unshipped` package or creates one, so returning to `/pack/{id}` after
  shipping a box creates a second package — `PackTest` covers exactly that in "preserves
  shipped packages when creating a new one".
- **But packing validation forbids the split.** With `packing_validation_enabled` on (the
  default), `Pack::saveReadyPackageDraft()` passes `requireCompletePackedItems: true`, so
  every shipment item must be packed into that *single* package. A partial box cannot ship.
  A split is only reachable with the setting off globally, or in scan-to-add mode.
- **And the UI has no memory.** Resuming a shipment rebuilds `packingItems` from the current
  draft only, so the second pass shows every line at 0 packed with no record of what went out
  in the first box. There is no "add another package" control, no package counter, nothing in
  any Blade template or Filament page that mentions more than one package per shipment.
- **Batch ship refuses outright.** `createBatchReadyDraft()` throws if an active draft
  exists, and `BatchLabelService` skips shipments with unshipped packages.
- **Rating is single-package too.** `RateRequest` carries a `packages` array, but the USPS,
  FedEx and UPS adapters all read `$request->packages[0]`; UPS sums the weights into one
  parcel.

So a packer can reach two packages on one shipment only by turning off validation and
navigating back to the pack URL, unaided. Designing Shopify's split against that is designing
against an accident.

## Comments

### 2026-09-08 — reviewed in the sequencing pass; still blocked, deliberately last

Nothing about the label purchases in `01` changes this. The blocker is not Shopify's side —
`fulfillmentOrderSplit` has been there all along — it is that PolyBag has no multi-package
packing workflow to split *against*. That is unchanged.

Left at `needs-triage` rather than scheduled: the question this issue asks cannot be
answered before the packing workflow it depends on exists, and designing the Shopify half
first would be designing against the accident described above.
