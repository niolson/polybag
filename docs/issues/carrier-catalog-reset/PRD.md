# Carrier catalog reset: real carriers, one catalog, sources as policy

Status: needs-triage — drafted 2026-09-24 and reviewed the same day. The review kept the
goals, corrected several facts, named the recorded decisions this work reverses, and
recommends an answer to each open question. The maintainer settled every question the
same day. A second review against the code, also the same day, changed four things. It
made Amazon Shipping a direct sale for other channels, and dated labels by carrier rather
than by connection. It let *Exclude* name a carrier, and made the method's source policy
Admin-only. It also closed a set of gaps. See its comment at the end. ADR-0006
(`docs/adr/0006-carriers-are-carriers-sources-are-policy.md`, Accepted 2026-09-24)
records the decisions. The work is sliced into `issues/01`–`15`, worked in this order: `01`–`06`,
`14`, `07`–`11`, `15`, `12`, `13`.

Repo: `polybag`

## Why this exists

Shopify Shipping (`shopify-shipping-carrier`) and Amazon Buy Shipping
(`amazon-buy-shipping`, `amazon-shipping-external-orders`) were each added by fitting a
postage source into the existing carrier catalog. Each fit made sense locally. Together
they produced a catalog where two "carriers" are not carriers, most services exist twice,
and the permission to buy discovered services lives on its own page with four dimensions
of configuration.

There are **no production tenants**. Existing rows, migrations, seeders and settings can
be replaced outright. Compatibility shims, backfills and "keep existing installs working"
constraints do not apply to this work.

This document states what the model should achieve and the rules any design must follow.
It then gives the direction, walks five cases through it, and ends with the decisions on
the open questions.

## Goals

1. **A `Carrier` is something that can physically move a parcel.** That means USPS, UPS,
   FedEx, Amazon Shipping, DHL, OnTrac, and so on. It never means Shopify, and never
   "Amazon Buy Shipping" as a channel.
2. **Each real service exists once.** USPS Ground Advantage is one `CarrierService`,
   whether it is bought on our USPS account, through Shopify, or through Amazon. The
   difference is the postage source, not the service.
3. **Postage sources are first-class, not disguised carriers.** There are four kinds of
   source: a direct `CarrierAccount`, a Shopify connection, an Amazon connection selling
   for its own orders (`channelType: AMAZON`), and an Amazon connection scoped to sell
   Amazon Shipping for other orders (`channelType: EXTERNAL`). Each source declares
   which services it can sell and whether it sells them rated or blind. The fourth sells
   one carrier through one account, priced and known before purchase, so it is treated
   as a direct sale, with the connection as its account.
4. **Configuration lives where an operator already thinks about shipping.**
   - The shipping method says what may be bought for its orders, including from which
     sources.
   - Shipping rules carry the per-client and per-condition exceptions.
   - Connections carry credentials and how far the connection sells postage.
   - There are no standalone permission pages.
5. **Every setting changes an outcome someone can observe.** A dimension that nobody
   would set differently does not exist. Examples are sandbox versus production, or
   Amazon-order versus other-order approval of a single service.
6. **The attended path stays permissive.** A packer sees and may buy anything quoted,
   including a service nobody has named, with the price shown or the blind-purchase
   warning.
7. **The unattended path stays deny-by-default, and the permission is expressed once.**
   Auto-ship, batch ship and rules buy only what configuration explicitly allows.

## Non-goals

- A direct integration with any new carrier. OnTrac and DHL appear here only as
  carriers of record reached through Amazon or Shopify.
- Shopify label cost reconciliation (`shopify-shipping-carrier/05`) and billing for
  unpriced postage (`shopify-shipping-carrier/12`).
- Changes to the Label and Package model (ADR-0004), the Offer model, or post-purchase
  dispatch by recorded postage source. Those are sound and should keep working as they do.
- Preserving current data or configuration.

## Constraints from the sources

These are facts about the APIs. Any design has to fit them, whatever the data model
looks like.

**Direct carriers (USPS, UPS, FedEx).**
- Rated per service code on our own account.
- Price, service and carrier are all known before purchase.

**Shopify Shipping.** Evidence is in `shopify-shipping-carrier/PRD.md`.
- There is no rating API, so every purchase is blind: price unknown until after, and
  service not reported afterwards.
- A purchase is keyed to a Shopify fulfillment order. Only shipments imported from that
  Shopify connection can use it.
- The request can name a preference, as a `carrier:service` pair in each carrier's own
  vocabulary (`usps:GroundAdvantage`, `ups_shipping:03`, `dhl_express:P`), or `auto` to
  let Shopify choose. The preference is advisory: Shopify may ignore it. `usps:MediaMail`
  is one of the established pairs (`shopify-shipping-carrier/02`).
- The carrier of record is known only after purchase.
- `shippingDatetime` goes out in the purchase mutation, before the carrier of record is
  known. The ship date can follow the carrier we request, but never the one Shopify
  actually picks (ADR-0002 decision 3).
- There is no void API, and labels are always PDF.
- Blind purchase is opt-in per client today (`clients.blind_purchase_enabled`).
- Automation may make a blind purchase only through an explicit shipping rule, or when
  it is the shipping method's sole eligible configured choice (ADR-0003, 2026-09-22
  amendment).

**Amazon Buy Shipping / Shipping v2.** Evidence is in `amazon-buy-shipping` and ADR-0003.
- For an Amazon order, `getRates` returns offers across many carriers. One response named
  108 services across fifteen carriers, including carriers PolyBag has no integration
  with (OnTrac, DHL).
- For an order from another channel, the only purchasable carrier is Amazon Shipping.
- The service catalog is discovered, not authored. Discovery never creates a `Carrier`
  or `CarrierService` (ADR-0003 decision 2).
- `getRates` takes no service filter.
- `getRates` carries no ship date. Amazon computes its promise from now, and the ship
  date PolyBag records is its own pickup policy's answer
  (`AmazonBuyShippingService::buildRatePayload()`). It is taken at purchase from the
  offer's carrier, so each Amazon offer can be dated by its own carrier.
- The adapter creates its `ShippingOffer` rows inside `getRates()`. Any filter that
  drops Amazon offers therefore has to run in the adapter as well, not only after the
  quote (finding from `amazon-buy-shipping/15`).
- Offers can be OTDR-protected, which matters only for Amazon orders.
- For an Amazon order, Amazon checks Media Mail and Bound Printed Matter against the
  order's products. Every captured `getRates` response lists `USPS_PTP_MM` and
  `USPS_PTP_BPM` as ineligible with "The shipping service is not available for the
  products in the order." `amazon-buy-shipping/12` put their absence down to the delivery
  promise, and the captures do not support that. `UPS_PTP_SUREPOST_MEDIA` and
  `UPS_PTP_SUREPOST_BPM` were refused only for weight or for a PO Box, so nothing shows
  whether Amazon checks products for them.

## Constraints from the app

Existing behaviour that has to survive, whatever the model.

