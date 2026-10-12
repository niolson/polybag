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
- **A Database source cannot report a status at all.** Its field mapping has no status
  field; only the historical Amazon import sends one (`_import_status`). And even that is
  dropped once there is a Package: `ShipmentBatchWriter::shouldUpdate()` refuses to move
  an open Shipment off `open` when it has any Package, a Package Draft included.
  (Corrected 2026-10-12 while building `02`; the first draft said a Database source could
  report a status.)
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
- **Amazon.** A second `searchOrders` asks for `SHIPPED` and `CANCELLED` orders updated
  since the connection's last successful reconciliation (a watermark on the connection),
  not over the whole `lookback_days` window. A month of shipped orders would be many
  pages, and `searchOrders` refills one request every three minutes (burst 20, shared
  with the import itself). Since the watermark, it is normally one page. A `SHIPPED`
  order closes a matching open Shipment; a `CANCELLED` one is matched against open *and*
  recently shipped Shipments (below). The first run, with no watermark, starts from
  `lookback_days`.
- **Cancellation after PolyBag shipped.** An order can be cancelled after its Label was
  bought, and decision 4 has to catch that. So cancellation detection also covers
  Shipments shipped here, for as long as their Label could still be voided or the parcel
  stopped: until delivery, bounded by the same day window the Shopify synchronizer uses.
  For Shopify that is a separate check on those orders' cancellation, because a shipped
  order is not in the open fulfillment-order set to go missing from it.
- **Database.** Add a status field to the field mapping, and apply it instead of
  dropping it (decision 4 covers Shipments that have a Package).

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

### 5. Check the order at the source before buying a Label, without waiting on it

Before a Label is bought for a Shipment from a Shopify or Amazon connection, PolyBag
must know the order is still open at the source, and refuses if it has been fulfilled or
cancelled. This applies on the Ship page, in batch ship and in auto-ship. It is what
actually stops a double shipment; reconciliation only shortens how long a stale Shipment
sits there.

**The check must not add a wait to the purchase.** So it runs earlier, and the purchase
reuses the answer:

- **Ship page: alongside rate shopping.** The order lookup is sent with the rate quotes,
  in the same concurrent fan-out. One order lookup returns well before the slowest
  carrier quote, so it adds no time to the page. The result is stored on the Shipment
  (`source_checked_at` and what was found). At purchase, a check younger than ten
  minutes is reused, so a normal purchase sends no extra request. An older one, from a
  page left open, is repeated first.
- **Pack page: an early warning.** Opening Pack starts the same lookup without holding up
  the page (deferred, not on the request that renders it). If the order is closed, a
  banner says so before anything is packed. It warns and does not block; the Ship page
  blocks.
- **Batch ship and auto-ship: one lookup per connection, not per Label.** Amazon's
  `getOrder` is limited to 0.5 requests a second with a burst of 30, against 5 a second
  for rates and purchases, so a per-Label lookup would be the slowest step of any batch
  over 30 Amazon orders: a 100-order batch would wait about 140 seconds for it. Instead,
  before the batch:
  - **Amazon:** one `searchOrders` for `SHIPPED` and `CANCELLED` orders updated since the
    connection's last reconciliation (the watermark slice 07 keeps), normally one page.
    `searchOrders` has its own tight limit (burst 20, refilling one every three minutes,
    shared with the import), so if it is throttled the batch falls back to `getOrder`
    for as many orders as the bucket allows, and treats the rest as unreachable.
  - **Shopify:** one `nodes(ids:)` query per 250 orders.

**If the source cannot be reached, the Label is still bought, with a warning.** Shipping
must keep working while, say, AWS us-east-1 is down. Attended, the Ship page shows the
warning and the purchase goes ahead. Unattended, the Package is bought and the batch
result says which orders were not checked. Either way the Label records that its order
was not verified, so it can be found afterwards.

