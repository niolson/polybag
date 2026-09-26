# ADR-0006: A carrier is a carrier; postage sources are policy on the shipping method

## Status

Accepted — 2026-09-24. Records the decisions in
`docs/issues/carrier-catalog-reset/PRD.md`. The maintainer settled every question in it
on 2026-09-24.

Revised the same day after a second review against the code. Amazon Shipping becomes a
carrier sold directly for orders from other channels, rather than a second kind of Amazon
postage source. Labels are dated by the carrier expected to carry them, not by a
connection default. *Exclude* rules can name a carrier, the source policy on a method is
an Admin-only table of its own, and seeded mappings are written once by migration. The PRD's comment of the
same date lists every change and why.

Amended 2026-09-25 (`carrier-catalog-reset/05`): the `carriers.adapter` key is dropped.
System carriers' names are locked instead, and an operator-owned `display_name` carries
what an operator would have renamed. See decision 1 and option F. Decision 4 is clarified
to match (`carrier-catalog-reset/06`).

Amended 2026-09-25 (`carrier-catalog-reset/09`): seeded mappings are written once by the
reference-data sync behind a marker, not by migration (decision 2).

Supersedes in part:

- **ADR-0002.** The 2026-09-22 amendment's choice of the `Amazon` postage-source row as the
  off-Amazon scope's `carrier_id`, and its reason: that an Amazon Shipping carrier row
  would make a direct Amazon Shipping account look valid. That account is valid now, and
  it is the scoped connection. Also the 2026-09-03 amendment's Shopify cutoff on the
  `Shopify` carrier row. The definitions of carrier of record and postage source, and
  every other decision, stand.
- **ADR-0003.** Decisions 3 and 4 on automation approval, with their 2026-09-24
  amendments. Approval as the third concept of decision 2, with that decision's
  2026-09-24 amendment: mapping onto a service the method lists is now what authorizes
  (see the trade-off). Decision 5's client opt-in, which becomes the Shopify connection's
  postage setting (decision 6). Also the seeded `AMAZON_BUY_SHIPPING` row, which is how a
  shipping method asked Amazon. The rest stands: discovery applies to Amazon only and
  never creates catalog rows, observation and normalization stay separate, Shopify is a
  blind purchase, and unmapped is a valid terminal state.
- **ADR-0005.** The Media Mail half of *Foreseen, not decided* on mail content classes,
  including its rule that automation must not choose one on price alone. Packaging stays
  on the rate, as that ADR decided.

Each of those ADRs carries a status line pointing here.

## Context

Shopify Shipping and Amazon Buy Shipping were each added by fitting a postage source into
a catalog built for direct carriers. Rating was carrier-first:
`ShippingRateService::buildCarrierTasks()` grouped a method's services by carrier, then
found an adapter by carrier name. A source could only be reached by posing as a carrier.

- **Shopify** became a `Carrier` with 21 "Shopify's …" services duplicating the direct
  catalog, plus an `auto` row that is not a service.
- **Amazon** became a `Carrier` whose single `AMAZON_BUY_SHIPPING` row did three jobs.
  A method listed it to ask Amazon. A rule named it to mean "the Amazon source"
  (`amazon-buy-shipping/19`). The off-Amazon scope row hung off it.
- **Amazon Shipping for orders from other channels** was fitted the same way, as a
  second use of the Amazon source. It sells one carrier through one account, priced and
  known before purchase, which is a direct sale. It was modelled as a channel source
  because its credentials live on an Amazon connection.

Because a discovered service could not be listed on a method, unattended purchase of one
needed its own permission system. `ServiceApproval` was keyed by client, environment,
channel type, carrier or service with wildcards, and allow or except. It was edited on a
standalone page. Direct services never needed it, because listing one on a method
already authorizes it.

Once one real service could be bought three ways, the duplicated catalog stopped being
able to say which way. A rule naming USPS Ground Advantage could not tell a direct
purchase from a Shopify one. Several things were keyed on names that no longer meant
anything:

- ship dates, by carrier name;
- the adapter registry, by an editable carrier name;
- content-restricted services, handled differently by each of three adapters.

There are no production tenants, so none of this needs a migration path.

## Decision

**1. A `Carrier` is a carrier of record.** The `Shopify` and `Amazon` rows go.

