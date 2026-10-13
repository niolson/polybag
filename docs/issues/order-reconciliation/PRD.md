# Order reconciliation: orders shipped or cancelled outside PolyBag

Status: reference

Repo: `polybag`

Opened 2026-10-12 and cut back 2026-10-13 (see Comments). Takes over the cancellation
part of `shelved-drafts/01`, and adds orders fulfilled by other software.

## Why

A tenant may ship some orders with other software, or from a warehouse PolyBag does not
run, and orders get cancelled. PolyBag does not find out about either. The order is still
`Open` here, it can still be packed, and a Label can still be bought for it. That pays
postage twice and sends the goods twice.

Most tenants will run one shipping system at a time. Two overlap mainly while someone
evaluates PolyBag beside the software they already use; otherwise an order closes
elsewhere only when it is cancelled or someone fulfills it by hand. So the goal is for
PolyBag's open Shipments to follow the channel **within one import interval**. It is not
a check at the moment of purchase: an order closed since the last import can still be
bought for, and that is accepted (decision 5).

## Current state

- **Imports fetch only open orders.** Shopify reads every fulfillment order with
  `includeClosed: false` on each run and keeps `OPEN` and `IN_PROGRESS`. Amazon reads
  `UNSHIPPED`/`PARTIALLY_SHIPPED` orders updated within the connection's `lookback_days`.
  An order fulfilled elsewhere or cancelled drops out of the feed, and nothing changes
  locally. A Shopify order moved to a location the connection ignores ends the same way.
- **Re-imports already follow the quantities of an order that is still open.** Shopify
  takes each line's `remainingQuantity` and, while the Shipment has no Package, removes a
  line with none left. Amazon takes ordered less fulfilled, but keeps a line that reaches
  zero.
- **A Database source cannot report a status at all.** Its field mapping has no status
  field; only the historical Amazon import sends one (`_import_status`). And
  `ShipmentBatchWriter::shouldUpdate()` refuses to move an open Shipment off `open` when
  it has any Package, a Package Draft included.
- **Voiding a Label on a Shopify order undoes it at Shopify.** PolyBag cancels the
  fulfillment its export made and moves the Shipment onto the fulfillment order Shopify
  replaces it with (`ShopifyFulfillmentCanceller`, `ShopifyFulfillmentOrderReplacer`). A
  Shopify Shipping Label voided in the Shopify admin is found by
  `ShopifyFulfillmentSynchronizer` and handled the same way.
- **Amazon keeps a voided Label's confirmation.** A shipment confirmation cannot be
  withdrawn, so after PolyBag voids a Label whose export confirmed an Amazon order, expect
  Amazon to go on reporting the order shipped with that Label's tracking number. Nothing
  notices a Buy Shipping Label voided in Seller Central (decision 8).
- **Built by `01` and `02` (2026-10-12).** Every void shows who started it, on Packages and
  the Shipment view. Shipment status `Void` is now `Cancelled`, and `status_origin`,
  `status_changed_at` and an external carrier and tracking number record who closed a
  Shipment and how. A cancelled order is refused on every purchase path and blocked on
  Pack. A Manager or Admin can set any status; the form says when the source closed the
  order, and the change is audit-logged.
- **Built by `06` (2026-10-13).** A Shopify import ends by looking up the open Shipments
  whose fulfillment order left the feed, 250 to a `nodes(ids:)` request, and closing
  those Shopify closed. The closing step, `SourceClosureApplier`, takes a
  `SourceOrderReport` for `07` to fill from Amazon.
- **Built by `07` (2026-10-13).** An Amazon import asks only for the orders updated since
  it last read through (`data_sources.import_updated_through`), open and closed together,
  closes the Shipments of the closed ones, and reads every open order again once a day. A
  search Amazon throttles partway carries on from its pagination token next run.
  Amazon's item import is authoritative, as Shopify's is.

## Decisions

### 1. Status says what happened; a separate field says who reported it

Built in `02`. `ShipmentStatus` has three values: `Open`, `Shipped` and `Cancelled`.
"Shipped by other software" is not a separate status. To the operator it is shipped, and
every filter, report and purge rule that treats a Shipment as finished keeps treating it
that way. `status_origin` records who reported the change:

| `status_origin` | Meaning |
|---|---|
| `polybag` | A Label bought here shipped it |
| `source` | The originating connection reported it shipped or cancelled |
| `manager` | A manager set it by hand |

A Shipment its source shipped has no Package or Label. The source's carrier and tracking
number go on the Shipment (`external_carrier`, `external_tracking_number`), so someone
answering a customer can find them. PolyBag does not track that parcel, and billing counts
Labels (ADR-0009), so it is never billed.

### 2. Sync on import, not at purchase