- **Ship dates are looked up by carrier name.**
  - `ShippingRateService::shipDatesFor()` keys quoting by carrier name.
  - A rated purchase, Amazon's included, is dated by `getShipDate($rate->carrier)` in
    `ShipRequest::fromPackageAndRate()`.
  - A blind purchase is dated by `getShipDate($offer->source)`.
  - End of Day lists every active carrier, and it is where an operator ends Shopify's day.
  - Shopify's 8 PM cutoff is `pickup_cutoff_hour` on the `Shopify` carrier row (ADR-0002,
    2026-09-03 amendment).
  - An unmapped Amazon offer carries Amazon's raw carrier name. Its date therefore
    depends on whether `CarrierNormalizer` happens to resolve that name to a carrier row.
    An OnTrac offer resolves to nothing and gets no cutoff.
- **Media Mail is handled three different ways.** The USPS adapter leaves Media Mail and
  Library Mail off its mail-class list (`UspsAdapter::SHIPPER_PACKAGING_INDICATORS`). The
  Amazon adapter drops Media Mail and Bound Printed Matter
  (`CONTENT_RESTRICTED_SERVICES`, from `amazon-buy-shipping/12`). Shopify offers
  `usps:MediaMail`. ADR-0005 left mail content classes to a later decision.
- **`CarrierRegistry` is keyed by carrier name, and the carrier form lets an operator
  rename a carrier.** Renaming UPS already stops it being rated.
- **A shipping method with no active services throws** `NoActiveCarrierServicesException`.
- **Shipping rules apply on the Ship page as well as in automation.** An *Exclude* rule
  hides the rate from the packer too (`EloquentPackageShippingWorkflow::prepareRates()`).

## What is wrong today

- **Shopify is a `Carrier`.** It has 21 "Shopify's …" services that duplicate the direct
  catalog, plus an `auto` row that is not a service at all (`CarrierSeeder`).
- **`Amazon` is a `Carrier`** whose single `AMAZON_BUY_SHIPPING` row is a hook: a
  shipping method must list it before Amazon is asked. A rule that names it means "use
  the Amazon source" (`amazon-buy-shipping/19`). One catalog row is carrying three
  meanings.
- **Rating is carrier-first.** `ShippingRateService::buildCarrierTasks()` groups a
  method's services by `carrier_id` and looks up an adapter by carrier name. That lookup
  is the reason sources have to pose as carriers. The no-method fallback iterates over
  configured adapters by name in the same way.
- **Source resolution is off the rating path.** `PostageSourceResolver::resolve()` has no
  caller outside the tests. Each adapter resolves its own carrier account
  (`ResolvesCarrierAccount`) or connection (`AmazonBuyShippingService`), and `resolve()`
  itself takes carrier names as its input.
- **Fake carriers are guarded unevenly.** A carrier account on the `Amazon` row is refused
  by `CarrierAccountScope`'s saving hook, and the Carrier Account form filters `Amazon`
  out. Nothing refuses one on `Shopify`. The only check is a conflict reported by
  `resolve()`, which nothing calls. The off-Amazon scope row targets the fake `Amazon`
  carrier.
- **Approval is its own system.** `ServiceApproval` is keyed by client × environment ×
  channel type × carrier or service (with wildcards) × approve-or-except. It is edited on
  a separate page (`ServiceApprovals`) and enforced in
  `RateSelector::partitionByApproval()`. Direct-carrier services need no approval, because
  listing them on a method already authorizes them. Discovered services need a second,
  unrelated permission system.
- **A rule names a `CarrierService`, and a rule grants.** Once services are no longer
  duplicated per source, a rule naming USPS Ground Advantage no longer says whether to
  buy it directly or through Shopify. The rule form also lists the whole catalog, and
  auto-ship buys a rule's service without checking that the method lists it.

## Guidelines

Follow these whatever design is chosen. A reviewer who wants to break one should say so
explicitly.

1. **A `Carrier` row exists only for a carrier of record.** A carrier we hold no account
   with is fine as a policy-only row, for cutoffs, manifests, aliases and logos. A row
   that exists only so a source has somewhere to hang services is not.
2. **A `CarrierService` is a real carrier's service.** Anything that describes how a
   source behaves is policy on the method, the rule or the connection, not a catalog row.
   That covers Shopify `auto`, "ask Amazon", and "show unnamed Amazon offers".
3. **Source codes map to the catalog through one table, in the direction each source
   needs.** Amazon's mapping runs inward: an observed identifier names a `CarrierService`,
   and several identifiers may name the same one. Shopify's runs outward: a
   `CarrierService` names the one `carrier:service` code to request. Shopify never
   reports a service, so nothing maps back from it. `ObservedService.carrier_service_id`
   is most of the Amazon half already.
