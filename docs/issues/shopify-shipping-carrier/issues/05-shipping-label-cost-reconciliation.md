# Recover Shopify Shipping postage costs

Status: needs-triage — cost recovery shipped 2026-10-03; the refund side below remains, blocked on `package-label-history/04`

Repo: `polybag`

## Problem

Nothing in the label purchase reports a price, so `packages.cost` is null for every Shopify
Shipping label. That is deliberate (see the PRD), and it means postage spend on these
packages is invisible to PolyBag — displayed as a gap by `08`, and **invoiced as zero** by
`12`. Since 2026-10-03 a USD label's price is recovered from the order timeline (below).

## Two routes, and the second is the one the PRD got wrong

**1. `Order.events`** — the label price is written into the order timeline as prose, and
the timeline is readable through the Admin API with no payments scope and no reconciliation
job:

```
2026-09-08T20:52:42Z  BasicEvent  "PolyBag purchased a shipping label for $5.68."
2026-09-08T19:08:35Z  BasicEvent  "Nick Olson voided a $5.69 shipping label."
```

Found while verifying `01`, not looked for. **It is a prose string, and that is the whole
problem with it**: a localized human sentence with a currency glyph rather than an amount
and a currency code.

**2. Shopify Payments balance transactions** — `ShopifyPaymentsTransactionType` includes
`SHIPPING_LABEL`, so postage charges surface as financial transactions. Verified by
introspection 2026-08-31, and gated on `read_shopify_payments` /
`read_shopify_payments_accounts`, neither granted.

## What the timeline actually carries — settled 2026-10-03

Read from every field of the 76 label events (41 purchases, 35 voids) on the development
store's orders, including today's GBP-priced UK purchases. Scripts and the raw dump are in
`.scratch/shopify-label-cost/`. The event is far more structured than its `message`:

| Field | Purchase | Void |
|---|---|---|
| `action` | `shipping_label_created_success` | `shipping_label_cancelled_success` |
| `arguments[0]` | the **ShippingLabel ID** | the same ID |
| `arguments[2..3]` | `api_client_id`, our app's ID — **present only on an app purchase** | absent |
| `attributeToApp` / `appTitle` | true / `PolyBag` on ours; false / null on an admin purchase | false (voids are made in the admin) |
| `additionalContent` | JSON key/value list: carrier-and-service name, `Test` badge, tracking number, items, weight and box | the same |
| `message` | `PolyBag purchased a shipping label for $5.97.` | `<staff member> voided a $5.97 shipping label.` |

The three open questions, answered:

1. **Identity, not correlation.** `arguments[0]` equals the numeric tail of the
   `gid://shopify/ShippingLabel/…` PolyBag already stores as
   `package_labels.source_label_reference` — checked for every Shopify label in the local
   database. A purchase and its void share it. Multiple purchases per order and multiple
   packages per shipment (`06`) are therefore not ambiguous at all. The tracking number in
   `additionalContent` is a second, independent check.
2. **Locale is the request's, and can be pinned.** The `message` is rendered per the
   `Accept-Language` header: `fr` gives `2,24 £ GBP`, `de-DE` reorders the sentence, `ja`
   rewrites it. The shop's settings do not enter into it. Sending `Accept-Language: en`
   makes the prose deterministic. **Currency follows Shopify's money format:** an amount in
   the shop's currency is printed bare (`$6.23`, the shop's `moneyFormat`), and one in any
   other currency carries its ISO code (`£2.24 GBP`, `moneyWithCurrencyFormat` of that
   currency). So a bare amount means the shop's `currencyCode`, and a coded amount names
   its own. **Label currency is not the shop's currency**: a USD shop's London Location
   bought in GBP, and on an order whose presentment currency was USD too.
3. **A failed parse leaves cost null.** The amount exists only in `message`. Nothing
   structured carries it: not `arguments`, not `additionalContent`. So the parse stays
   strict: pin the header, match the one English sentence per action, require a bare
   amount's glyph to match the shop's `moneyFormat`, and record nothing otherwise.

Also: `events(query: "action:shipping_label_created_success")` filters server-side, by
exact match only (a `*` wildcard returns nothing). The event is written *before* PolyBag's
label row: two seconds earlier in the one timed case. So the cost can be read **at
purchase time**, by the same `read_orders` scope the import already holds. The earlier
plan's premise (order-granularity, after settlement) does not hold for this route.

For the balance-transaction route, four caveats shape any design: **Shopify Payments only**;
**new scopes**, which live in the Dev Dashboard so widening them makes every connected store
re-approve the app; it **links to an order, not a label**, so matching is heuristic; and it
**settles later**, so cost arrives well after the package ships and can never be shown to a
packer at ship time.

## What shipped — 2026-10-03

