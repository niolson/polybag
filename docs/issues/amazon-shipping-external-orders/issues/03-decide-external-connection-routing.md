# Decide how off-Amazon Amazon Shipping selects a connection

Status: done

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

- [x] ADR-0002 amended (or a new ADR) with the selection rule, the carrier row the scope
      keys on, and rejected alternatives
- [x] `PostageSourceResolver`'s class docblock and `postage-source-split/10`'s
      clarification point at the decision
- [x] `04` updated to match the decision

## Blocked by

None - can start immediately

## Comments

### 2026-09-22 — decided

ADR-0002 was amended in place (its 2026-09-22 amendment) rather than given a new ADR. The
decision fills a gap left by that ADR's own decision 9, and decision 9 now points at it.
The recommended option was taken: a `carrier_account_scopes` row may target a `DataSource`,
keyed on the seeded `Amazon` postage-source row, with `rate_shop` always false. An
Amazon-originating Shipment never reaches the scoped arm.

What decided it was testing the two tenants against it. A single-account seller needs one
global row, which turning the opt-in on creates for them. A 3PL needs a global row for its
own account and client-scoped rows for clients that pay for their own Amazon Shipping.
Location rows cover a 3PL with an account per warehouse. Shipping v2 takes `shipFrom` on
every request, and whether Amazon refuses sites it has not onboarded is unknown. If it
does, that shows up at quote time.

Added while deciding, and not in the original list:

- A connection assigned to a Client can only be scoped to that Client, so one client's
  Amazon account is never charged for another client's parcels.
- A `CarrierAccount` scope on the `Amazon` row is refused, which removes the
  resale-channel tie `postage-source-split/10` found on that row.

A 3PL's Amazon Shipping account may have no Seller Central account at all, so it is not
yet clear how it would connect. That went to `08`. It does not affect routing.