**Partly fulfilled elsewhere is flagged, not adjusted.** Shopify splits the fulfillment
order; Amazon reports `PARTIALLY_SHIPPED`. PolyBag does not shrink the Shipment's items.
The Shipment is flagged for review, the Ship page says which items the source reports
shipped, and unattended purchases skip it.

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

### 7. A manager can change a Shipment's status, and is told when the source closed it

A source can close an order by mistake, or an order fulfilled elsewhere can come back. A
Manager or Admin can set any status by hand, which records `status_origin = manager`.
When the Shipment was closed by its source, the form says so before saving ("Shopify
reported this order cancelled on …"), and the change is audit-logged. A shipper cannot
change status. The next reconciliation does not undo a manager's change unless the
source reports something newer than it.

### 8. Amazon Buy Shipping Labels can be voided in Seller Central

Confirmed 2026-10-12. So Amazon needs the void detection the Shopify synchronizer has:
a Label voided there must become `VoidedUpstream` here and the Package unshipped. How
the Shipping v2 API reports it (tracking, a shipment lookup, or not at all) is not yet
known, so the slice starts with a probe.

## Issues

| # | Issue | Status | Needs |
|---|---|---|---|
| [01](issues/01-show-where-each-void-came-from.md) | Show where each void came from | `done` | — |
| [02](issues/02-cancelled-status-origin-and-review-flag.md) | Rename `Void` to `Cancelled`; `status_origin`, external tracking, the review flag, and manager status changes | `done` | — |
| [03](issues/03-check-the-order-on-the-ship-page.md) | Check the order on the Ship page, alongside rate shopping | `ready-for-agent` | 02 |
| [04](issues/04-check-orders-before-batch-and-auto-ship.md) | Check orders before batch ship and auto-ship, one lookup per connection | `ready-for-agent` | 03, 07 |
| [05](issues/05-warn-on-the-pack-page.md) | Warn on the Pack page when the order is closed | `ready-for-agent` | 03 |
| [06](issues/06-shopify-reconciliation-on-import.md) | Shopify reconciliation on import | `ready-for-agent` | 02, 03 |
| [07](issues/07-amazon-reconciliation-on-import.md) | Amazon reconciliation on import | `ready-for-agent` | 02, 03 |
| [08](issues/08-database-sources-apply-a-reported-status.md) | Database sources report and apply a status | `ready-for-agent` | 02 |
| [09](issues/09-detect-buy-shipping-labels-voided-in-seller-central.md) | Detect Buy Shipping Labels voided in Seller Central | `ready-for-human` | — |
| [10](issues/10-shopify-webhooks.md) | Shopify webhooks for hosted instances, then slow the backstop poll | `needs-triage` | 06 |

`01`, `02`, `08` and `09` can start in parallel. `03` is the one that stops double
shipments, so it goes first after `02`.

## Answered questions

Answered 2026-10-12; each now lives in the decision named.

1. **Source unreachable at purchase:** ship, with a warning, attended and unattended
   (decision 5).
2. **Partial fulfillment elsewhere:** flag it (decision 5).
3. **Amazon rate limits at purchase:** `getOrder` is the bottleneck, 0.5/s with a burst
   of 30, against 5/s for rates and purchases. Hence one lookup per connection in batch
   ship, and the Ship page's check running alongside rates (decision 5).
4. **Buy Shipping Labels can be voided in Seller Central** (decision 8, issue `09`).
5. **A manager can change a source-closed Shipment's status**, with a message saying the
   source closed it (decision 7).

## Related

- **Choosing which orders a tenant ships through PolyBag.** For Shopify the boundary is
  fulfillment location, and ignored source locations (`DataSourceLocationResolver`)
  already keep other locations' orders out of the import. Those orders are never
  validated either, so they cost no address-validation calls. A Database source chooses
  through its query. Amazon has no natural boundary; leave an import filter until a
  customer needs one.
- `shelved-drafts/01` keeps the rest of its scope: flagging a draft whose order changed,
  an explicit hold, and stale weight.
- `pii-retention/03` (import marketplace cancellations) is covered by `02`, `06` and `07`
  here, and can close as superseded once they land.

## Comments
