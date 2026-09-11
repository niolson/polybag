# A Shopify-side void replaces the fulfillment order, and the shipment keeps the dead ID

Status: done — 2026-09-09

Repo: `polybag`

## Problem

When a Shopify Shipping label is voided, Shopify does not reopen the fulfillment order it
was bought against. It **closes that one permanently and creates a replacement.** Observed
three times — twice through the synchronizer's path, once on a void done by hand.

`applyVoid()` returned the package to `unshipped` and stripped the four label identifiers,
but nothing updated `shipment.metadata.shopify_fulfillment_order_id`. So after a void
`canPurchaseFor()` was true (there is a stored ID), `shipmentAlreadyBoughtALabel()` was
false (the markers were cleared), the offer was shown to the packer again, and the purchase
failed with `FULFILLMENT_ORDER_INVALID` after the box was taped shut — precisely the
failure `06` withdrew the offer to prevent, arrived at from the other direction.

**It contradicted shipped code's own docblock**, which said voiding reopens the fulfillment
order. The *order* becomes fulfillable again; the fulfillment order does not.

Two things that are **not** the lever: `orderOpen` (the order behind a voided label is not
closed, and the closure is per fulfillment order), and `fulfillmentOrderOpen` (which applies
to *scheduled* fulfillment orders, a different state). There is no mutation that reopens a
closed fulfillment order.

## What shipped — with `21`, as one fix for one root cause

**Refreshing, not clearing.** `applyVoid()` re-resolves the shipment's fulfillment order
after un-shipping the package, keeping those whose `supportedActions` carry
`CREATE_FULFILLMENT`, assigned to the shipment's location, **whose goods fingerprint
matches the shipment's**, and that no other shipment of that source already names.
`source_record_id` moves with the metadata ID — left behind, it names the dead fulfillment
order and the next import reads the replacement as work it has never seen.

Clearing remains the answer to one case: **no** fulfillable fulfillment order, meaning the
order cannot be shipped through Shopify at all. There the stored ID goes and
`canPurchaseFor()` withdraws the offer on its own — fail-closed, without giving up the
re-ship path in the case that has one. Several candidates is no answer and the ID is left
alone. `null` keeps meaning "don't know".

The docblock is corrected: it now says Shopify closes the fulfillment order and creates a
replacement, and that what makes a voided shipment buyable again is `applyVoid()` stripping
the markers **and** re-pointing at that replacement.

## Comments

- **2026-09-09** — found while probing `02`: a purchase attempt against a voided package
  returned `FULFILLMENT_ORDER_INVALID`. Worth knowing how that looks from outside — *every
  subsequent probe* comes back the same way, so a screen of them reads like the service
  codes went bad, and says nothing about the stored ID being the stale half. Re-pointing by
  hand restored the purchase path immediately with no other change, which is the evidence
  that refreshing is sufficient and that nothing else about the shipment is invalidated.
- **2026-09-09** — the churn reaches the import too, filed as `21`: the replacement arrives
  as a GID the import has never seen and is inserted as a **duplicate shipment**. That
  changed the fix — the import-side re-point covers both ends, and fixing this alone would
  have left a duplicate in the packing queue.
- **2026-09-09, review** — the first implementation filtered only on `supportedActions` and
  location, which is not sufficient: an order split at one location leaves siblings that
  pass both and are **for different goods**. Writing one of those in points the purchase
  path at another shipment's work — worse than the stale ID this issue was filed about,
  because a stale ID fails loudly while a sibling's ID buys a label and ships the wrong
  parcel. Candidates now carry a goods fingerprint (see `21`).
- **2026-09-09** — none of the above had ever run against the live API. The query asked
  `Order.fulfillmentOrders` for `includeClosed: false`, which it does not accept, so every
  call threw and was caught. See `22`. Nothing user-visible broke, because `21`'s
  import-side re-point is a working backstop and this issue's docblock already relied on it.

## Related

- `21` — the same root cause on the import path
- `22` — the invalid query that kept this fix from ever executing
- `06` — the offer withdrawal this failure mode arrives around
