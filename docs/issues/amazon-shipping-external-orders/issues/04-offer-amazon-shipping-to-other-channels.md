# Let an Amazon connection offer Amazon Shipping to orders from other channels

Status: ready-for-agent

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
  supports one. SQLite (the test suite) cannot add one to an existing table, so the
  constraint is MySQL-only and the tests exercise the hook.
- `carrier_id` on a data-source row is always the seeded `Amazon` row. The hook derives
  it, as it does for account rows. A `CarrierAccount` scope on the `Amazon` row is
  refused. The local database has none. Check both server databases before the migration
  ships.
- `rate_shop` is always false on a data-source row.
- A connection with a `client_id` can only be scoped to that Client, and never globally
  or to a location alone.
- A sibling of `CarrierAccount::resolveForShipment()` walks the data-source rows on the
  same four bands, sharing the band ordering. Leave `resolveForShipment()` and its callers
  alone. Anything that iterates every scope (the `Location` relation, the carrier-account
  scope UI) must cope with a row that has no `carrierAccount`.
- On `data_sources`: a boolean for the opt-in, plus the check result as an enum column
  (enabled / not set up / unknown) and a nullable timestamp for when it was checked.
  Use columns rather than the settings JSON so the result can be queried and cast.

Rules:

- **Turning the opt-in on creates the connection's default scope** if its slot is free:
  - a connection with no Client gets the global `Amazon` row
  - a client-assigned connection gets a row for its own Client, with no Location, because
    the Client rule forbids it a global row.

  If the slot is taken, save the opt-in and warn that the connection has no scope, as
  `CreateCarrierAccount` does. A single-account seller never has to edit a scope.
- The opt-in is independent of order import; a connection with import off (`02`) and the
  opt-in on is the expected shipping-only setup.
- An Amazon-originating Shipment still resolves to its originating connection through
  `channelSourceFor()` and never to a scoped one, even if a different connection is scoped
  to its Client. This holds even when its origin connection is inactive: it then resolves
  to nothing on this arm.
- Inactive connections, connections without the opt-in, and Packages with no matching
  scope resolve to nothing on this arm. The scope rows of an opted-out connection are kept.
- **The new arm in `resolve()` always runs**, like the channel arm, and does not depend on
  `$carrierNames`. It costs one query, and `resolve()` has no production callers yet;
  `05` connects it to quoting.
- **The candidate is distinguishable from origin-bound Amazon postage**, so later slices
  know to send `channelType: EXTERNAL`. Do not build it with
  `PostageSourceCandidate::fromDataSource()`:
  - `PostageSourceResolution::channel()` assumes at most one candidate for which
    `isChannel()` is true. A Database-imported Shipment would then get the scoped
    Amazon connection back as its "channel".
  - `isChannel()` also means a blind purchase whose carrier is unknown, and off-Amazon
    postage is neither.

  Add a named constructor for the off-Amazon candidate, a field that marks it (for example
  an `offAmazon` flag or a Shipping v2 channel-type enum), and the connection's check
  result. It keeps `kind` as `PostageDataSource` and `postageDataSourceId` set, because the
  postage is still bought through a connection. `isChannel()` returns false for it, and
  `carrier` stays null, so `forCarrier()` does not pick it up. `05` decides how its rates
  are named. The stored `PostageSource` enum does not change.
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
  A-101 is an account-level answer, so one check per connection is enough. If Amazon
  refuses a particular site, `05` will see it when quoting from that site. It is not
  enablement.
  - **Body.** Use `01`'s validated `EXTERNAL` shape: the adapter's rate-request builder
    without `amazonOrderDetails`, one package, and an item whose weight fits the package
    weight. `shipFrom` is the chosen Location's address. `shipTo` is a fixed, known-valid
    US address held as a constant next to the check. If no Location has a usable address,
    record unknown without calling Amazon.
  - **Sandbox mode.** The sandbox answers `200` for any account, and `sandbox_mode` is one
    setting shared by every carrier, so the check cannot reach production from there.
    Skip the call and record unknown, and have the warning say it was not checked in
    sandbox mode.
  - **When it runs.** Synchronously, when the opt-in is saved, with a short timeout, so
    the A-101 warning appears on that save. A timeout or connection error records unknown
    and never blocks the save. It runs again only when the opt-in goes from off to on,
    plus through an explicit "Check again" action on the connection.
- **Scope editing lives on the Amazon connection form.** Add a scopes repeater there,
  shown when the opt-in is on, modelled on the one in `CarrierAccountForm`: a Location
  and a Client per row, with no `rate_shop` field. For a client-assigned connection, the
  Client is fixed to that Client and the Location is optional. Show the check result and
  its time on the same form.
- **The Location form must ignore data-source rows.** The "Carrier Accounts" repeater in
  `LocationResource` is backed by the `carrierAccountScopes` relationship, and its
  `carrier_account_id` select is required. A location-scoped connection row would appear
  there with an empty Account field and block saving the Location. Limit that repeater
  to rows with a `carrier_account_id`, and don't let it delete rows it doesn't show.
  No Client screen edits scopes, so nothing else needs changing.

## Acceptance criteria

- [ ] Opt-in, scope rows and the check result are shown and editable on an Amazon
      connection's form, validated as described in `03`
- [ ] Resolver returns the scoped connection for a non-Amazon Shipment at the right
      precedence (location+client, location, client, global)
- [ ] Resolver never returns a scoped connection for an Amazon-originating Shipment
- [ ] The off-Amazon candidate is marked as off-Amazon and carries the check result.
      `isChannel()` is false for it, and `channel()` never returns it
- [ ] Scope validation: exactly one target, `Amazon` carrier row derived, no
      `CarrierAccount` scope on it, `rate_shop` false, client-assigned connections only
      scoped to their Client
- [ ] Enabling the opt-in creates the global row for an unassigned connection and the
      client-only row for a client-assigned one, and warns when that slot is taken
- [ ] Existing direct-account scope screens and `resolveForShipment()` callers are
      unaffected. Saving a Location that has a data-source scope row keeps the row and
      does not fail (tests)
- [ ] Resolver returns nothing for inactive, opted-out or unscoped connections
- [ ] Enabling the opt-in runs the check and records enabled / not set up / unknown,
      with a warning for A-101. In sandbox mode, or when no Location has an address, it
      records unknown without calling Amazon. A timeout records unknown and the save
      still succeeds (tests with a faked connector)
- [ ] Feature tests cover each rule; factory states added for the opt-in

## Blocked by

- `02` — Connections and the import toggle (done)
- `03` — the routing decision (done: ADR-0002, 2026-09-22 amendment)

## Comments

### 2026-09-22 — triaged

Read against the code before starting. Five questions were open, and each now has an
answer in the text above:

- **Default scope for a client-assigned connection.** The first rule said to create a
  global row, but the Client rule forbids a client-assigned connection one. It now gets a
  row for its own Client.
- **Candidate shape.** A candidate built with `fromDataSource()` would break the
  at-most-one assumption in `PostageSourceResolution::channel()`, so the off-Amazon
  candidate gets its own marker.
- **Where scopes are edited.** On the connection form. The Location repeater has to skip
  data-source rows.
- **The check.** Where the result is stored, the request body, sandbox mode, and running
  it during the save.
- **`$carrierNames`.** The new arm always runs.

The precedence in the acceptance criteria listed client before location. It now matches
the ADR and `resolveForShipment()`.
