# Order reconciliation: orders shipped or cancelled outside PolyBag

Status: reference

Repo: `polybag`

Opened 2026-10-12. Takes over the cancellation and purchase-time parts of
`shelved-drafts/01`, and adds orders fulfilled by other software.

## Why

A tenant may use PolyBag for only some of its orders and ship the rest with other
software, or from a warehouse PolyBag does not run. Orders also get cancelled. PolyBag
does not find out about either. The order is still `Open` here, it can still be packed,
and a Label can still be bought for it. That pays postage twice and sends the goods
twice.

## Current state

- **Imports fetch only open orders.** Shopify reads every fulfillment order with
  `includeClosed: false` on each run. Amazon reads `UNSHIPPED`/`PARTIALLY_SHIPPED` orders
  updated within the connection's `lookback_days`. An order fulfilled elsewhere or
  cancelled just drops out of the feed, and nothing changes locally.
- **A Database source can report a status, but it is dropped once there is a Package.**
  `ShipmentBatchWriter::shouldUpdate()` refuses to move an open Shipment off `open` when
  it has any Package, a Package Draft included. That protects packed work, but it also
  drops a cancellation without telling anyone.
- **`ShopifyFulfillmentSynchronizer` only covers Labels bought through Shopify Shipping.**
  Every fifteen minutes it asks Shopify whether those Labels were voided in the Shopify
  admin and where the parcels are. It never looks at the order, and never looks at
  direct-account Labels on Shopify orders.
- **Amazon has no equivalent.** Buy Shipping tracking comes from the four-hourly
  `packages:refresh-tracking`. Nothing checks order status or Labels cancelled on
  Amazon's side.
- **Each void records who started it, but only one page shows it.**
  `PackageLabel.void_reason` is `Operator`, `VoidedUpstream` or `Recorded`. Only View
  Package shows it.
- **Shipment status `Void` means "cancelled at the source".** A Database import sets it.
  "Void" also names what happens to a Label, which is a different thing.

## Decisions (proposed)

### 1. Status says what happened; a separate field says who reported it

`ShipmentStatus` stays at three values: `Open`, `Shipped` and `Cancelled`. `Void` is
renamed to `Cancelled`, value and label both. There are no live tenants, so the rename
needs a data migration and nothing else.

"Shipped by other software" is not a separate status. To the operator it is shipped, and
every filter, report and purge rule that treats a Shipment as finished should keep
treating it that way. What differs is who reported the change, so a new
`status_origin` records it:

| `status_origin` | Meaning |
|---|---|
| `polybag` | A Label bought here shipped it (the current behavior) |
| `source` | The originating connection reported it shipped or cancelled |
| `manager` | A manager marked it by hand |

This follows `VoidReason` on `PackageLabel`, which already separates what happened from
who reported it.

A Shipment marked shipped by its source has no Package or Label. When the source
supplies a carrier and tracking number, they go on the Shipment
(`external_carrier`, `external_tracking_number`), so someone answering a customer can
find them. PolyBag does not track these parcels.

Billing counts Labels (ADR-0009), so a Shipment shipped elsewhere is never billed, with
no extra code.

### 2. Reconcile from the import, not from a separate per-order poll

