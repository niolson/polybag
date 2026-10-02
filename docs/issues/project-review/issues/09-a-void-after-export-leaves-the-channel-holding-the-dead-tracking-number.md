# A void after export leaves the sales channel holding the voided tracking number

Status: done — 2026-10-02 (Shopify; Amazon relies on the Orders v0 docs)

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
- 2026-10-02 — Triage: **cancel on void** for Shopify (shape 2). Updating in place leaves
  the fulfillment order closed until the re-ship, and the re-ship can be days later or
  bought through Shopify Shipping, which needs an open fulfillment order:
  `shippingLabelPurchase` can't buy against a closed one. Cancelling keeps Shopify's state
  matching PolyBag's: a voided Package is unshipped in both. Shopify's own assistant
  describes the same flow (`fulfillmentCancel`, then `fulfillmentCreate` against the
  replacement fulfillment order). The build needs:
  - **Keep the fulfillment ID.** `exportPackage()` already selects `fulfillment { id }`;
    store it on the Label row (ADR-0004), not on `PackageExport`, because `clearShipping()`
    deletes those rows.
  - **Cancel after a void is recorded.** Covers operator voids and Record Void. Shopify
    Shipping Labels are skipped: they never ran `fulfillmentCreate`, and their void
    already goes through the Shopify admin.
  - **Re-point the Shipment.** `fulfillmentCancel` opens a *new* fulfillment order, so
    `fulfillment_order_id` and `source_record_id` have to move to it, the way
    `ShopifyFulfillmentSynchronizer::repointFulfillmentOrder()` already does for Shopify
    Shipping voids. Pull that logic out and share it; don't copy it.
  - **Report a failed cancel.** The void stands (the carrier has already voided the
    label), but the operator is told that Shopify still shows the old number.
  - **Narrow the swallow.** The "already fulfilled" swallow must not apply to a Package
    that has a voided Label.
  - **Verify on the test store** (`polybag-test.myshopify.com`): export, void, cancel,
    re-ship by direct Label, then repeat with a Shopify Shipping re-ship.

  Amazon is not covered by this decision. The 2026-10-01 research suggests a re-confirm with
  the same `packageReferenceId` already replaces the number. That needs a production order
  to confirm; split it out when the Shopify side is built.
- 2026-10-02 — **Shopify built.**
  - `ShopifySource::exportPackage()` returns the fulfillment ID, and
    `ExportDestinationInterface::exportPackage()` now returns `?string`.
    `PackageExportService` stores the ID in `package_labels.shopify_fulfillment_id`, but
    only for the shipment's own Shopify source and only on the active Label with the
    exported tracking number.
  - `EloquentPackageLabelWorkflow` calls `ShopifyFulfillmentCanceller` after an operator
    void or a Record Void. It reads the ID from the Label just voided and sends
    `fulfillmentCancel`. On success it re-points the Shipment through
    `ShopifyFulfillmentOrderReplacer`, which is the synchronizer's re-point logic moved
    into its own class so both paths use it. If the cancel fails, the void stands and
    `LabelVoidResult::$warning` puts a persistent notification on screen telling the
    operator to cancel the fulfillment in the Shopify admin.
  - The "already fulfilled" swallow no longer applies to a Package that has a voided
    Label.
  - Tests: `tests/Feature/ShopifyFulfillmentCancelOnVoidTest.php`, plus two in
    `ShopifyImportExportTest.php`.
  - **Still to run live** on `polybag-test.myshopify.com`. Check:
    - which fulfillment order `fulfillmentCancel` reopens the goods on (the same one or a
      new one; the re-point handles both);
    - what Shopify says when it refuses (for example, a delivered fulfillment);
    - that the re-ship works both ways: a direct Label export, and a Shopify Shipping
      purchase.
  - **Amazon:** goes by the Orders v0 docs, because there is no practical way to test
    with a production order. A re-confirm with the same `packageReferenceId` replaces the
    tracking number, so nothing is built. One risk is left open:
    `shipmentWasAlreadyConfirmed()` still reads an "already confirmed" refusal as success
    even after a void. The same `_has_voided_label` guard would turn it into a visible
    failure.
- 2026-10-02 — **Live run on the test store, first half.** A direct Label was exported,
  then voided. `fulfillmentCancel` succeeded. The Shopify timeline shows "PolyBag canceled
  fulfillment" and the order is unarchived (Shopify had auto-archived it once fulfilled).
  The goods came back on a **new** fulfillment order: `…/22140102770902` was replaced by
  `…/22140109783254`, and the Shipment was re-pointed at the new one (audit 39299). The
  timeline's "Service: Manual" is Shopify's name for merchant-managed fulfillment, not
  the shipping service; `FulfillmentInput` has no field for that. Still to run: re-ship
  the Package by direct Label and by Shopify Shipping, and a cancel Shopify refuses.
- 2026-10-02 — **Live run, second half.**
  - **Direct re-ship:** the new fulfillment `…/7266964111574` sits on the re-pointed
    fulfillment order `…/22140109783254`, confirmed by querying the fulfillment's
    `fulfillmentOrders`.
  - **Shopify Shipping re-ship** after a void and cancel: works.
  - **Repeated cancel is not refused.** The fulfillment was cancelled in the admin
    (17:17:47), then voided in PolyBag. `fulfillmentCancel` on the already-cancelled
    fulfillment returned no `userErrors`, so no warning showed. The Shipment was
    re-pointed onto the fulfillment order the admin cancel had opened (`…/22140181020886`,
    OPEN). So cancelling in the admin before or after the void is harmless.
  - **Still unseen:** a real refusal and its text. The refusal path is covered only by
    faked responses.
- 2026-10-02 — Code review: `cancelFulfillment()` now succeeds only when Shopify returns
  the same fulfillment ID with status `CANCELLED`. Before, a reply with no
  `fulfillmentCancel` or no `fulfillment` passed as a success. Checked live: repeating
  `fulfillmentCancel` on the already-cancelled `…/7267003007190` returns that fulfillment
  as `CANCELLED` with no `userErrors`. So an admin cancel followed by a PolyBag void still
  passes the check and shows no warning.
