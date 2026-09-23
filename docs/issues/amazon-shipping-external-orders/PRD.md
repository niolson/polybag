# Amazon Shipping for off-Amazon orders

Status: reference

Repo: `polybag`

Amazon Buy Shipping currently sells postage only for Shipments imported from Amazon
(`channelType: AMAZON`), bound to the originating Amazon `DataSource`. Shipping v2 can also
sell Amazon Shipping for orders from other channels (`channelType: EXTERNAL`). ADR-0002's
2026-09-22 clarification says that path should select a connected Amazon `DataSource`, not
a `CarrierAccount`, because these are Shipping v2 seller credentials.

## Model

One record per external account. A `DataSource` — presented in the UI as a **Connection** —
owns credentials, OAuth state, and Client assignment, and may:

- import orders (optional; can now be switched off), and
- for Amazon, offer Amazon Shipping for orders from other channels (new opt-in).

Origin-bound postage (Shopify Shipping; Buy Shipping for Amazon orders) is not a toggle: it
follows from where the order came from, and automation is already gated by
`ServiceApproval`. Fulfillment write-back is part of order import, not a separate capability.

Rejected:

- **Separate order-source and postage-source records for one account.** Duplicates tokens
  and raises the question of which two records are the same seller account.
- **Amazon as a `CarrierAccount`.** Amazon Buy Shipping is the postage source; Amazon
  Shipping is the carrier of record (ADR-0002).
- **A general per-capability framework.** Only one capability — off-Amazon Amazon
  Shipping — can be on while import is off. Add a flag when a second one appears.
- **Renaming the `data_sources` table.** UI rename only; not worth the churn.

Deferred: removing Amazon and Shopify from `CarrierRegistry` in favor of a separate
postage-source registry. The direction is right, but this work does not need it.

## Risk: we have never seen a live account with Amazon Shipping

`01` probed the only seller account available. It can buy on-Amazon postage but never
signed up for Amazon Shipping, and never will. Production refuses every `EXTERNAL` rate
request from it with `403 A-101`. So:

- **Detection is inferred.** "A-101 on `EXTERNAL` means not set up for Amazon Shipping"
  rests on one account. No API reports whether a seller has signed up. `04` checks with a
  free `getRates` and treats any other refusal as unknown.
- **The working path is built on the sandbox.** Request rules, rates, purchase, all label
  formats, reprint, tracking and cancel were exercised there. The production body
  rules were confirmed against the same account, because Amazon validates the body
  before checking access. Still unconfirmed until a customer with Amazon Shipping turns
  it on: the carriers and services a real lane returns, real charges and adjustments,
  and production tracking and cancellation. Plan a supervised first run with that
  customer before calling the feature done.

## Issues

| # | Issue | Type | Blocked by |
|---|---|---|---|
| 01 | [Probe `EXTERNAL` rating and purchase](issues/01-probe-external-rates-and-purchase.md) | HITL — **done** | — |
| 02 | [Connections and the import toggle](issues/02-connections-and-import-toggle.md) | AFK — **done** | — |
| 03 | [Decide how a connection is selected](issues/03-decide-external-connection-routing.md) | HITL — **done** | — |
| 04 | [Offer Amazon Shipping to other channels](issues/04-offer-amazon-shipping-to-other-channels.md) | AFK — **done** | 02, 03 |
| 05 | [Quote off-Amazon Packages](issues/05-quote-amazon-shipping-for-off-amazon-packages.md) | AFK — **done** | 01, 04 |
| 06 | [Buy, track and void off-Amazon Labels](issues/06-buy-track-void-off-amazon-labels.md) | AFK | 05 |
| 07 | [Batch ship approved off-Amazon services](issues/07-batch-ship-approved-external-services.md) | AFK | 06 |
| 08 | [Connect an Amazon Shipping-only account](issues/08-connect-amazon-shipping-only-account.md) | HITL | — |
