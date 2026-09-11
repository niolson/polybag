# Recover Shopify Shipping postage costs

Status: needs-triage — sequenced last of the substantive work; its evidence is gathered first

Repo: `polybag`

## Problem

Nothing in the label purchase reports a price, so `packages.cost` is null for every Shopify
Shipping label. That is deliberate (see the PRD), and it means postage spend on these
packages is invisible to PolyBag — displayed as a gap by `08`, and **invoiced as zero** by
`12`.

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

## What has to be settled before either becomes the plan

For the timeline route:

1. **What does it read in another locale or currency?** `$` is not a currency. A shop
   billing in CAD or EUR, or an admin in another language, may render a sentence the parse
   does not recognise — and a regex that quietly matches nothing writes null cost, which is
   the state we already have.
2. **How is an event tied to a label?** The event is on the order, not the label. One order
   can carry several purchases and a void, and `06` allows several packages per shipment.
   Timestamp proximity is correlation, not identity. Does `attributeToApp` narrow it to our
   own purchases — ours reads `PolyBag purchased…` where a manual one reads a person's name?
3. **What does a failed parse do?** It must leave cost null rather than guess.

For the balance-transaction route, four caveats shape any design: **Shopify Payments only**;
**new scopes**, which live in the Dev Dashboard so widening them makes every connected store
re-approve the app; it **links to an order, not a label**, so matching is heuristic; and it
**settles later**, so cost arrives well after the package ships and can never be shown to a
packer at ship time.

## Shape if built

A periodic job attaching costs at **order granularity**, surfaced as a cost-reconciliation
report kept *separate* from `packages.cost`. **Do not backfill `packages.cost` from it** —
that column is exact for carrier-account labels, and populating it with an order-level
approximation would quietly corrupt a number other reports treat as precise (`08`).

**The bar: a wrong number here is worse than no number**, because `12` invoices it. A parse
that fails must leave cost null, which everything downstream already handles honestly.

## Sequencing — build last, gather first

**Build it last.** Everything else open here either costs nothing or is already wrong; this
is a new subsystem whose shape depends on answers the earlier work produces, and nothing is
blocked waiting for it.

**Gather its evidence first, because that part is free.** The questions above are
answerable from purchases other issues make anyway — pull `Order.events` after every one
rather than running a separate campaign later. `14`'s captures deliberately buy several
labels against one order and void them, which is exactly the multi-purchase case question 2
needs. A test label still carries a price, so the development store exercises the parse.

**What it unblocks:** `12`'s charging half, which is the reason this is worth building
rather than merely interesting. Report back there.

## Comments

- **2026-08-31** — balance transactions verified by introspection, and found to be gated.
- **2026-09-08** — the timeline price found while verifying `01`, correcting the PRD's claim
  that cost is reachable only through Shopify Payments.