- `Amazon Shipping`, `OnTrac` and `DHL Express` are seeded as carriers, with the services
  each is known to sell: Amazon Shipping Ground, OnTrac Ground and DHL Express Worldwide.
  Seeding is authoring, not discovery (ADR-0003 decision 2).
- **A system carrier's name is fixed.** Every carrier the seeders create is marked
  `is_system`. Its `name` cannot be changed and the row cannot be deleted, only
  deactivated. So the name is a stable key for `CarrierRegistry`, the seeders, alias
  matching and ship dates, and the reference-data sync that runs on every start finds
  the same row each time. `carriers.name` is unique. A carrier an operator creates is
  custom: it can be renamed and deleted, and has no direct integration.
- **What an operator sees is `display_name`**, nullable and operator-owned, falling back
  to `name`. It covers a rebrand or house style. The UI shows it; nothing that leaves
  the app or matches incoming text uses it. A rebrand of our own is a data migration
  of `name`.

  *Amended 2026-09-25.* As first accepted, a nullable, unique `carriers.adapter` key
  (`usps`, `ups`, `fedex`, `amazon_shipping`) named the direct integration, and the
  registry and seeders were to move from names to it. Each review of the code found more
  name lookups, including one that stopped a renamed carrier's account counting as
  configured. The key also left carriers with no adapter found by name, so a renamed
  OnTrac would still have come back as a duplicate. Locking the name makes every lookup
  correct as written, and covers every seeded carrier.
- **A carrier with a registered direct adapter is sold directly.** The account for USPS, UPS and FedEx is a
  `CarrierAccount`. For Amazon Shipping it is an Amazon connection opted in to selling
  for other channels, chosen by a scope row on the Amazon Shipping carrier (ADR-0002's
  2026-09-22 amendment, on a different carrier row). The Carrier Account form sends
  Amazon Shipping to Connections.
- **A carrier with no direct adapter** (OnTrac, DHL Express, any custom carrier) is
  reached only through Shopify or Amazon Buy Shipping. No account can be created for it, and no direct adapter is asked.
- **End of Day lists every active carrier**, with or without an adapter, because every
  carrier dates labels (decision 9). A manifest is offered only where the integration
  supports one.
- A carrier's service codes are its own API codes: DHL's `P` for Express Worldwide, and
  Amazon's `std-us-swa-mfn` for Amazon Shipping Ground (from the sandbox). A later direct
  integration with OnTrac or DHL then needs no remapping.

**2. Each real service exists once, and one table maps source codes to it, in the
direction each source needs.**

- **Amazon Buy Shipping maps inward.** An observed `(carrier id, service id)` names a
  `CarrierService`, and several identifiers may name one. The table is unique on the
  external key.
- **Shopify maps outward.** A `CarrierService` names the one `carrier:service` code to
  request, so the table is also unique on `(source kind, carrier_service_id)`. Shopify
  never reports a service, so nothing maps back from it.
- Amazon Shipping's direct adapter reads its codes from the catalog, as the UPS adapter
  does, so it needs no mapping rows. Buy Shipping's `AMZN_US` / `std-us-swa-mfn` maps to
  the same service, so one listing on a method covers both routes.
- The mapping is one row, no longer copied onto every `observed_services` sighting. That
  removes the recorder and mapper race behind `ObservedService::MAPPING_LOCK`.
- **Seeded mappings are written once, by migration**, never by the reference-data sync.
  A mapping authorizes (see the trade-off), and the sync would restore one an Admin had
  removed.

  *Amended 2026-09-25* (`carrier-catalog-reset/09`). Not by migration after all: the
  entrypoint runs `migrate` before `app:sync-reference-data`, so on a fresh install a
  mapping migration finds no service rows and never runs again. The sync writes each
  seeded set as a named batch, only while that batch's marker is absent, and records the
  marker when it does. Removing a mapping still sticks.
- Mapping never makes a blind purchase's service `confirmed`. What Shopify was asked for
  stays the requested preference (ADR-0003 decision 7).

**3. Whether a source can sell a service is decided per service.**

- A direct account sells a service when its carrier's adapter supports the code. Amazon
  Shipping's adapter sells only for orders from other channels. An Amazon order is never
  sold `EXTERNAL`, because its label would lose its link to the order (ADR-0002).