- **Shopify.** Each run already reads the complete set of open fulfillment orders. An
  open local Shipment from that connection that is missing from the set is a
  *candidate*, not a conclusion. A fulfillment order can be replaced or moved, which
  `ShopifyFulfillmentOrderRepointer` already handles. Each candidate is confirmed with
  one request for its order, then marked shipped (with the fulfillment's tracking) or
  cancelled.
- **Amazon.** Within the same `lookback_days` window, also ask for `SHIPPED` and
  `CANCELED` orders. A `SHIPPED` order closes a matching open Shipment; a `CANCELED` one
  is matched against open *and* recently shipped Shipments (below). This adds requests
  per page of orders, not per order.
- **Cancellation after PolyBag shipped.** An order can be cancelled after its Label was
  bought, and decision 4 has to catch that. So cancellation detection also covers
  Shipments shipped here, for as long as their Label could still be voided or the parcel
  stopped: until delivery, bounded by the same day window the Shopify synchronizer uses.
  For Shopify that is a separate check on those orders' cancellation, because a shipped
  order is not in the open fulfillment-order set to go missing from it.
- **Database.** When the field mapping supplies a status, apply it instead of dropping
  it (decision 4 covers Shipments that have a Package).

### 3. PolyBag recognizes its own fulfillments

When PolyBag ships an order and exports the fulfillment, the source then reports that
order as shipped too. Reconciliation must not mark it shipped by the source. A shipped
report never changes a Shipment already `Shipped` here. If its status has not settled
yet, it is matched by its Label's `shopify_fulfillment_id`, its `PackageExport` rows, or
its tracking number. A *cancelled* report on a Shipment shipped here is not ignored: it
keeps its status and is flagged (decision 4).

### 4. A Shipment the source closes may already have a Package

- **Package Draft, nothing bought.** The Shipment closes. The draft stays, so someone can
  unpack and restock it, but it is flagged and nothing can be bought for it. This answers
  `shelved-drafts/01` open question 2.
- **A Package shipped here, and the source says cancelled.** The status does not change.
  The Shipment is flagged for review, because a live Label may need voiding and the
  parcel may need stopping. This is the case that costs money, and only a person can
  settle it.

### 5. Check the order at the source just before buying a Label

Before buying a Label for a Shipment from a Shopify or Amazon connection, re-read the
order and refuse if it has been fulfilled or cancelled. This applies on the Ship page,
in batch ship and in auto-ship. It is what actually stops a double shipment;
reconciliation only shortens how long a stale Shipment sits there. It costs one request
per Label.

**Replacements are the one exception, and only when attended.** A lost or damaged parcel
is replaced by sending an already-shipped order again, which `ShipmentPolicy::reship`
already lets a manager do. The order is fulfilled at the source, so the check would
refuse it. When the source says fulfilled, the Ship page offers a replacement only to a
Manager or Admin, asks them to confirm that this is a replacement, and records that they
did.

That check is on the role itself, not `ShipmentPolicy::reship()`. The policy lets
*every* user reship a Shipment that is still `Open` here, and still open here while
fulfilled at the source is exactly the state in which this check first finds an order
fulfilled elsewhere. Going through the policy would let a shipper confirm a replacement.
A test covers that case: a stale `Open` Shipment, fulfilled at the source, and a shipper
is refused. Recognizing
PolyBag's own fulfillment (decision 3) is not enough on its own to let the purchase
through: an operator who didn't notice the order already shipped would be let through
too. A cancelled order is still refused. Batch ship and auto-ship never make a
replacement. The `reshipments` issue covers recording why a parcel was replaced.

### 6. Webhooks come later, and polling stays as a backstop

Hosted instances are publicly reachable, so Shopify can deliver webhooks straight to the
tenant, with each delivery's HMAC verified against the app's client secret. No relay is
needed for them. An on-prem install that is not publicly reachable stays on polling
until one actually needs more. Amazon's SP-API notifications go through AWS SQS or
EventBridge, not plain HTTP, so Amazon stays on polling.

Shopify does not guarantee delivery, so the poll stays as a backstop. With webhooks in
place it can run every hour or two instead of every fifteen minutes.

## Slices

Issue files will be cut from these once the open questions below are answered.

1. **Show where each void came from** on the Packages list and the Shipment view. This
   is small and also fits `release-polish`.
2. **Rename `Void` to `Cancelled`; add `status_origin` and the external carrier and
   tracking fields.** Show them on the Shipments list and the Shipment view.
3. **Check the order before purchase** (decision 5), for Shopify and Amazon.
4. **Shopify reconciliation on import** (decisions 2 and 3), including the flag on a
   draft or shipped Package (decision 4).
5. **Amazon reconciliation on import**, the same way.
6. **Database sources:** apply a reported status, flagging any Package, instead of
   dropping it.
7. **Shopify webhooks for hosted instances**, then slow the backstop poll.

## Open questions

1. **What if the source cannot be reached at purchase time?** Attended: warn and let the
   operator go ahead, or refuse? Unattended: skip the Package and report it, most likely.
2. **Partial fulfillment elsewhere.** Shopify splits the fulfillment order; Amazon reports
   `PARTIALLY_SHIPPED`. Shrink the Shipment's items to what is left, or flag it for
   someone to look at? Flagging is safer for a first version.
3. **Amazon rate limits at purchase.** The check is one order lookup per Label. Batch
   ship needs to know whether that limit or the label purchase limit is the slower one.
4. **Can a Buy Shipping Label be cancelled from Seller Central?** If it can, Amazon needs
   the same void detection the Shopify synchronizer has. Not yet checked.
5. **Should a manager be able to reopen a Shipment the source closed?** For a source that
   closed it by mistake, or an order fulfilled elsewhere that came back.

## Related

- **Choosing which orders a tenant ships through PolyBag.** For Shopify the boundary is
  fulfillment location, and ignored source locations (`DataSourceLocationResolver`)
  already keep other locations' orders out of the import. Those orders are never
  validated either, so they cost no address-validation calls. A Database source chooses
  through its query. Amazon has no natural boundary; leave an import filter until a
  customer needs one.
- `shelved-drafts/01` keeps the rest of its scope: flagging a draft whose order changed,
  an explicit hold, and stale weight.

## Comments
