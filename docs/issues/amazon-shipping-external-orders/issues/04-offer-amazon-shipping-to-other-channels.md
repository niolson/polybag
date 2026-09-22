# Let an Amazon connection offer Amazon Shipping to orders from other channels

Status: needs-triage

Repo: `polybag`

## What to build

An Amazon connection gets an opt-in, labeled along the lines of "Offer Amazon Shipping for
orders from other channels", plus the scope editing that ADR-0002's 2026-09-22 amendment
(`03`) decides on. With it enabled and a matching scope, `PostageSourceResolver::resolve()` returns that connection as
a postage-source candidate for a Package whose Shipment did **not** come from Amazon —
a Shopify, Database, or manually created Shipment.

This slice stops at resolution: no rates are requested yet. It is verifiable through the
resolver and the connection form.

Schema, per the amendment:

- `carrier_account_scopes.carrier_account_id` becomes nullable, and a nullable
  `data_source_id` (FK, cascade on delete) is added. Exactly one of the two is set,
  enforced in the model's `saving` hook and by a check constraint where the driver
  supports one.
- `carrier_id` on a data-source row is always the seeded `Amazon` row. The hook derives
  it, as it does for account rows. A `CarrierAccount` scope on the `Amazon` row is
  refused.
- `rate_shop` is always false on a data-source row.
- A connection with a `client_id` can only be scoped to that Client, and never globally
  or to a location alone.
- A sibling of `CarrierAccount::resolveForShipment()` walks the data-source rows on the
  same four bands, sharing the band ordering. Leave `resolveForShipment()` and its callers
  alone. Anything that iterates every scope (the `Location` relation, the carrier-account
  scope UI) must cope with a row that has no `carrierAccount`.

Rules:

- Turning the opt-in on when no global `Amazon` scope exists creates one for this
  connection. A single-account seller never has to edit a scope.
- The opt-in is independent of order import; a connection with import off (`02`) and the
  opt-in on is the expected shipping-only setup.
- An Amazon-originating Shipment still resolves to its originating connection through
  `channelSourceFor()` and never to a scoped one, even if a different connection is scoped
  to its Client. This holds even when its origin connection is inactive: it then resolves
  to nothing on this arm.
- Inactive connections, connections without the opt-in, and Packages with no matching
  scope resolve to nothing on this arm. The scope rows of an opted-out connection are kept.
- The candidate is distinguishable from origin-bound Amazon postage, so later slices know
  to send `channelType: EXTERNAL`.
- **Check whether the account can ship off-Amazon when the opt-in is switched on.** No
  API reports whether a seller has signed up for Amazon Shipping (`01`), so send a
  production `EXTERNAL` `getRates` for a valid parcel from one Location the connection
  serves (a location-scoped row's Location, or else any active Location).
  It is free and buys nothing. `200` means enabled. `403 A-101` means the account is
  not set up for Amazon Shipping: save the opt-in anyway, but warn that the seller has to
  finish Amazon Shipping sign-up in Seller Central first. Anything else means "could not
  check". The body must be valid, because Amazon validates it before checking access
  and a `400` would hide the answer. Record the result and its time on the connection.
  `05` updates it when a quote hits A-101. The resolver still returns a connection that
  is marked not enabled, so the 403 stays visible and nothing silently drops out.
  The sandbox answers `200` for any account, so the check means nothing in sandbox mode.
  A-101 is an account-level answer, so one check per connection is enough. If Amazon
  refuses a particular site, `05` will see it when quoting from that site. It is not
  enablement.

## Acceptance criteria

- [ ] Opt-in and scope configuration are editable on an Amazon connection (and wherever
      `03` puts scope editing), with validation per `03`
- [ ] Resolver returns the scoped connection for a non-Amazon Shipment at the right
      precedence (location+client, client, location, global)
- [ ] Resolver never returns a scoped connection for an Amazon-originating Shipment
- [ ] Scope validation: exactly one target, `Amazon` carrier row derived, no
      `CarrierAccount` scope on it, `rate_shop` false, client-assigned connections only
      scoped to their Client
- [ ] Enabling the opt-in with no global `Amazon` scope creates one; existing direct-account
      scope screens and `resolveForShipment()` callers are unaffected (tests)
- [ ] Resolver returns nothing for inactive, opted-out or unscoped connections
- [ ] Enabling the opt-in runs the check and records enabled / not set up / unknown,
      with a warning for A-101 (tests with a faked connector)
- [ ] Feature tests cover each rule; factory states added for the opt-in

## Blocked by

- `02` — Connections and the import toggle
- `03` — the routing decision (done: ADR-0002, 2026-09-22 amendment)