- Shopify sells a service when a Shopify mapping names it.
- Amazon Buy Shipping serves only Amazon's own orders. It sells whatever it quotes, and
  sells a method's service when an Amazon mapping names it.

Rating tasks, the rule form and the sole-choice test for blind purchase all read this.

**4. Rating is source-first.** `PostageSourceResolver::resolve()` becomes the entry point.
It returns source instances for the package. It no longer takes carrier names, and
nothing finds a source by a carrier's name. Each instance is asked for the method's
services it can sell:

- A direct account is asked for the method's services its carrier sells.
- Shopify gets a blind offer per sellable method service, plus `auto` when the method
  allows it.
- Amazon Buy Shipping is asked when the method allows it, and every offer it returns is
  kept for the Ship page, mapped or not.

Catalog special-service scoping applies to direct tasks only (decision 10).

*Amended 2026-09-25* (`carrier-catalog-reset/06`). Since decision 1's amendment, a
resolved source's adapter is still found in `CarrierRegistry` by its carrier's locked
`name`. "Nothing finds a source by a carrier's name" means that no caller hands
`resolve()` a list of carrier names, and rating does not group a method's services by
carrier to decide whom to ask.

**5. The shipping method holds the unattended allowance.** Beside its due-by and OTDR
settings it gains a source policy:

- which sources may sell for it: direct on by default, Shopify and Amazon Buy Shipping
  opt-in;
- for Shopify, whether `auto` is allowed;
- for Amazon Buy Shipping, either *services on this method* or *any service*.

Automation may buy an offer when all three hold:

- its source may sell for the method;
- for Shopify and Amazon Buy Shipping, its connection allows automation (decision 6);
- its service is on the method, or, for Amazon Buy Shipping, the method allows any
  service.

An unmapped Amazon offer is automatable only under *any service*. A method may list no
services when its source policy gives it something to buy. `RateSelector` enforces this
at selection. It never works by dropping offers, so the attended list is untouched.

**The source policy is edited by an Admin only**, for the same reason the mapping page
is (see the trade-off). A Manager still edits the method's services, its due-by and OTDR
settings, and its rules. Those pick within what an Admin allowed and grant nothing.

**The source policy is its own table**, with one row per source kind that may sell for
the method: the kind, and whether it may sell beyond the method's listed services
(`unlisted_services`: `none` or `any`). Each kind declares the values it accepts.
Shopify's `any` is `auto`, Amazon Buy Shipping's is *any service*, and direct accepts
only `none`. A method is created with a `direct` row. The table has its own Admin-only
policy.

**6. A connection's postage setting is the payer's consent to channel postage.** Shopify
and Amazon connections carry one setting: *does not sell postage*, *packer only*, or
*packer and automation*.

- It governs Shopify's blind purchase and Amazon Buy Shipping for the connection's own
  orders.
- It only narrows, and grants nothing.
- It replaces `clients.blind_purchase_enabled`.
- Defaults: *does not sell postage* for Shopify, and *packer only* for Amazon.

Amazon Shipping for other channels is a direct sale. `offers_off_amazon_shipping` and the
scope decide whether a connection sells it at all, as a carrier account's scopes do. No
postage setting applies to it.

Per-client default-deny holds without a permission table. Channel postage bought on a
client's own account goes through that client's connection. A client's own Amazon
Shipping account can only be scoped to that client (ADR-0002).

**7. Shipping rules name a source and a service, and pick within the allowance.**

- The source is a kind (*direct*, *Shopify*, *Amazon Buy Shipping*) or *any priced
  source*. The service is one service or *any*. *Any* is stored explicitly, never as a
  null service.
- A blind purchase never enters *any priced source*.
- *Use* can pick only what the allowance allows, including the allowance of a shipment
  with no method (decision 12).
- *Exclude* matches a source kind, a carrier, a service, or any combination of them. A
  carrier matches every offer it carries, mapped or not, so excluding OnTrac excludes
  every OnTrac service Amazon offers, including ones first offered later.
- Both apply on the Ship page as well as in automation. An exclusion hides the service
  from the packer too.

**8. Automation approval is removed.** The `service_approvals` table, the
`ServiceApprovals` page, `ServiceApprovalGate` and `ServiceApprovalRules` go.