Each import run, once it has read the whole open feed, closes the Shipments whose orders
the source has closed. A lookup that fails leaves the Shipment for the next run. Shopify
stores nothing beyond the Shipment's own status and origin; Amazon stores how far it has
read.

- **Shopify (`06`).** A Shipment whose fulfillment order is missing from the run's open
  set, or is now at a location the connection ignores, is looked up: the fulfillment
  order's status and location, its order's cancellation, and its fulfillments' tracking
  numbers, many to a `nodes(ids:)` request.
- **Amazon (`07`).** The import's own `searchOrders` asks for every order updated since the
  connection last read through, shipped and cancelled as well as open. The open ones are
  imported and the closed ones close their Shipments, so one search does both and needs
  no lookup per Shipment. `searchOrders` refills one request every three minutes, and
  asking only what changed costs one request a run in the usual case, where re-reading
  every open order cost one per hundred.
- **Database (`08`).** A status field in the field mapping, applied.

How far behind PolyBag runs is the connection's import schedule, as often as every 5
minutes.

### 3. What closes a Shipment, and recognizing PolyBag's own parcels

| The source reports | The Shipment becomes |
|---|---|
| Cancelled | `Cancelled`, `status_origin = source` |
| Shipped, listing parcels that are all PolyBag's | No change |
| Shipped, otherwise | `Shipped`, `status_origin = source`, with the carrier and tracking number of a parcel that is not PolyBag's, if it lists one |
| A Shopify fulfillment order closed with no live fulfillment, or moved to a location the connection ignores | `Cancelled`, `status_origin = source` |
| Anything else, or no answer | No change |

A parcel is PolyBag's when its tracking number is one of the Shipment's Labels, voided ones
included. That keeps a Shipment open when the only parcel the source lists is a voided
PolyBag Label, as Amazon's will be, so PolyBag can ship it again. A parcel with no tracking
number is someone else's, because PolyBag's exports always send one.

Recognizing PolyBag's parcels by tracking number, rather than leaving alone every
Shipment that has had a voided Label, matters most during an evaluation: people buy and
void test Labels, and the blanket rule would keep those Shipments open after the other
system shipped them.

A Shopify fulfillment order closed with nothing shipped was withdrawn from this location:
refunded, merged into another, moved, or replaced where `ShopifyFulfillmentOrderRepointer`
could not pair the replacement. Whatever replaces it imports as its own Shipment.

### 4. A closed order may already have a Package Draft

The Shipment closes anyway, and the draft stays so someone can unpack and restock it.
Nothing can be bought for a cancelled order (`02`), and buying for a shipped one is a
manager's reship. Nothing is flagged: `02`'s review flag was removed on 2026-10-13. This
answers `shelved-drafts/01` open question 2.

### 5. Dropped 2026-10-13: checking the order at the source before buying

The first draft asked the source about the order before every purchase. On the Ship page
the lookup went out beside the rate quotes, and its answer was stored and reused for ten
minutes at purchase. Batch ship got one lookup per connection, and Pack a warning. An order
fulfilled or partly fulfilled elsewhere was refused, with a replacement a manager could
confirm, and an unreachable source still shipped, with a warning. Dropped because:

- **Most tenants run one system at a time.** The overlap is mostly an evaluation, and a lag
  of one import interval is acceptable there.
- **It cost a request per Label,** and Amazon's `getOrder` at 0.5 a second made it the
  slowest step of a large batch.
- **Its ten-minute reuse let through an order cancelled after the Ship page loaded,** the
  case it existed for.
- **Partial fulfillment elsewhere needed history.** Telling another system's parcels from
  PolyBag's meant storing what each line had shipped at import, and a manager
  acknowledgement to move that on. Three review rounds of `03` each found another sequence
  of voids, re-imports, replaced fulfillment orders or refunds in which the stored history
  shipped goods twice.

If a check at purchase comes back, it should ask what the source still expects (each
Shopify line's `remainingQuantity`, Amazon's ordered less fulfilled) and store nothing.

Dropped with it: flagging an order partly fulfilled elsewhere, since re-imports already
follow the remaining quantities of a Shipment not yet packed; and noticing an order
cancelled after PolyBag shipped it, which stops no shipment and can be a follow-up.

### 6. Dropped 2026-10-13: webhooks

Polling at the import interval is the design. A Shopify webhook would only shorten a lag
already accepted, and Amazon's notifications need AWS SQS or EventBridge.

### 7. A manager's status change wins

A Manager or Admin can set any status (`02`), and the form says when the source closed the
order. An import never closes a Shipment a manager reopened: an `Open` Shipment with
`status_origin = manager`. To ship an order its source closed, a manager reopens it.

The first draft let a source report newer than the manager's change undo it. A Database
row has no reliable update time to compare, and the simpler rule needs none.