4. **Carrier of record, postage source and import source stay separate.** Their
   definitions in `CONTEXT.md` and ADR-0002 do not change. Two ADR-0002 amendments do;
   see [Decisions this reverses](#decisions-this-reverses).
5. **Rating is source-first.** Resolve the eligible sources for the package first, then
   ask each source for the services the method allows it to sell. `PostageSourceResolver`
   holds the resolution rules, but `resolve()` is not on the rating path yet and takes
   carrier names; it becomes the entry point. Never find a source by looking up a
   carrier's name.
6. **The shipping method is where the unattended allowance lives.** Rules narrow it or
   pick within it, per client or per condition. No other table grants unattended spend.
   A setting that only narrows, such as the connection's postage setting below, is not a
   grant. For rules this is a behaviour change, because today a rule can name any service.
7. **Speed is guarded by the due-by date, not by a list of services.** This was decided in
   `amazon-buy-shipping/15` (wontfix) and `17`. The method's due-by and OTDR
   requirements already refuse rates that are too slow. Revisiting that decision is out
   of scope unless the review finds it wrong. (It did not. The method's service list
   returns below as an allowance, not as a speed guard.)
8. **Environment is not a configuration dimension.** Sandbox and production share
   mappings and allowances. Offers remain bound to the environment they were quoted in,
   as they are today. This is already how direct services work, and Amazon mappings
   already span environments (`ObservedService::scopeSameService()`).
9. **Order channel is not a configuration dimension beyond what the API enforces.** An
   order from another channel can only be sold Amazon Shipping. That is the API's rule,
   and the scope row decides whether it is offered at all. The two kinds of order are
   separated by source instead: Amazon's own orders buy through Buy Shipping, and other
   orders buy Amazon Shipping directly. This replaces `amazon-shipping-external-orders/07`'s
   separate approvals without losing its separation. A rule's channel condition can
   narrow either.
10. **Prefer a clean schema to compatibility.** With no production data, replace tables,
    migrations and seeders rather than layering on top of them. Resetting a developer's
    local database still needs their go-ahead each time.
11. **Keep the existing safety invariants.** Browser state is never authority for price,
    service, source or purchase token. Offers are bound to the package version and
    source. A blind purchase is never made silently. Mapping a Shopify code to a
    `CarrierService` never makes a blind purchase's service `confirmed`. What Shopify was
    asked for stays the requested preference (ADR-0003 decision 7).
12. **A ship date follows the carrier expected to carry the parcel.** The following all
    use that carrier's cutoff and pickup days:
    - a direct label;
    - every Amazon offer, mapped or not, because Amazon names the carrier on each;
    - a Shopify label that requests a service, by the requested service's carrier.

    This is ADR-0002's own reasoning, "the policy matches the carrier we ask for and
    expect, and treats an override as the tolerated exception", applied to each
    purchase. Only Shopify `auto` has no carrier before purchase. Its connection names
    the carrier to date it by, USPS unless changed.
13. **A property of a service binds every source that sells it.** Reaching a PO Box or a
    military address is a fact about the service, and so is requiring qualifying contents,
    as Media Mail does. These live on the `CarrierService`, and no adapter keeps its own
    list of services it refuses to sell. Packaging is the exception and stays on the rate
    (ADR-0005). Several Amazon identifiers that alias to one service can each require
    different packaging, so packaging is not a property of the service.
    - Catalog special-service scoping is not one of these properties. It records what
      our direct integrations can request, so it applies to direct offers only. Amazon
      offers are judged on the value-added services each returns (ADR-0002 decision 8).
    - The one list an adapter keeps is of Amazon identifiers with a known content
      restriction and no catalog service. `UPS_PTP_SUREPOST_BPM` is dropped and
      `USPS_PTP_BPM` is attended-only (see *Media Mail*).

## Decisions this reverses

| Recorded decision | What it said | What changes |
|---|---|---|
| ADR-0002, 2026-09-22 amendment | The off-Amazon scope's `carrier_id` is the `Amazon` postage-source row, not an "Amazon Shipping" carrier row, partly so a direct `CarrierAccount` for Amazon Shipping would not look valid | Amazon Shipping is a carrier with a direct integration, sold to other channels through the scoped connection, and the scope sits on it. A direct Amazon Shipping account is valid now: it is that connection, not a `CarrierAccount`. `carrier_account_scopes.carrier_id` cascades on delete, so the scopes are moved before the `Amazon` row is dropped (`15`) |
| ADR-0002, 2026-09-03 amendment | Shopify's 8 PM cutoff is `pickup_cutoff_hour` on the `Shopify` carrier row, for every Shopify label | The row goes. A Shopify label that requests a service uses the requested carrier's cutoff and pickup days, so a UPS request no longer takes USPS's 8 PM. `auto` is dated by the carrier its connection names, USPS unless changed, whose 8 PM the old value matched (guideline 12) |
| ADR-0005, *Foreseen, not decided*: mail content classes; `amazon-buy-shipping/12` rule 3 | Media Mail and Bound Printed Matter stay dropped until a per-product declaration exists, and automation must not choose them on price alone | Media Mail is supported by direct USPS, Shopify and Amazon (question 8). The per-product declaration ADR-0005 foresaw is now in scope: every source offers Media Mail only when every item in the Package is marked as media (question 10). Amazon's `USPS_PTP_BPM` is no longer dropped, because Amazon checks products for it (question 11) |
| ADR-0003 decisions 3 and 4, and their 2026-09-24 amendments | Automation approval scoped by source, client, environment and channel type, with wildcards and exceptions | Replaced by the method's allowance, the connection's postage setting and exclude rules |
| `amazon-buy-shipping/06` | Approval is keyed on Amazon's identifier, not on `carrier_service_id`, so that aliasing two identifiers onto one service cannot let approving one vouch for both. Mapping is a Manager's job and approving an Admin's | Under open question 1's answer, mapping an identifier onto a service the method lists is what lets automation buy it. Mapping becomes an act of authorization: the mapping page moves to Admin, and a wrong mapping is a spending error, not just a naming one |
| `amazon-buy-shipping/18` | Approve everything, a whole carrier or one service, with exceptions, so the cheapest acceptable offer is bought, including services first offered later | Kept through the method's *any service* option for Amazon. Without it, a new service needs a mapping and a method listing before automation buys it. An exception becomes an exclude rule, by carrier or by service, which also hides it from the packer; the maintainer accepted that |
| `amazon-shipping-external-orders/07` | Approvals for Amazon orders and for other channels are separate | The separation is kept by source: Buy Shipping for Amazon's own orders, a direct sale of Amazon Shipping for the rest (guideline 9) |
| `amazon-shipping-external-orders/03`–`06` | Off-Amazon Amazon Shipping is a second use of the Amazon postage source, quoted by the Buy Shipping adapter | A direct sale with its own adapter (`15`). The scope, the connection as the account, the request, and purchase and dispatch through the connection all stand |
| `amazon-buy-shipping/15` (wontfix) | The service-class filter was withdrawn | Its reasons are answered under open question 1. The stashed implementation is not reused |
| `shopify-shipping-carrier/09`, and the Shopify line in `docs/issues/README.md` | Blind purchase is reachable by no automated path | Already superseded by ADR-0003's 2026-09-22 amendment and the code. Both corrected by `01` |
| `CONTEXT.md` | *Automation approval*, the relationship bullet on approval, and the example dialogue "Only after an administrator approves it for that Client and environment" | Rewritten for the method's allowance |

## Direction

### Catalog

- Remove the `Shopify` and `Amazon` carriers and their services.
- Add `Amazon Shipping` as a real carrier, **sold directly** to orders from other channels.
  It sells one carrier through one account, priced and known before purchase, and
  Shipping v2 is the only API it has. Its account is the Amazon connection its scope row
  chooses, not a `CarrierAccount` (ADR-0006 decision 1, option N).
- **Seed the carriers Shopify and Amazon sell that we do not integrate with**, with their
  services. For Amazon, seed the US subset: the carriers Amazon's help page says Buy
  Shipping sells to a US seller, which is the list in
  `ServiceApprovals::US_BUY_SHIPPING_CARRIERS` (Amazon Shipping, FedEx, OnTrac, UPS,
  USPS). The list moves beside the seed when that page is removed. Of those, only Amazon
  Shipping and OnTrac are new carriers. Add DHL Express, because Shopify sells
  `dhl_express:P`. Anything else arrives through the mapping page. Seeding is authoring,
  not discovery, so ADR-0003 decision 2 still holds.
- **What is known is one service each.** Shopify's *Preferred services* screen lists
  everything a shop can buy, and Express Worldwide is its only DHL entry. Across every
  Amazon capture, OnTrac has one identity (`ONTRAC_MFN_GROUND`), and Amazon Shipping has
  one (`std-us-swa-mfn`, sandbox only). Seed those; do not author services no source has
  been seen to sell.
- **A service code is the carrier's own API code**: DHL's `P`, Amazon's
  `std-us-swa-mfn`, and OnTrac's own code for Ground. A later direct integration then
  needs no remapping.
- **UPS Ground Saver stays two services**, under 1 lb (`92`) and 1 lb and over (`93`).
  UPS, Shopify and Amazon each sell it that way, with a code per band. Shopify's admin
  toggles *Ground Saver (<1 lb)* and *Ground Saver* separately, and Amazon has
  `UPS_PTP_SUREPOST_L` and `_H`. Two rows are UPS's own service list, not duplication,
  and each source's code maps to one of them. The rows are renamed by band; both are
  called *UPS Ground Saver* today.
- **A system carrier's name is its key, and cannot change.** Every seeded carrier is
  `is_system`: its `name` is fixed, and it can be deactivated but not deleted.
  `carriers.name` is unique. `CarrierRegistry`, the seeders, alias matching and ship
  dates find carriers by name, and with names fixed that is correct. A carrier with no
  registered direct adapter has no direct integration:
  - no carrier account can be created for the carrier;
  - rating never asks a direct adapter for it;
  - it is left out of the carrier account form.

  A shipping method lists its services like any other, and only a channel source can
  sell them.
- **Operators relabel with `display_name`**, nullable, falling back to `name`. The UI
  shows it. Everything that leaves the app or matches incoming text uses `name`. The
  seeders never write it. A carrier an operator creates is custom, and can be renamed and
  deleted.
- **The seeders find the same row every start.** `CarrierSeeder` runs on every start
  through `app:sync-reference-data`. Today it finds carriers by an editable name, so a
  renamed UPS comes back as a second row. With names fixed, finding by name is safe. A
  seeded carrier or service that is not wanted is deactivated, never deleted.
- **End of Day lists every active carrier**, with an adapter or without, because each
  one dates labels (see [Ship dates](#ship-dates)). A manifest is offered only where the
  integration supports one.
- **USPS Media Mail is an authored USPS service**, sold directly, through Shopify
  (`usps:MediaMail`) and through Amazon (`USPS_PTP_MM`). The USPS adapter adds
  `MEDIA_MAIL` to its mail-class allow-list, and the Amazon adapter stops dropping
  `USPS_PTP_MM`. Library Mail stays excluded. See [Media Mail](#media-mail).

### Source mapping

- One table maps `(source kind, external carrier id, external service id)` to a
  `CarrierService`.
- **Amazon entries** are unique on the external key, and several may name the same
  service. Identifiers already known from captured `getRates` responses are seeded as
  authored mappings. Anything seen for the first time lands on the mapping page (*Map
  Carrier Services*, `UnmappedObservedServices`) for a person to map. Until then it can
  be bought by hand, and by automation only under the method's *any service* option.
- **Shopify entries** are seeded from the 21 established `carrier:service` pairs. They are
  also unique on `(source kind, carrier_service_id)`, because a purchase sends exactly one
  code. MySQL has no partial unique index, so a generated column that is null for Amazon
  rows carries the second constraint.
- **The mapping moves off `observed_services`.** Today it is copied onto every sighting,
  which is why the recorder and the mapper serialize on `ObservedService::MAPPING_LOCK`.
  One row per mapping removes that race. Observations become a record of what was seen.
- **Amazon Shipping's direct adapter needs no mapping rows.** It reads its codes from the
  catalog, as the UPS adapter does. Buy Shipping's `AMZN_US` / `std-us-swa-mfn` maps to
  the same service, so one listing on a method covers both routes.
- **Seeded mappings are written once**, as a named batch the reference-data sync writes
  only while its marker is absent. A mapping authorizes, and a sync that wrote them on
  every start would restore one an Admin had removed. (Not by migration: migrations run
  before the sync, so a fresh install has no service rows to map yet.)
- **The table comes first, on its own** (`14`), so the Shopify rows (`09`) and the Amazon
  seed (`11`) do not wait on each other.
- **The mapping page moves to Admin**, because mapping now authorizes (see
  `amazon-buy-shipping/06` above).

### Which sources can sell a service

Decided per service, not per carrier. USPS has a direct integration, but not every USPS
service is sold directly.

- A direct account sells a service when its carrier has an adapter and the adapter
  supports the code. Amazon Shipping's adapter sells only to orders from other channels.
  An Amazon order is never sold `EXTERNAL`, because its label would lose its link to the
  order (ADR-0002).
- Shopify sells a service when a Shopify mapping names it.
- Amazon Buy Shipping serves only Amazon's own orders. It sells whatever it quotes, and a
  method's service is sellable through it when an Amazon mapping names it.

Rating tasks, the rule form and the sole-choice test for blind purchase all read this. A
configured direct USPS account therefore no longer stops Shopify being the sole choice on
a method whose only service USPS cannot sell directly.

### Media Mail

This is the per-product declaration ADR-0005 foresaw for mail content classes, brought
into scope for Media Mail.

- **Products are marked as media or not.** `products.is_media`, set in the catalog and
  importable through a `DataSource` field mapping, like `hazmat_class`. It is never
  inferred.
- **A Package qualifies only when every item does.** An item with no product never
  qualifies, and neither does a Package with no items, such as one from Manual Ship.
- **The requirement is a property of the service** (guideline 13), held as
  `carrier_services.required_contents`, a nullable enum whose one case is `media`. USPS
  Media Mail's `CarrierService` requires media contents, and every source honours it:
  - **Direct USPS:** a Media Mail rate is dropped before its Offer is issued.
  - **Shopify:** no Media Mail blind offer is made.
  - **Amazon:** a mapped Media Mail offer is dropped inside the adapter, as
    `fitsThePackaging()` does, because the adapter issues its Offers itself.
- **Amazon's own product check still runs**, so an Amazon Media Mail offer needs both
  checks to pass. The seller marks products in PolyBag even for Amazon orders.
- **Media is the only content class.** The service side is an enum, so a new class is a
  new case. The product side is one boolean per class, because each is a separate
  declaration by the seller. The candidates:
  - Bound Printed Matter is not authored (question 11).
  - Documents, for international express document products, has no use here yet.
  - Library Mail is not a class: it is Media Mail's contents plus a condition that
    sender and recipient are qualifying institutions, a fact about the shipper.
  - Contents that rule services out, such as hazmat, alcohol and lithium batteries, stay
    with the special services a product requires
    (`SpecialServiceResolver::resolveProductRequiredCodes()`). A content class is only
    the opposite: contents that make a service allowed.
- **It applies to the packer too.** A Package that does not qualify is never shown Media
  Mail. For one that qualifies, automation may choose Media Mail on price, because the
  contents have been vouched for.
- **Later, not in this work: filling the flag from Amazon's catalog.** `AmazonSource`
  already calls Catalog Items (`searchCatalogItems`) to get barcodes, asking only for
  `includedData=identifiers`. The same call could also ask for Amazon's classification of
  the product: its top-level Books, Music, and Video & DVD (BMVD) group, a product type,
  or another attribute. Which field is reliable has to be checked against the vendored
  JSON model and real responses. Two things need deciding first:
  - ADR-0005 says the flag is never inferred. Amazon's classification is the channel's
    declaration rather than a guess of ours, but it is not a declaration by the seller,
    who is liable for misdeclared Media Mail.
  - BMVD and Media Mail eligibility do not match exactly. Media Mail excludes, for
    example, video games and media carrying advertising.

  The likely shape is a default the seller can override.
- **Known limit:** Shopify `auto` cannot be constrained. If the buyer chose Media Mail at
  checkout, Shopify may buy it whatever the contents (ADR-0003 decision 5).
- **Other content classes.** Bound Printed Matter is for advertising, promotional and
  directory material such as catalogues and phone books, not ordinary books. It is not
  authored as a direct USPS service. Amazon's `USPS_PTP_BPM` is no longer dropped,
  because Amazon checks the order's products for it. It is shown to the packer and never
  bought unattended, even under *any service*. Nothing in PolyBag vouches for the
  contents, and Amazon's product classes do not match eligibility exactly: question 10
  rejected that posture for Media Mail. UPS Ground Saver Media (UPS `95`, 1 lb and over)
  is authored with Media Mail's requirement, because its last mile is USPS Media Mail.
  Amazon's `UPS_PTP_SUREPOST_MEDIA` maps to it, and PolyBag's own check guards it, so
  Amazon's check is not needed. `UPS_PTP_SUREPOST_BPM` stays dropped, and Ground Saver
  BPM (`94`) is not authored.

### Ship dates

A purchase is dated by the carrier expected to carry the parcel: its cutoff and pickup
days, and its End of Day.

- **Direct carriers:** the carrier row, as today. Amazon Shipping sold to another channel
  is one of them.
- **Amazon Buy Shipping, mapped or not:** the carrier Amazon names on the offer. It names
  one even when nobody has mapped the service, so an unmapped OnTrac offer is dated by
  OnTrac once OnTrac is seeded.
- **Shopify, requesting a service:** the requested service's carrier row. A Shopify-bought
  USPS label goes out in the same USPS pickup as a direct one, and ending USPS's day moves
  both. If Shopify overrides the request, the date may be off by a day. ADR-0002 already
  accepts that.
- **Shopify `auto`:** the one purchase whose carrier is unknown before it is made. The
  Shopify connection names a carrier to date it by, USPS unless changed. USPS is the
  carrier the integration exists for, and the old 8 PM on the `Shopify` row was USPS's.
  Ending USPS's day therefore moves `auto` labels too, and they pick up USPS's
  per-location pickup days.
- **A carrier with no row**, such as a cross-border carrier nobody has seeded, keeps
  today's fallback: no cutoff, Monday to Friday.
- The carrier is resolved when the offer is issued and stored on it (`02`, `04`). A
  purchase is no longer dated from a carrier-name string (`getShipDate($rate->carrier)`
  or `getShipDate($offer->source)`), so a rename between quote and purchase changes
  nothing.
- **End of Day lists carriers only**, including those with no direct integration, so
  OnTrac's and Amazon Shipping's day can be ended. Ending a carrier's day moves every
  label it dates, whichever source sells it.
- The seeded carriers with no direct integration (OnTrac, DHL Express) and Amazon
  Shipping get no cutoff, as UPS and FedEx have none today. An operator can set one on the
  carrier row.
- **Nothing recorded today is lost** by dating this way instead of by a connection
  default: the `Shopify` row's 20 is USPS's 20, and Amazon offers were already dated by
  their normalized carrier name. What is given up is a Shopify cutoff that differs from
  every carrier's, which the connection's setting can approximate by pointing at another
  carrier.

### Rating

- `PostageSourceResolver::resolve()` becomes the entry point. It takes the package and
  its method, and returns source instances.
- One task per source instance:
  - A direct account is asked for the method's services that its carrier sells. For
    Amazon Shipping that is the connection scoped for the order's location and client.
  - Shopify gets a blind offer for each method service it has a code for, and an `auto`
    offer when the method allows it.
  - Amazon Buy Shipping, bound to an Amazon order's origin, is asked when the method
    allows it. Its offers are all kept for the Ship page, mapped or not.
- What changes in the code:
  - Catalog special-service scoping stays with direct tasks. Shopify never reaches it,
    because it cannot guarantee a special service. Amazon's offers are judged one by one
    on the value-added services each returns.
  - Ship dates, exclusions and prepared requests are keyed by source instance, not
    carrier name.
  - Direct adapters are handed the resolved account with each call, rather than resolving
    it themselves or holding it on the cached adapter instance.
  - One account per direct carrier is quoted, as today. `resolve()` returns every account
    of a `rate_shop` scope, but the purchase path checks an offer against the first only.
  - Eighteen test files register fakes into `CarrierRegistry` by carrier name, and about
    twenty call sites look a carrier up by name (`05`).

### Shipping method

Gains a source policy next to the due-by and OTDR settings already there:

- **Which sources may sell for this method.** Direct is on by default, and covers Amazon
  Shipping sold to other channels. Shopify and Amazon Buy Shipping are opt-in.
- **Shopify:** whether `auto` is allowed.
- **Amazon Buy Shipping:** *services on this method* (the default) or *any service*.
  - With the default, automation may buy an Amazon offer whose mapped service is on the
    method. That is the same test a direct service passes.
  - With *any service*, automation may buy any Amazon offer that passes due-by and OTDR,
    including an unmapped one. This keeps `18`'s main use.

A method may list no services when its source policy gives it something to buy: Shopify
with `auto`, or Amazon Buy Shipping with *any service*. Today that throws.

**The source policy is Admin-only.** It grants unattended spend, including on services
nobody has mapped, and the mapping page moves to Admin for the same reason. A Manager
still edits the method's services, due-by and OTDR settings and rules, which pick within
what an Admin allowed. Today the whole method is Manager-editable, and approvals were
Admin-only.

**The source policy is a table of its own** (`09`, ADR-0006 option O), with one row per
source kind that may sell for the method:

| Column | Holds |
|---|---|
| `shipping_method_id` | The method |
| `source_kind` | `direct`, `shopify` or `amazon`, the `PostageSourceKind` enum rules and the mapping table also use |
| `unlisted_services` | `none` or `any`: whether the source may sell beyond the method's listed services. Shopify's `any` is `auto`, Amazon Buy Shipping's is *any service*, and direct takes only `none` |

- No row means the kind may not sell, and a new method gets a `direct` row.
- The table has its own Admin-only policy, rather than fields locked one by one on a form
  a Manager edits.
- A new channel source (eBay, Walmart, TikTok Shop) is a new kind, not a migration.
- A nullable `client_id` is where per-client allowances would go later.

### Connections

- Shopify and Amazon connections gain one postage setting: *does not sell postage*,
  *packer only*, or *packer and automation*. It only ever narrows. It governs channel
  postage: Shopify's blind purchase, and Amazon Buy Shipping for the connection's own
  Amazon orders.
- It replaces `clients.blind_purchase_enabled`. A Shopify connection belongs to one
  client and sells only blind, so its setting is that client's consent.
- Defaults:
  - Shopify: *does not sell postage*, today's opt-in default.
  - Amazon: *packer only*, which is today's behaviour when nothing is approved.
- **Amazon Shipping for other channels is a direct sale**, not channel postage. No
  postage setting applies to it. `offers_off_amazon_shipping` and the scope decide
  whether a connection sells it at all, as a carrier account's scopes do, and a method
  that lists the service buys it like UPS.
- A Shopify connection also names the carrier that dates Shopify's own choice (`auto`),
  USPS unless changed (see [Ship dates](#ship-dates)).
- Per-client default-deny survives where the money is the client's. Channel postage
  bought on a client's own account goes through a connection that belongs to that
  client. A client's own Amazon Shipping account can only be scoped to that client
  (ADR-0002). For the 3PL's own Amazon Shipping account, the scope rows decide which
  clients it sells for.

### Shipping rules

- **A rule names a source and a service.** The source is a kind, or *any priced source*.
  The service is one service, or *any*, stored as its own value and never as a null
  foreign key. Examples:
  - *Direct, USPS Ground Advantage*.
  - *Any priced source, USPS Ground Advantage*, which rate-shops the service across direct
    and Amazon.
  - *Amazon Buy Shipping, any*, which is today's `19` behaviour.
  - *Direct, Amazon Shipping Ground*, for an order from another channel.
  - *Shopify, auto*.

  A blind purchase never enters *any priced source*.
- ***Use* picks within the method's allowance.** The form lists only the method's services
  and the sources the method allows. This is a behaviour change: today a rule can name any
  service, and a rule with no method applies to every method. A shipment with no method
  has the allowance under [No shipping method](#no-shipping-method): every direct service.
  So a global *Use* rule naming a direct service still applies to it, as today.
- ***Exclude* matches a source, a carrier, a service, or any combination.** It no longer
  matches the service-code string. A carrier matches every offer it carries, mapped or
  not, so "everything except OnTrac" excludes OnTrac services Amazon first offers later.
  ADR-0006 option D relies on this. It still applies on the Ship page, so the exclusion
  hides OnTrac from the packer as well. The maintainer accepted this; no unattended-only
  form of *Exclude* is needed.
- **A carrier or service a rule names cannot be deleted.** Today a deleted service
  cascades to its rules, so an *Exclude* rule would silently vanish.

### Unattended selection

- `RateSelector` replaces `partitionByApproval()` with a check against the allowance. A
  rate passes when:
  - its source may sell for the method;
  - for Shopify and Amazon Buy Shipping, its connection allows automation;
  - its service is on the method, or, for Amazon Buy Shipping, the method allows any
    service.
- `USPS_PTP_BPM` is withheld from automation whatever the allowance says (see
  [Media Mail](#media-mail)).
- *Any service* and `auto` have no fixed list, and the sandbox setting switches every
  source to production at once. The confirmation for that switch lists the methods that
  allow either.
- This is enforced at selection, where `06` said exclusions belong, not by dropping
  offers. The allowance removes nothing from the Ship page, so it leaves no Offer behind
  and needs no filter in the adapter. The `15` stash is not reused, except
  `RateResponse::$carrierServiceId`.
- Blind purchase follows the same rule as now, generalized. A rule names it, or Shopify
  is the only eligible source for the method and yields exactly one offer. "Shopify may
  sell, `auto` allowed, no services listed" is how a method lets Shopify choose
  unattended.

### No shipping method

- Every eligible priced source is asked, unfiltered, as today. Shopify offers nothing, as
  today, because there are no services to offer it for.
- The allowance is every direct service. Automation buys direct rates as today. It never
  buys from Amazon Buy Shipping, and never blind. Today an approval covers Amazon here.
- A *Use* rule picks within that allowance, so a global rule naming a direct service
  still buys it for a shipment with no method.
- An Amazon order whose service level has no alias reaches this case only when its
  connection has no default method. `AmazonSource` falls back to the connection's
  configured method (`_shipping_method_fallback`).

### Removed

- The `service_approvals` table, the `ServiceApprovals` page, `ServiceApprovalGate` and
  `ServiceApprovalRules`.
- `clients.blind_purchase_enabled`.
- The `Shopify` and `Amazon` carrier rows, the `auto` and `AMAZON_BUY_SHIPPING` catalog
  rows, and the lookups by source name that rules use today (`blindPurchaseSourceFor()`,
  `discoveringSourceFor()`).
- The resale-channel conflict in `PostageSourceResolver::resolve()`.
- The Buy Shipping adapter's `EXTERNAL` quoting, which moves to Amazon Shipping's direct
  adapter.

## The five cases

Under this direction:

| Case | Attended | Unattended |
|---|---|---|
| **A direct order** | Direct rates for the method's services, Amazon Shipping among them when the method lists it and a connection is scoped | The cheapest acceptable direct rate under due-by, Amazon Shipping included |
| **A Shopify order bought blind** | A blind offer for each method service Shopify has a code for, plus `auto` when allowed. Shown only when the connection sells postage, and bought only after confirmation | A rule naming Shopify, or Shopify as the sole eligible source with one offer. The connection must allow automation |
| **A Shopify order sent to a direct carrier** | Direct rates beside the blind offers | The cheapest acceptable direct rate. Shopify is never a fallback. A rule names *Direct, a service* |
| **An Amazon order bought through Amazon** | Every Amazon offer, mapped or not, beside direct rates, with lateness and OTDR marked | Amazon offers whose mapped service is on the method, or any under *any service* except `USPS_PTP_BPM`, plus direct rates, filtered by due-by and OTDR. The origin connection must allow automation |
| **A non-Amazon order sold Amazon Shipping** | A direct Amazon Shipping rate from the scoped connection, when the method lists the service | The same, as any direct rate, under due-by. OTDR does not apply |

How each source constraint is handled:

- **Blind purchase:** never compared or ranked, confirmed by the packer, and bought
  unattended only by a rule or as the sole choice.
- **`auto`:** a method setting, not a catalog row.
- **Only Amazon Shipping for other channels:** the API enforces it. It is a direct sale,
  and the scope row decides which connection makes it.
- **Carriers with no integration:** seeded or mapped. Unmapped offers stay attended-only
  unless the method allows any service.
- **OTDR:** unchanged, on the method.
- **Offers created inside the adapter:** the only drops are for packaging and for Media
  Mail qualification, and both run in the adapter before an offer is issued. The
  allowance itself drops nothing.

## Configuration surfaces

| Today | Candidate |
|---|---|
| Method services, including the fake rows | Method services, real ones only |
| Method due-by and OTDR | Unchanged |
| — | Method source policy, Admin-only, one row per source kind: which sources sell, Shopify `auto`, Amazon Buy Shipping *services on this method* or *any service* |
| Rules name a service | Rules name a source and a service; *Exclude* can also name a carrier |
| *Amazon Approvals* page: client × environment × channel × carrier or service × effect | Removed |
| *Map Carrier Services*: naming, Manager | Same page, now authorizing, Admin |
| Client *blind purchase* flag | Removed, folded into the connection |
| Connection: active, import, off-Amazon opt-in | Adds the postage setting; a Shopify connection also names the carrier that dates `auto` |
| Carrier Account form | Lists only carriers with a `CarrierAccount` integration; Amazon Shipping links to Connections |
| Scope rows | Off-Amazon scopes move from the `Amazon` row to Amazon Shipping |

One page and one client flag go. One table is added beside the method, for its source
policy, shown on the method's page. Fields are added to surfaces that already exist: two
on the rule and two on the connection.

## Open questions, decided

1. **What lets automation buy an Amazon offer?** *Decided 2026-09-24:* option (a), with
   option (c) as a per-method opt-in. By default the offer's mapped service must be on the method;
   *any service* lets any offer through that passes due-by and OTDR.

   `amazon-buy-shipping/15` rejected (a). Its main reason was that OnTrac and DHL had no
   catalog rows, so they could never be listed on a method, and seeding removes that
   reason. Its other two reasons:
   - "It guarded the wrong direction": the list is an allowance, not a speed guard.
     Speed stays with the due-by date (guideline 7).
   - "It was unintuitive, and listing a service also asked the direct account": the
     `AMAZON_BUY_SHIPPING` hook row is gone, and the method's source policy decides who
     sells.

   (a) alone would lose `18`'s main use, buying whatever Amazon offers, including
   services first offered later. The *any service* option keeps it.

   The `15` stash is not the right mechanism. It drops offers before they are issued,
   which hides them from the Ship page and contradicts goal 6. The allowance is checked at
   selection instead.

   `18`'s exceptions become exclude rules, which also hide the service from the packer.
   The maintainer accepted that, so *Exclude* needs no unattended-only form.
2. **Is per-client default-deny still needed?** *Decided 2026-09-24:* yes, where the money is the client's. The
   connection's postage setting provides it without a permission table (see
   [Connections](#connections)).
3. **Does a method that only uses Shopify still need a sole mapped service?** *Decided
   2026-09-24:* no. The
   sole-choice rule is generalized: Shopify is the only eligible source and yields one
   offer. `auto` with no services listed covers "let Shopify choose".
4. **What does "no shipping method" rate against?** *Decided 2026-09-24:* every eligible priced source, as
   today. Shopify offers nothing and Amazon is never automated (see
   [No shipping method](#no-shipping-method)).
5. **Can one mapping table cover both sources?** *Decided 2026-09-24:* yes, if it runs in two directions, with
   the constraint each direction needs (see [Source mapping](#source-mapping)). One table
   is also what [which sources can sell a service](#which-sources-can-sell-a-service)
   needs in order to answer across all sources in one query.
6. **Which documents change?** *Decided 2026-09-24:*
   - ADR-0003's approval decisions (3 and 4) have been amended three times already. A new
     ADR that supersedes them, the `AMAZON_BUY_SHIPPING` hook and the two ADR-0002
     amendments in the table above is cleaner than more amendments. The same ADR can
     settle the Media Mail half of ADR-0005's *Foreseen, not decided* note on mail
     content classes.
   - `CONTEXT.md`: *Automation approval*, the approval relationship bullet, the example
     dialogue, and new entries for the method's source policy and the connection's
     postage setting.
   - `AGENTS.md`: the Domain Model entry for `ObservedService` / `ServiceApproval`, Batch
     Ship, the service layer, and Key Files.
   - `shopify-shipping-carrier/09` and `docs/issues/README.md` on blind-purchase
     automation.
7. **Which carriers without a direct integration, and which of their services, get
   seeded?** *Decided 2026-09-24:* the US subset: Amazon Shipping and OnTrac from Amazon's list, plus DHL
   Express for Shopify. Services Amazon sells under USPS, UPS or FedEx that we don't
   carry directly are authored under those carriers. Direct integration was recorded in a
   `carriers.adapter` column (see [Catalog](#catalog)). *Revised in the second review:*
   Amazon Shipping has one, reached through the scoped connection. OnTrac and DHL Express
   do not. *Revised 2026-09-25:* the column is dropped. A carrier has direct integration
   when the registry has a direct adapter under its fixed name.
8. **Does USPS Media Mail exist as a `CarrierService`?** *Decided 2026-09-24:* yes. It is
   sold by direct USPS, through Shopify and through Amazon. For an Amazon order, Amazon
   offers it only when the order's products qualify (see *Constraints from the sources*).
9. **Where does Shopify's cutoff live?** *Decided 2026-09-24:* a label that requests a
   service follows the requested carrier (guideline 12). *Revised in the second review:*
   Shopify `auto` is dated by a carrier its connection names, USPS unless changed. An
   unmapped Amazon offer is dated by the carrier Amazon names on it. There is no
   connection cutoff, and End of Day lists carriers only (see [Ship dates](#ship-dates)).
10. **What stops Media Mail being bought for contents that do not qualify?** *Decided
    2026-09-24:* products are marked as media or not, and Media Mail is used only for a
    Package whose items all qualify (see [Media Mail](#media-mail)). The method's
    service list alone was rejected as the guard. It would have let automation choose
    Media Mail on price for any order on a method that listed it.
11. **Do the other content classes follow Media Mail through Amazon?** *Decided
    2026-09-24:*
    - Stop dropping `USPS_PTP_BPM`. Every capture refuses it for the same product reason
      as Media Mail.
    - Keep dropping `UPS_PTP_SUREPOST_MEDIA` and `UPS_PTP_SUREPOST_BPM` until a capture
      shows Amazon checking products for them. So far they have been refused only for
      weight or for a PO Box. *Revised in the second review:* `UPS_PTP_SUREPOST_MEDIA`
      maps to an authored UPS Ground Saver Media carrying the media requirement.
      PolyBag's check guards it, so Amazon's is not needed. BPM stays dropped.
    - Direct USPS Bound Printed Matter and Library Mail stay out of scope. BPM is for
      catalogues, phone books and similar material, and sending ordinary books by BPM
      appears to breach USPS's terms.

## Comments

### 2026-09-24 — review

> *This was generated by AI during triage.*

Reviewed against the code and the recorded decisions. The goals stand.

- Corrected: the Shopify pair count is 21 plus `auto`, not 17 pairs or 23 rows. `resolve()`
  is not on the rating path. A carrier account on `Shopify` is not refused.
- Added:
  - *Constraints from the app*
  - *Decisions this reverses*
  - guidelines 12 and 13
  - *Which sources can sell a service*
  - *Ship dates*
  - *Connections*
  - the walkthrough of the five cases
  - the count of configuration surfaces
  - recommended answers to questions 1–7 and two new questions (8, 9)
- The candidate direction is rewritten around these.

### 2026-09-24 — maintainer decisions

> *This was generated by AI during triage.*

- Question 1: accepted. Exclude rules replace `18`'s exceptions, and hiding the service
  from the packer is fine.
- Question 8: Media Mail is supported by direct USPS, Shopify and Amazon. The Amazon
  captures back this up: `USPS_PTP_MM` is refused for the order's products, not for the
  delivery promise `12` assumed.
- Question 9: a Shopify label that requests a service follows that carrier's ship-date
  policy, and `auto` keeps 8 PM on the adapter.
- Guideline 12 and *Ship dates* are rewritten. The content-restricted flag is gone. Two
  questions are new: 10 (Media Mail on price, for direct and Shopify) and 11 (Bound
  Printed Matter and SurePost through Amazon).
- Question 10: products are marked as media, and Media Mail is offered only when every
  item in the Package qualifies. It is a property of the service, so it binds Amazon too.
  This brings ADR-0005's foreseen per-product declaration into scope; see *Media Mail*.
- Question 11: accepted. BPM stays off the direct catalog, since it is for catalogues and
  phone books, not books.
- The per-product media flag, applied to every source including Amazon, stands for now.
  Filling it from Amazon's catalog classification (BMVD) during the barcode lookup is
  recorded under *Media Mail* as later work.
- Questions 2–7: accepted as written.
- End of Day: each Shopify and Amazon connection has a default cutoff, 8 PM local, for
  unmapped services. Checking this corrected an error in the review, which had said
  Amazon's ship date goes out with `getRates`. It does not: `buildRatePayload()` omits
  it, and the purchase is dated by the offer's carrier. So a mapped Amazon offer follows
  its carrier, and an unmapped one takes the connection's default. Guideline 12 and
  *Ship dates* are rewritten to match.

### 2026-09-24 — second review, against the code

> *This was generated by AI during triage.*

The goals and the direction stand. The body, ADR-0006 and the issues are updated for
everything below, and the maintainer agreed each change.

- **Fixed before acceptance.**
  - *Exclude* could not express a per-carrier carve-out, which ADR option D relies on. It
    can now name a carrier (`07`).
  - `rateFromOffer()` rebuilt a Ship-page rate without its service or carrier, so nothing
    could date it. Offers and Labels store both (`02`, `04`).
  - Carriers without an adapter were dated by their row but left off End of Day. End of
    Day now lists every carrier (`08`).
  - The method is Manager-editable and approvals were Admin-only. The source policy is
    now Admin-only.
  - `app:sync-reference-data` runs `CarrierSeeder` on every start, finding carriers by
    name, so a renamed UPS returns as a duplicate. The seeders now find carriers by
    adapter key, and seeded mappings are written once by migration (`05`, `09`, `11`,
    `14`).
- **Amazon Shipping for other channels is a direct sale** (`15`, ADR options M and N). The
  maintainer asked whether it isn't really a direct carrier, and for other channels it
  is. It sells one carrier through one account, priced and known before purchase, and
  Shipping v2 is its only API. It was modelled as a source because its credentials live
  on a connection, which the connection keeps doing as its account. This also settles
  the four-source-kinds suggestion: the fourth kind is direct.
- **Dates follow the carrier expected to carry the parcel**, not a connection default
  (`08`, ADR option L). Asked whether this loses data: no.
  - The `Shopify` row's 20 is USPS's 20.
  - Amazon offers were already dated by their normalized carrier name, and every offer
    names a carrier.
  - What is given up is a Shopify cutoff that differs from every carrier's.
- **Smaller moves.**
  - `RateResponse::$carrierServiceId` moved to `02`.
  - The mapping table got its own issue, `14`.
  - A rule's *any service* became explicit, and its foreign keys restrict deletion.
- **Gaps closed in the issues.**
  - `05` lists about twenty name lookups, including the purchase path's account check
    and End of Day.
  - `06` keeps one account per carrier, because the purchase path refuses a second
    `rate_shop` account. It passes the account with each call, and limits
    special-service scoping to direct tasks.
  - `04` makes `USPS_PTP_BPM` attended-only.
  - `07` keeps direct *Use* rules working on shipments with no method, and makes the
    Ship page default honour the rule's source.
  - `12` checks references before deleting the `Amazon` row.
  - `13` adds the production-switch guard.
  - *No shipping method* was corrected: Amazon connections have a default method.
- **Seeding OnTrac and DHL.** The maintainer asked whether to seed every service Shopify
  and Amazon are known to sell. That is one service each: DHL Express Worldwide (`P`),
  OnTrac Ground, and Amazon Shipping Ground (`std-us-swa-mfn`, sandbox only). The OnTrac
  Sunrise and DHL eCommerce identifiers in the repo are test fixtures. `DHLMX` is left
  unmapped. Seed only what a source has been seen to sell.
- **Decided after the review: the method's source policy is a child table**, one row per
  source kind, rather than columns (`09`, ADR-0006 option O). It suits new channel
  sources, the Admin-only policy and later per-client overrides.
- **Decided after the review: UPS Ground Saver stays two services** by weight band,
  renamed apart (`11`). This closes the question of services with more than one code:
  every source splits Ground Saver the same way. The maintainer's screenshot of Shopify's
  admin shows the two toggled separately. UPS Ground Saver Media (`95`) is authored with
  the media requirement, so `UPS_PTP_SUREPOST_MEDIA` maps and leaves the drop list.
  `UPS_PTP_GROUNDSAVER` stays unmapped, because it is not tied to a weight band.
- **Decided after the review: content classes** (`02`). `carrier_services.required_contents`
  is a nullable enum whose one case is `media`, and `products.is_media` is a boolean. The
  other candidates are listed under [Media Mail](#media-mail). None is needed now.
- **Deferred, with what would reopen each.** Each is an addition to the schema later,
  with existing rows keeping their meaning. None needs anything built now.
  - **Per-client allowances** (ADR *Foreseen, not decided*). Reopen when a client needs a
    different allowance on a method it shares. The change is a nullable `client_id` on
    the source-policy table, a generated `client_key` as `carrier_account_scopes` has,
    and the unique key widened to include it. It is not in `09`.
  - **Multi-carrier resellers.** Reopen when one is planned. It would be a new source
    kind, which the enum, the source-policy table and the mapping table already allow.
    The obstacle is routing, because scopes are keyed on `carrier_id`, and this work does
    not make that worse.
  - **Mappings per Amazon marketplace** (ADR *Foreseen, not decided*). Reopen with the
    first Amazon connection outside the US. The marketplace is already recorded on the
    connection, on every Offer and on every observation. Only the mapping key lacks it,
    and a `marketplace` column defaulting to every marketplace would keep existing rows'
    meaning.

### 2026-09-25 — system carrier names are locked

> *This was generated by AI during triage.*

A third check of `05` against the code found more carrier-name lookups than the second
review had listed. `CarrierAccount::hasUsableCredentials()` matches on the name and
returns false otherwise, so a renamed UPS stops rating even with every listed lookup
moved. The maintainer chose to fix the cause instead: seeded carriers are `is_system` and
their names cannot change, `carriers.name` is unique, and an operator-owned
`display_name` carries rebrands and house style. The `carriers.adapter` key is dropped.
A carrier has direct integration when the registry has a direct adapter under its name.
[Catalog](#catalog), decision 7 above, ADR-0006 (decision 1 and option F), `05`, `11`
and `15` are updated.