- **Environment is not a dimension.** Mappings and allowances apply in sandbox and
  production alike, as direct services always have. Offers stay bound to the environment
  they were quoted in. *Any service* and `auto` have no fixed list, so switching the
  shared sandbox setting to production names the methods that allow them before it takes
  effect.
- **Channel is separated by source.** Amazon's own orders buy through Buy Shipping. Orders
  from other channels buy Amazon Shipping directly. A rule's channel condition can narrow
  either.
- Blind purchase keeps ADR-0003's explicit-choice rule, generalized: a rule names it, or
  Shopify is the method's only eligible source for the package and yields exactly one
  offer.

**9. A ship date follows the carrier expected to carry the parcel.** That carrier's cutoff
and pickup days date the purchase:

- **A rate**, direct or Amazon Buy Shipping, mapped or not: the carrier the offer names.
  Amazon names a carrier on every offer, even when nobody has mapped its service. The
  carrier is resolved when the offer is issued and stored on it, so a rename between
  quote and purchase changes nothing.
- **A Shopify blind offer that requests a service:** that service's carrier.
- **Shopify `auto`:** the carrier its connection names for dating Shopify's choice, USPS
  unless changed. It is the carrier the integration exists for, and the old 8 PM matched
  it (ADR-0002, 2026-09-03 amendment).
- **A carrier that resolves to no row**, such as a cross-border carrier nobody has seeded,
  keeps today's fallback: no cutoff, Monday to Friday.

Amazon's `getRates` sends no ship date, and a purchase is dated at buy time.

End of Day lists carriers only. Ending USPS's day moves every label USPS dates, whichever
source sold it, including Shopify `auto` labels dated as USPS.

**10. A property of a service binds every source that sells it.**

- Reaching a PO Box or a military address, and requiring media contents, live on the
  `CarrierService`. No adapter keeps its own list of services it refuses to sell.
- Packaging is not a property of the service and stays on the rate (ADR-0005).
- Catalog special-service scoping is not one either. It records what our direct
  integrations can request, so it applies to direct offers only. Amazon Buy Shipping
  offers are judged on the value-added services each offer returns (ADR-0002 decision
  8). Shopify cannot guarantee a special service at all.
- **One exception.** An Amazon identifier with a known content restriction and no
  catalog service stays on a list in the adapter. `UPS_PTP_SUREPOST_BPM` is dropped.
  `USPS_PTP_BPM` is shown but never bought unattended (decision 11).

**11. Media Mail is qualified by the products in the Package.**

- It is an authored USPS service, sold directly, through Shopify and through Amazon.
- Products carry a media flag, set in the catalog or imported through a field mapping,
  like `hazmat_class`. It is never inferred.
- The service holds its requirement as a content class, of which media is the only one.
  A product declares each class it qualifies for separately. Library Mail would take
  Media Mail's contents plus a condition on the sender and recipient, so it is not a
  class. Contents that rule services out, such as hazmat and alcohol, stay with the
  special services a product requires. A content class covers only the opposite case,
  contents that make a service allowed.
- A Package qualifies only when every item does. An item with no product never
  qualifies.
- Every source drops Media Mail for a Package that does not qualify, in front of the
  packer and in automation. Amazon drops it inside the adapter, before it issues the
  Offer. Amazon's own product check runs as well.
- Amazon's `USPS_PTP_BPM` is no longer dropped, because Amazon checks the order's products
  for it. It is shown to the packer and never bought unattended, even under *any
  service*. Amazon's product classes do not match eligibility exactly, and nothing in
  PolyBag vouches for the contents.
- UPS Ground Saver Media (UPS `95`) is authored with the same requirement, because its
  last mile is USPS Media Mail. Amazon's `UPS_PTP_SUREPOST_MEDIA` maps to it. PolyBag's
  own check then guards it, as it guards direct USPS Media Mail, whether or not Amazon
  checks products for it.
- `UPS_PTP_SUREPOST_BPM` stays dropped. Bound Printed Matter is not authored, directly
  or as Ground Saver BPM (UPS `94`), and neither is Library Mail.

