# Let an Amazon connection offer Amazon Shipping to orders from other channels

Status: needs-triage

Repo: `polybag`

## What to build

An Amazon connection gets an opt-in, labeled along the lines of "Offer Amazon Shipping for
orders from other channels", plus the routing configuration `03` decides on. With it
enabled and a matching scope, `PostageSourceResolver::resolve()` returns that connection as
a postage-source candidate for a Package whose Shipment did **not** come from Amazon —
a Shopify, Database, or manually created Shipment.

This slice stops at resolution: no rates are requested yet. It is verifiable through the
resolver and the connection form.

Rules:

- The opt-in is independent of order import; a connection with import off (`02`) and the
  opt-in on is the expected shipping-only setup.
- An Amazon-originating Shipment still resolves to its originating connection through
  `channelSourceFor()` and never to a scoped one, even if a different connection is scoped
  to its Client.
- Inactive connections, connections without the opt-in, and Packages with no matching
  scope resolve to nothing on this arm.
- The candidate is distinguishable from origin-bound Amazon postage, so later slices know
  to send `channelType: EXTERNAL`.

## Acceptance criteria

- [ ] Opt-in and scope configuration are editable on an Amazon connection (and wherever
      `03` puts scope editing), with validation per `03`
- [ ] Resolver returns the scoped connection for a non-Amazon Shipment at the right
      precedence (location+client, client, location, global)
- [ ] Resolver never returns a scoped connection for an Amazon-originating Shipment
- [ ] Resolver returns nothing for inactive, opted-out or unscoped connections
- [ ] Feature tests cover each rule; factory states added for the opt-in

## Blocked by

- `02` — Connections and the import toggle
- `03` — the routing decision
