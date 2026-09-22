# Decide how off-Amazon Amazon Shipping selects a connection

Status: needs-triage

Repo: `polybag`

Type: HITL — an ADR-0002 amendment.

## What to build

ADR-0002's 2026-09-22 clarification settles *what* sells Amazon Shipping for an off-Amazon
order: a connected Amazon `DataSource`, not a `CarrierAccount`. It does not settle *which*
one when a tenant has more than one, or how that choice varies by Client and Location.

`DataSource.client_id` alone is not enough — it names one Client and says nothing about
Location — and "pick the first eligible one" is the arbitrary pick the resolver is built
to rule out. Direct carrier accounts already answer the same question with
`carrier_account_scopes`, whose unique index on `(carrier_id, location_key, client_key)`
makes each precedence band hold at most one scope.

Recommended option: let a scope row target a `DataSource` instead of a `CarrierAccount`
(nullable `carrier_account_id` / `data_source_id` with a check that exactly one is set), so
`CarrierAccount::resolveForShipment()`'s precedence walk — or a sibling built on the same
table — selects the connection and inherits the uniqueness guarantee.

Things the decision has to address:

- **Which `carrier_id` the scope keys on.** The seeded `Amazon` carrier row
  (`AmazonBuyShippingAdapter::SOURCE_NAME`) is a postage-source hook, not Amazon Shipping
  as carrier of record. Either key on that row, or seed an Amazon Shipping carrier row;
  ADR-0002's carrier-of-record/postage-source split argues the scope belongs to the
  postage source, but record the choice.
- **Rate shopping.** Whether `rate_shop` means anything for a connection-targeted scope.
- **Interaction with on-Amazon orders.** An Amazon-originating Shipment keeps binding to
  its originating connection; confirm a scope never overrides that.
- **Alternatives considered** — a priority column on `data_sources`; a separate
  `data_source_scopes` table — and why they lose.

## Acceptance criteria

- [ ] ADR-0002 amended (or a new ADR) with the selection rule, the carrier row the scope
      keys on, and rejected alternatives
- [ ] `PostageSourceResolver`'s class docblock and `postage-source-split/10`'s
      clarification point at the decision
- [ ] `04` updated to match the decision

## Blocked by

None - can start immediately