`ShopifyLabelCostRecorder`, run hourly as `packages:sync-shopify-label-costs`. It finds
Shopify-bought labels with no cost from the last `services.shopify.label_cost_check_days`
(7), makes one request per order (`events(query: "action:shipping_label_created_success")`
with `Accept-Language: en`, plus the shop's currency and money format), and matches each
label to its event by ShippingLabel ID.

**The price goes into `package_labels.cost`, and into `packages.cost` while the label is
active.** The old rule here, never to backfill `packages.cost`, was written for an
order-level approximation. A price matched by label ID is exact, so it belongs in the
column `08` and `12` already read, and both pick it up with no change of their own. A
voided label gets its cost on the history row only: that is the refund to expect, below.

**Only USD is recorded.** Every cost column is USD by convention, and only `shipping_offers`
has a currency column. A label priced in GBP from a London Location stays null, which is
disclosed as unpriced, the same as before. Adding a `cost_currency` column was considered
and deferred: every report and invoice that sums cost would then have to handle mixed
currencies.

The bar stayed where it was: **a wrong number is worse than none.** Each of these leaves
the cost null:

- no event names the label, or two do
- the event's tracking number disagrees with the label's
- the sentence does not match the one English pattern
- a bare amount does not render exactly as the shop's `moneyFormat` would (a CAD shop's `$`
  fails here, as does any `moneyFormat` other than a plain `{{amount}}`)
- the currency is not USD

A cost landing on a package shipped before yesterday triggers `stats:aggregate` over those
dates, since the nightly rollup rebuilds only yesterday and today.

**Verified against the development store**: four USD labels were priced, matching the
timeline to the cent (two of them voided, so recorded on the label row only), and one GBP
label was left null. Test labels carry a price too, and that price is recorded; in
production a test label happens only on a development store.

**Not done:** the packer still does not see the cost at ship time. The event exists before
the purchase returns, so the purchase path could read it inline. That was left out to keep
an extra request out of the packer's wait, so the cost arrives within the hour instead.
The balance-transaction route is not needed for USD shops and stays unbuilt.

## The refund side, for every source

Absorbed from `package-label-history/06` on 2026-09-14 so there is one reconciliation
plan, not two. Carriers bill a label at purchase and refund it after a void — USPS on a
schedule of its own, UPS and FedEx on the next invoice, Shopify to the store's shipping
balance, Amazon to the seller account. Until ADR-0004's `package_labels` lands, a voided
label's cost survives only in an audit row (and only once `package-label-history/01`
ships), so there is nothing queryable to reconcile a refund against; after it, every
voided label is a row with a cost and a void time.

What to do with that row is the same question as above, from the other direction:

- Is the surface a report ("voided labels awaiting refund, by postage source, older than
  N days") or a status on the row (`refund_confirmed_at`)? A report needs no new writes;
  a status needs a source of truth for the refund, which only some carriers expose.
- Which sources expose it? `package-label-history/04` is the per-source table of what a
  void response actually contains; UPS and FedEx may carry nothing, and Shopify's
  `Order.events` is the same timeline this issue already reads.
- Client billing: `12` bills a client for postage. A voided label's cost must not reach
  an invoice, and a label voided *after* invoicing is a credit. Does the billing owner
  want that automated or flagged?

Blocked on `package-label-history/02` (the row) and `04` (what each source returns).

## What it unblocked

`12`'s charging half: Shopify labels in USD now invoice at their real cost, so what is left
to charge for is the residue: non-USD labels and anything the parse refused. Reported
there. Also `postage-source-split/17`, which needed a cost to log a blind purchase as the
`selected` quote row.

## Comments

- **2026-08-31** — balance transactions verified by introspection, and found to be gated.
- **2026-09-08** — the timeline price found while verifying `01`, correcting the PRD's claim
  that cost is reachable only through Shopify Payments.
- **2026-09-14** — `package-label-history/06` (a voided label with a cost is a refund to
  expect) folded in as the section above rather than kept as a second plan.
- **2026-10-03** — `postage-source-split/17` narrowed to recording a blind purchase as the
  selected quote, and now waits on this issue for the cost that row needs.
- **2026-10-03** — a third route checked and closed. The Shopify **admin's** own
  `ShippingLabel` carries `totalPrice`, `priceUsd`, `carrierCode` and `serviceCode` — seen in
  its GraphQL traffic for an InPost label (£4.25 / $5.61). The **public** Admin API's
  `ShippingLabel` (2026-07, introspected) has none of them: `cancellable`, `id`, `location`,
  `printed`, `shippingDocuments`, `trackingInfo` only. So the stored `source_label_reference`
  cannot be read back for a price; the two routes above stand.
- **2026-10-03** — the timeline route characterized from all 76 label events on the
  development store (body, *What the timeline actually carries*). Event-to-label is by
  ShippingLabel ID, locale is pinnable by header, currency is explicit whenever it differs
  from the shop's, and the event exists at purchase time. All three open questions closed.
- **2026-10-03** — cost recovery built on the timeline route (body, *What shipped*). The
  "never backfill `packages.cost`" rule was dropped, because a match by label ID is exact
  rather than approximate. Only USD is recorded; everything else stays null.
