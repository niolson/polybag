# Recover Shopify Shipping postage costs from balance transactions

Status: needs-triage

Repo: `polybag`

## Problem

Nothing in the label purchase reports a price, so `packages.cost` is null for every
Shopify Shipping label. That is deliberate — see the PRD — but it means postage spend on
these packages is invisible to PolyBag.

## The one route that exists

Verified 2026-08-31 by introspection: `ShopifyPaymentsTransactionType` includes a
**`SHIPPING_LABEL`** value, so postage charges surface as financial transactions:

```graphql
shopifyPaymentsAccount {
  balanceTransactions(first: 50) {
    nodes { id type amount { amount currencyCode } transactionDate associatedOrder { id name } }
  }
}
```

The query path is real. It is gated:

```
ACCESS_DENIED — requires `read_shopify_payments` or `read_shopify_payments_accounts`
```

## Four caveats that shape the design

1. **Shopify Payments only.** A shop billing postage another way has no such feed.
2. **New scopes**, neither currently granted. Declared scopes live in the Shopify Dev
   Dashboard, so widening them makes every connected store re-approve the app — this
   inherits that whole re-consent problem.
3. **It links to an order, not a label.** An order with a voided-then-rebought label
   produces several `SHIPPING_LABEL` transactions with nothing distinguishing them.
   Matching is heuristic — order plus timestamp — not exact.
4. **It settles later**, so cost arrives well after the package ships. This can never be
   shown to a packer at ship time.

## Shape if built

A periodic job pulling `SHIPPING_LABEL` transactions and attaching them to shipments at
**order granularity**, surfaced as a cost-reconciliation report kept *separate* from
`packages.cost`.

Do not backfill `packages.cost` from it. That column is exact for carrier-account labels
and populating it with an order-level approximation would quietly corrupt a number other
reports treat as precise. See `08`.

## Worth it when

Postage spend needs to land in client billing. Not worth it merely to compare Shopify
Shipping against your own USPS account — the Shopify admin already shows that.

## Comments

### 2026-09-08 — the price is in the order's event stream

Found while verifying `01`, not looked for. Shopify writes the label price into the
order timeline, and the timeline is readable through the Admin API as
`Order.events` — no Shopify Payments balance transaction involved:

```
2026-09-08T20:52:42Z  BasicEvent  "PolyBag purchased a shipping label for $5.68."
2026-09-08T19:08:35Z  BasicEvent  "Nick Olson voided a $5.69 shipping label."
2026-09-08T19:08:19Z  BasicEvent  "Nick Olson purchased a shipping label for $5.69."
```

So the PRD's "cost is only available via Shopify Payments balance transactions" is wrong,
and this issue has a second option that needs no payments scope and no reconciliation job.

**It is a prose string, and that is the whole problem with it.** What is on offer is a
localized, human-readable sentence with a currency glyph rather than an amount and a
currency code. Before this becomes the plan, settle:

1. **What does it read in another locale or currency?** `$` is not a currency. A shop
   billing in CAD or EUR, or an admin in another language, may render a sentence this
   parse does not recognise — and a regex that quietly matches nothing writes null cost,
   which is the state we already have.
2. **How is an event tied to a label?** The event is on the order, not the label. This
   order alone carries two purchases and a void, and `06` allows several packages per
   shipment. Timestamp proximity is the obvious correlation and is not an identity.
3. **Does the app attribution help?** The purchase we made reads `PolyBag purchased…`
   where the manual one reads a person's name. `attributeToApp` is selectable alongside
   the message and may narrow it to our own purchases.
4. **What does a failed parse do?** It must leave cost null rather than guess. Null is
   already handled honestly everywhere by `08` and `12`.

A wrong number here is worse than no number: `12` invoices this figure to a client.
