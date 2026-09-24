# Allow approved off-Amazon Amazon Shipping services in batch ship

Status: done — shipped 2026-09-24; approvals for other channels are separate from approvals for Amazon orders

Repo: `polybag`

## What to build

Batch ship and shipping rules may buy off-Amazon Amazon Shipping Labels only for services
that are explicitly approved through `ServiceApproval` for the Client and environment,
the same gate ADR-0003 applies to on-Amazon services. `amazon-buy-shipping/18` removes
the mapping prerequisite and adds carrier-wide and all-services approvals with exceptions.
Unapproved observed services stay available for attended purchase on the Ship page and
are excluded from every unattended path.

Decide and record whether an approval granted for a service on the on-Amazon path also
covers the same service on the off-Amazon path, or whether approvals are distinguished by
channel. The price and terms may differ between the two, which argues for distinguishing
them.

## Acceptance criteria

- [x] Batch ship selects an approved off-Amazon Amazon Shipping service for a non-Amazon
      Package with a scoped connection
- [x] Unapproved off-Amazon services are excluded from batch ship and shipping
      rules (test)
- [x] The approval-scope decision is recorded here and in ADR-0003 if it changes its rules

## Blocked by

- ~~`06` — purchase and dispatch~~ done
- ~~[`amazon-buy-shipping/15`](../../amazon-buy-shipping/issues/15-filter-amazon-offers-by-shipping-method-service-class.md)~~
  `wontfix`. Its job, stopping an approved service of the wrong speed from winning a
  batch, moves to
  [`amazon-buy-shipping/17`](../../amazon-buy-shipping/issues/17-move-offer-requirements-to-the-shipping-method.md):
  *exclude rates that deliver after the due-by date* on the shipping method, with the
  due-by date taken from `commitment_days` when an off-Amazon order has no `deliver_by`
- [`amazon-buy-shipping/17`](../../amazon-buy-shipping/issues/17-move-offer-requirements-to-the-shipping-method.md)
  — the speed guard above

## Decision

**Approvals are distinguished by channel type.** An approval for Amazon orders does not
cover the same service sold as Amazon Shipping for an order from another channel, and the
reverse. ADR-0003 decision 3 gains the channel type as a fourth scope axis (amended
2026-09-24).

- **The purchases differ.** Off-Amazon Amazon Shipping has its own prices and none of Buy
  Shipping's protections: no A-to-z claim cover, no OTDR protection. Approving everything for
  Amazon orders is not consent to buy on those terms for a Shopify order.
- **Splitting is the safe direction.** Until now one approval covered both channels, because
  both kinds of rate were filed under source `amazon`. Opting a connection in to other
  channels (`04`) would also have switched on unattended spending there, under approvals
  nobody gave for it. Merging later is cheap; splitting later would silently switch
  automation off for whoever depended on the shared approval.
- **Naming is not split.** Observations and mappings stay per service, whatever the
  channel. The service is the same one, under the same name.

Existing approvals became approvals for Amazon orders. No production account sells
off-Amazon yet, so nothing that was running stops.

## Comments

### 2026-09-24 — implemented

- `service_approvals.channel_type` (`amazon` / `external`, Shipping v2's `channelType`
  lowercased) is part of the unique scope and the lookup index. Existing rows default to
  `amazon`. `down()` deletes `external` rows rather than letting them widen into approvals
  for Amazon orders.
- `AmazonChannelType` enum. The adapter stamps it on every rate's `ObservedServiceIdentity`
  from the request that was sent, just as the connection is read. `RateSelector` makes
  one approvals query per source, environment and channel type in the quote.
  `ServiceApprovalGate`'s `approved`, `rulesFor`, `grant`, `grantRule`, `revoke` and `sync`
  take the channel type as a required argument, never a defaulted one, for the same
  reason the environment is required.
- *Amazon Automation Approvals* has an **Orders** select (Amazon orders / orders from other
  channels) beside Client and Environment. Both list the same observed services.
- A batch or auto-ship refusal names the channel (`… (via amazon, orders from other
  channels)`) and points at *Amazon Approvals*. It used to point at *Map Carrier Services*,
  which lost approvals in `amazon-buy-shipping/18`.
- `tests/Feature/OffAmazonShippingAutomationTest.php` runs `GenerateLabelJob` on a
  Shopify-imported Package with a scoped connection and Saloon faking Amazon. It covers:
  - an External approval buys
  - no approval does not buy
  - an Amazon-orders approval does not buy
  - an exclusion rule on the mapped service still wins over an approval
  - a rule naming the Amazon catalog row does not buy an unapproved service

**Found along the way:** a shipping rule that *uses* the Amazon catalog row cannot buy
anything, approved or not, on or off Amazon. The rule's fabricated rate carries no Offer and
no observed-service identity. `RateSelector` passes it as authored, and the purchase then
refuses it for lacking an Offer. Nothing unapproved is bought, so the criterion above holds,
but the rule is useless. Filed as
[`amazon-buy-shipping/19`](../../amazon-buy-shipping/issues/19-shipping-rule-naming-amazon-cannot-buy.md).