### 8. Amazon Buy Shipping Labels can be voided in Seller Central

Confirmed 2026-10-12. So Amazon needs the void detection the Shopify synchronizer has: a
Label voided there must become `VoidedUpstream` here and the Package unshipped. How the
Shipping v2 API reports it (tracking, a shipment lookup, or not at all) is not yet known,
so `09` starts with a probe. It concerns PolyBag's own Labels rather than double shipping,
and is independent of the rest.

## Issues

| # | Issue | Status | Needs |
|---|---|---|---|
| [01](issues/01-show-where-each-void-came-from.md) | Show where each void came from | `done` | — |
| [02](issues/02-cancelled-status-origin-and-review-flag.md) | Rename `Void` to `Cancelled`; `status_origin`, external tracking and manager status changes (its review flag was removed 2026-10-13) | `done` | — |
| [03](issues/03-check-the-order-on-the-ship-page.md) | Check the order on the Ship page | `wontfix` | — |
| [04](issues/04-check-orders-before-batch-and-auto-ship.md) | Check orders before batch ship and auto-ship | `wontfix` | — |
| [05](issues/05-warn-on-the-pack-page.md) | Warn on the Pack page | `wontfix` | — |
| [06](issues/06-shopify-reconciliation-on-import.md) | Shopify: close the Shipments the source closed, on import | `done` | — |
| [07](issues/07-amazon-reconciliation-on-import.md) | Amazon: close the Shipments the source closed, on import | `done` | — |
| [08](issues/08-database-sources-apply-a-reported-status.md) | Database sources report and apply a status | `ready-for-agent` | — |
| [09](issues/09-detect-buy-shipping-labels-voided-in-seller-central.md) | Detect Buy Shipping Labels voided in Seller Central | `ready-for-human` | — |
| [10](issues/10-shopify-webhooks.md) | Shopify webhooks | `wontfix` | — |
| [11](issues/11-shopify-reads-only-what-changed.md) | Shopify: read only the fulfillment orders that changed | `ready-for-agent` | `06` |

`06` goes first, and `07` reuses its closing step. `08` and `09` are independent. `11` replaces `06`'s full read with a search for changes, as `07` did for Amazon.

## Answered questions

Answered 2026-10-12. The numbering is kept, because ADR-0009 cites question 4.

1. **Source unreachable at purchase:** moot since decision 5 was dropped. On import, an
   unanswered lookup leaves the Shipment for the next run.
2. **Partial fulfillment elsewhere:** not flagged (decision 5).
3. **Amazon rate limits at purchase:** moot. On import, the closed orders come in the
   import's own search, which asks only for what changed (decision 2).
4. **Buy Shipping Labels can be voided in Seller Central** (decision 8, issue `09`).
5. **A manager can change a source-closed Shipment's status,** and the change wins
   (decision 7).

## Related

- **Choosing which orders a tenant ships through PolyBag.** For Shopify the boundary is
  fulfillment location, and ignored source locations (`DataSourceLocationResolver`)
  already keep other locations' orders out of the import. Those orders are never
  validated either, so they cost no address-validation calls. A Database source chooses
  through its query. Amazon has no natural boundary; leave an import filter until a
  customer needs one.
- `shelved-drafts/01` keeps the rest of its scope: flagging a draft whose order changed,
  an explicit hold, and stale weight.
- `pii-retention/03` (import marketplace cancellations) is covered by `06` and `07`, and
  can close as superseded once they land.
- `reshipments`: a replacement for an order shipped elsewhere is a manager's reship, once
  the import has closed its Shipment.

## Comments

**2026-10-13 — cut back.** The first build of `03` ran three review rounds on branch
`order-reconciliation-03` and was not merged (decision 5 says why). The goal then narrowed
to keeping PolyBag in step with the channels within one import interval, since most
tenants run one shipping system at a time. Decisions 2, 3, 4 and 7 were rewritten and 5
and 6 dropped. `03`, `04`, `05` and `10` closed `wontfix`, `06` and `07` were rewritten,
and `08` lost its flags. `02`'s review flag was removed: the `review_reason` and
`review_flagged_at` columns, the badge, filter and Shipment view entry, *Clear Review
Flag*, and the Exceptions widget count. Keep the branch until `06` and `07` have copied
the three pieces they name from it; nothing else in it is needed.

**2026-10-13 — Amazon reads changes instead of looking orders up.** `07` was first built
as specified: a `getOrder` per open Shipment that left the feed, paced and capped at 100 a
run. In review that asked Amazon again and again about orders almost certainly unchanged,
and newest-first left a warehouse's oldest orders, the ones it ships first, never checked
once more than 100 stayed open. It was replaced before merging by decision 2's current
Amazon bullet, which costs fewer `searchOrders` requests than the import made before.
