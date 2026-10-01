# A void after export leaves the sales channel holding the voided tracking number

Status: needs-triage

Repo: `polybag`

Severity: medium. The customer is sent a tracking number that will never scan. No money
is at risk, and the operator can't correct it from PolyBag.
Verified: plausible (read, not tested; needs a real or recorded Shopify reply to settle).

## Problem

For a Label bought on our own account for a Shopify or Amazon order, the export writes
the tracking number back to the channel: `ShopifySource::exportPackage()` calls
`fulfillmentCreate`, and `AmazonSource::exportPackage()` calls `confirmShipment`. A void
then does two things locally and nothing on the channel. `clearShipping()` deletes the
Package's `PackageExport` rows and sets `exported = false`. Nothing asks the channel to
cancel or update the fulfillment that carries the voided number.

The re-shipped Label is then exported as if for the first time:

- **Shopify.** Creating the fulfillment closed the fulfillment order, so the second
  `fulfillmentCreate` against the same `fulfillment_order_id` is refused. The docblock on
  `exportPackage()` quotes the reply: "has an unfulfillable status= closed". That becomes
  a `PermanentExportException`. The order stays fulfilled with the voided number, and the
  export is permanently failed with no action that fixes it. If Shopify instead replies
  with a message containing "already fulfilled", the `$allPermanent` branch returns
  normally and the export is recorded as **succeeded**, while Shopify still carries only
  the dead number.
- **Amazon.** A second `confirmShipment` for the order adds a package, or is refused,
  depending on the order's state. Either way the first confirmation, with the voided
  number, stays on the order.

The Shopify Shipping and Amazon Buy Shipping paths don't have this problem: the
purchase is the fulfillment, and the void goes through the same party. The gap is a
direct Label on a channel order that was voided after its export ran. The export runs
every five minutes, so that is almost any void that isn't made right after the purchase.

## What to verify

- Record Shopify's reply to `fulfillmentCreate` on a closed fulfillment order, and decide
  whether the "already fulfilled" branch can catch it.
- Record what `confirmShipment` does for an order already confirmed with another
  tracking number.

## What to build

The channel should end up carrying the live number. There are two shapes, and triage
should pick one:

1. **Update in place.** When the Package was exported before, the re-export updates the
   existing fulfillment's tracking (`fulfillmentTrackingInfoUpdate` on Shopify; Amazon has
   no equivalent in `confirmShipment` and would need investigating). This means storing
   the channel's fulfillment ID on the export row, or on the Label row, which is where
   ADR-0004 would put it.
2. **Cancel on void.** The void workflow cancels the channel fulfillment
   (`fulfillmentCancel` reopens the fulfillment order on Shopify) before clearing, so the
   re-export is a genuine first export.

Either way, a void that can't correct the channel should say so to the operator, not
stay silent. At minimum, the "already fulfilled" swallow should not apply to a Package
that has a voided Label.

## Comments

- 2026-10-01 — Desk research against the official docs (Shopify Admin 2026-07, Amazon
  Orders v0). Still needs live confirmation.
  - **Shopify: update in place.** `fulfillmentTrackingInfoUpdate(fulfillmentId,
    trackingInfoInput, notifyCustomer)` changes the number on an existing fulfillment.
    Cancel-on-void doesn't give a clean re-export: `fulfillmentCancel` creates **new**
    fulfillment orders for the cancelled items, with new IDs, so a `fulfillmentCreate`
    against the stored `fulfillment_order_id` is still refused. `fulfillmentCreate` already
    selects `fulfillment { id }`, but `exportPackage()` discards it. It has to be stored
    somewhere a void doesn't delete (the Label row, per ADR-0004, not `PackageExport`).
    The "has an unfulfillable status= closed" reply is reported by developers but not
    documented. No documented reply contains "already fulfilled", so the swallow probably
    can't match it.
  - **Amazon: probably works already.** The v0 guide says `confirmShipment` called again
    with the same `packageReferenceId`, order items and quantities edits the package's
    carrier, method and tracking ID. Ours is the Package ID, which stays the same across a
    void, so the re-export should replace the dead number. No time window is documented.
    The risk is `shipmentWasAlreadyConfirmed()`: if Amazon refuses the edit as already
    shipped, that is recorded as success and the dead number stays.
  - **Still to verify live:** Shopify's exact `userErrors` for a closed fulfillment order,
    whether `fulfillmentTrackingInfoUpdate` is refused on old or delivered fulfillments,
    and Amazon's reply to a re-confirm. The Amazon sandbox only matches its own fixture,
    so the Amazon check needs a production order.