**12. With no shipping method,** every eligible priced source is asked, unfiltered. The
allowance is every direct service: automation buys direct rates as before, and never
buys through Amazon Buy Shipping or blind. A *Use* rule on such a shipment picks within
that. Most Amazon orders never reach this case, because an Amazon connection's default
method catches service levels that have no alias.

### Terminology

| Term | Means |
|---|---|
| **Allowance** | What a shipping method lets automation buy: its services, the sources that may sell them, Shopify `auto`, and Amazon Buy Shipping *any service*. With no method, every direct service |
| **Direct sale** | Postage bought from the carrier's own integration: a carrier account for USPS, UPS and FedEx, a scoped Amazon connection for Amazon Shipping |
| **Postage setting** | A connection's consent to sell channel postage: none, to a packer, or to automation as well. Covers Shopify and Amazon Buy Shipping, not Amazon Shipping |
| **Source mapping** | One row naming a `CarrierService` for a source's own code. Inward for Amazon Buy Shipping, outward for Shopify |
| **Unmapped service** | An offer carrying no `CarrierService`: Shopify `auto`, or an Amazon identifier nobody has mapped. It still names a carrier, except Shopify `auto` |
| **Qualifying Package** | A Package whose every item is a product marked as media |

### Foreseen, not decided

- **Filling the media flag from Amazon's catalog.** The barcode lookup's Catalog Items
  call could also return Amazon's classification, such as the Books, Music, and Video &
  DVD group. Two things are unsettled:
  - It is Amazon's declaration, not the seller's, and the seller is liable.
  - The group and Media Mail eligibility do not match exactly.

  The likely shape is a default the seller can override.
- **Per-client allowances.** Shipping methods are shared by every client, and approvals
  were per client. The connection's postage setting still gives per-client default-deny,
  and a client-scoped *Exclude* rule can narrow. But two clients who want different
  allowances on the same method need a method each. The likely shape is a nullable
  client on the source-policy table, where a client's row overrides the method's row for
  that kind.
- **Mappings per Amazon marketplace.** A source mapping applies in every marketplace. The
  marketplace is already recorded on the connection, on each Offer and on each
  observation. Keying mappings by it matters only if Amazon uses one identifier for
  different services in different marketplaces, and every connection so far is US.
  Adding it later is a `marketplace` column that defaults to every marketplace, which is
  what existing rows mean now. Revisit with the first Amazon connection outside the US.
- **Quoting every account of a `rate_shop` scope.** One direct account per carrier is
  quoted, as today. The purchase path checks an offer against the first account only, so
  quoting the others needs that check changed first.

## Options considered

**A. Keep approvals, and stop the catalog duplicating services.** Rejected. It leaves two
permission systems for one question, and a standalone page with five axes where goal 4
of the PRD wants configuration on the method.

**B. The method's service list alone governs Amazon (option (a) without *any service*).**
Rejected. It loses `amazon-buy-shipping/18`'s main use: buying the cheapest acceptable
offer, including services Amazon first offers later.

**C. Amazon on or off per method, with no service test (option (c) alone).** Rejected as
the only choice. It cannot express "buy Amazon's Ground Advantage, not its Next Day Air".
It is kept as the *any service* opt-in.

**D. Carriers Amazon may sell, per method.** Rejected. It adds a third list beside
services and sources. Per-carrier carve-outs are exclude rules, which is why *Exclude*
can name a carrier.

**E. A source list per method service, on the join table.** ADR-0003 option C called this
blocked by the schema, and the reset unblocks it. Rejected: it multiplies the method's
configuration. "Amazon's USPS but not our account's" is a rule.

**F. A "no direct integration" boolean, or deriving it from the registry by name.**
Rejected at first for the `adapter` key. A boolean says nothing about which adapter, and
the name was editable, so deriving from it broke rating when UPS was renamed.

*Amended 2026-09-25:* deriving it from the registry by name is now the decision, because
a system carrier's name is fixed (decision 1). The `adapter` key was rejected for three
reasons. It meant moving about thirty name lookups for a rename nobody needed. It left
carriers with no adapter exposed to the duplicate-row bug. And `display_name` answers the
wish to relabel a carrier without touching its key. Lost: "which carriers are sold
directly" is no longer a column you can query. The registry answers it.

**G. Shopify codes as constants on the adapter.** Rejected. Deciding which sources can
sell a service needs one query across every source.

**H. The method's service list as the only guard on Media Mail.** Rejected. On a method
that lists Media Mail beside Ground Advantage, automation would choose Media Mail on price
for any contents.

**I. Amazon's product check alone for Amazon Media Mail.** Rejected by decision 10. The
requirement is the service's, so it binds every source.

**J. An unattended-only form of *Exclude*.** Rejected. Hiding an excluded service from the
packer as well is acceptable.

**K. Shopify's cutoff as a constant on the adapter.** Rejected. A ship date follows a
carrier (decision 9), and `auto` names the carrier on its connection.

**L. A default cutoff on each connection, for every unmapped service.** The first draft of
this decision. Rejected. Only Shopify `auto` has a carrier unknown before purchase: every
Amazon offer names one. A connection cutoff, with its own row on End of Day, would also
let the USPS truck leave while Shopify's `auto` labels, most of them USPS, kept today's
date until someone ended a second day.

**M. Amazon Shipping for other channels as a kind of Amazon postage source.** The first
draft of this decision. Rejected. It sells one carrier through one account, priced and
known before purchase, so it is a direct sale. The source policy, *any service* and the
postage setting built for Buy Shipping add nothing to it. One Amazon toggle on the method
also could not tell Amazon's own orders from other channels', which
`amazon-shipping-external-orders/07` had just separated. A fourth source kind was
considered and rejected for the same reason: making it direct gives the separation with no
new setting.

**N. A `CarrierAccount` of its own for Amazon Shipping.** Deferred. It would suit a 3PL
whose Amazon Shipping account has no Seller Central (`amazon-shipping-external-orders/08`).
But a seller with one Seller Central account would authorize twice, and the guard that
stops a client's own account being scoped to another client relies on the connection's
`client_id`. Revisit when `08` is answered.

**O. The source policy as columns on `shipping_methods`.** Rejected for the child table in
decision 5. As columns, *Shopify may sell*, `auto`, *Amazon may sell* and *any service*
are four fields, each named for its source. A fifth source (eBay, Walmart and TikTok Shop
all sell priced postage tied to their own orders, as Amazon does) would be a migration,
new form fields and a change to the selection check. As rows, it is a new source kind.
The columns would also sit on a form a Manager edits, held Admin-only field by field,
and a per-client override would have nowhere to go.

## Trade-off

The cost is that **mapping becomes authorization**. Under ADR-0003, what a service was
called and whether automation could buy it were separate. Here, mapping an Amazon
identifier onto a service the method lists is what lets automation buy it. So:

- A wrong mapping is a spending error, not just a naming one.
- The mapping page moves from Manager to Admin, and so does the method's source policy.
- Seeded mappings are written once, behind a marker, so removing one sticks.
- `amazon-buy-shipping/06` avoided keying on `carrier_service_id` for exactly this
  reason: aliasing two identifiers onto one service vouches for both. That now holds on
  purpose, because the method's service is what the seller chose to allow.

Two smaller losses are accepted:

- **Allowances are per method, not per client.** See *Foreseen, not decided*.
- **An exclusion is visible to the packer.** It removes the service from the Ship page as
  well as from automation.

## Consequences

Easier:

- One catalog, where a service is a service. A rule, a report or an export names USPS
  Ground Advantage once.
- One permission, on the method, whatever the source.
- Amazon Shipping is bought for a Shopify order the way UPS is: list the service on the
  method.
- Ship dates follow the pickup the parcel actually makes, including Shopify-bought and
  Amazon-bought labels, and End of Day ends one day per carrier.
- Media Mail is available from every source, and only for contents that qualify.
- A carrier rename can no longer break rating, because a system carrier cannot be
  renamed. An operator relabels it with `display_name`.

Harder:

- Rating, rules, ship dates, End of Day, the scope row and the seeded catalog all change
  together.
- A carrier rebrand on our side is a data migration of `name`, not a seeder edit.
- The mapping page and the method's source policy carry authority they did not have
  before.
- Sellers must mark products as media before Media Mail appears, including for Amazon
  orders, until the catalog default in *Foreseen, not decided* exists.

## Implementation

Tracked as issues under `docs/issues/carrier-catalog-reset/`. This document records the
decisions and why. It is not a checklist.
