# ADR-0003: Discover the Amazon service catalog; treat Shopify as a blind purchase

## Status

Accepted — 2026-09-02; amended 2026-09-22 to permit explicitly configured blind-purchase automation. Depends on ADR-0002.

Drafted 2026-09-01 and revised twice. The first draft modeled Shopify as a discovery source
alongside Amazon, on the assumption that the purchased service becomes knowable after the fact.
It does not. That assumption is removed here and Shopify is carved out as a different kind of
thing entirely.

Accepted once the premise underneath it was measured rather than argued. A production
`getRates` on a real Amazon order returned **six eligible offers across three carriers** —
OnTrac, UPS and USPS, priced independently — alongside a 102-entry ineligible catalog spanning
fourteen carriers. OnTrac was both eligible and cheapest, and we hold no `Carrier` row for it,
no account with it, and no adapter: the case this ADR was designed around, observed rather than
hypothesised.

## Context

`CarrierService` is authored configuration today. `CarrierSeeder` holds hand-written rows,
`app:sync-reference-data` "syncs" by reseeding from source code, and
`CacheService::getActiveCarrierServices()` caches for an hour. Nothing ever learns a service
from a carrier. That works because USPS, UPS and FedEx publish static service lists that change
roughly never.

ADR-0002 introduced postage sources, which do not have that property — and, importantly, do not
all fail the same way:

| Source | What it can tell us |
|---|---|
| Direct (USPS/UPS/FedEx) | static published service list, safely hand-seeded |
| Amazon Buy Shipping | **dynamic, per-shipment.** No list-services operation exists anywhere in the Shipping v2 model. `getRates` returns eligible rates with carrier, service, price and promise; `ineligibleRates[]` names services that exist but did not apply. Its `code` is `UNKNOWN` on every entry — the usable information is carrier/service **identity**, not the reason. The sandbox is not merely stale but structurally unrepresentative: it returned only Amazon Shipping where production returned none at all |
| Shopify Shipping | **carrier only, after the fact.** The `ShippingLabel` object exposes `id`, `location`, `printed`, `cancellable`, `shippingDocuments` and `trackingInfo`. There is no purchased service, no service code, no rate and no price — before or after purchase |

Two consequences follow from the Shopify row, and they are what this ADR turns on.

**Shopify can never tell us what service was bought.** Only the carrier, via
`trackingInfo.company`. The current implementation writes that value into `packages.service`
(`ShopifyAdapter::createShipment()`), which is a carrier name sitting in the service column —
a bug this ADR should not build on top of.

Blind purchase looks worth building, on a **dated observation rather than a confirmed result**.
On 2026-09-01 the Shopify admin rate calculator quoted USPS Connect eCommerce pricing — $5.17
for a zone 1 Ground Advantage parcel under 4 oz, matching Veeqo and Pirate Ship, against $6.93
list commercial for a USPS account with no NSA. That is roughly a quarter off the dominant
service, which would justify an option that reports nothing back.

It is a quoted calculator figure, not a completed purchase: the Shopify account is not yet
verified, and Shopify exposes no price on a bought label, so the saving cannot be confirmed from
a label either. Treat it as the **go/no-go hypothesis** for this path. Until a real purchase
corroborates it, the existing Shopify path stays behind explicit opt-in. Note also that the
configuration lives in the merchant's Shopify admin, in shipping profiles assigned to products,
not in PolyBag.

**Shopify's `auto` is not a service class.** Omitting `preferredRateSelection` gives Shopify an
unconstrained choice: the buyer-selected delivery method first, then shop preference, then
Shopify's own recommendation. There is no guarantee about service class, carrier, delivery
commitment, special services, or price. It is not a rate at all — it is a **blind purchase**.

The catalog model also has a hard schema constraint: `carrier_services.carrier_id` is a
non-nullable foreign key. A discovered service therefore **cannot exist as a `CarrierService`
row without a `Carrier` row**, which sits badly against ADR-0002's position that a carrier of
record may legitimately have no row and never need one.

What is *not* wrong is the operator-facing concept. `ShippingMethod` is a `belongsToMany` to
carrier services, so "Ground" → `[USPS_GROUND_ADVANTAGE, FEDEX_GROUND, UPS_GROUND]` is already
how it is used. That is a service class, and it absorbs direct carriers and Amazon cleanly. It
does not absorb Shopify, because Shopify does not offer services.

Finally, the app already solves the *naming* half of this for imports:
`shipments.shipping_method_reference` holds the raw channel string beside a nullable FK, import
never rejects an unresolved reference, `UnmappedShippingReferences` groups unresolved references
by string and client, and assigning creates a client-scoped `ShippingMethodAlias`.

## Decision

**1. Discovery applies to Amazon only.** Shopify does not participate in service discovery or
service aliasing, because it has nothing to discover.

**2. Observation, normalization and approval are three separate concepts**, not one:

| Concept | Question | Where it lives |
|---|---|---|
| Raw observation | what did Amazon report? | its own store, written by the adapter |
| Normalization | what do we call it? | an alias, modeled on `ShippingMethodAlias` |
| Approval | may automation spend money on it? | an explicit flag, scoped |

Raw observations **do not mutate `CarrierService`**. Observation must not silently rewrite
authored configuration, and keeping them separate also sidesteps the non-nullable `carrier_id`:
an observed offer for a carrier we have no row for is storable, where a `CarrierService` would
not be.

**Promotion creates canonical identities deliberately, or not at all.** For an offer naming a
carrier we have no row for — DHL eCommerce, OnTrac — a human either selects an existing
`CarrierService`, or explicitly creates the `Carrier` and `CarrierService` identities as an act
of catalog authorship. External discovery must never create either by itself. An offer that
nobody promotes stays **permanently human-selectable** rather than sitting in a queue; promotion
is what unlocks automation, and normalization is therefore a precondition of approval, not a
parallel track.

**3. Approval is scoped to postage source, client, and environment.** Environment matters
specifically: Amazon's sandbox and production service identifiers differ, so an approval earned
in sandbox must not authorize spending in production.

**4. Discovered is not approved.** An unapproved Amazon service is selectable **by a human on
the Ship page**, where a person sees the price and takes responsibility. It is excluded from
`RateSelector::selectBest()`, shipping rules, batch ship and auto-ship until approved.

**5. Shopify is governed as a blind purchase, not as a rate.** Specifically:

- The carrier of record is discovered from `trackingInfo.company`, and nothing else is.
- **Shopify-confirmed** service and cost stay permanently unknown. `packages.cost` is already
  left null; the service column must stop receiving `trackingCompany`. The service may later be
  *inferred* from the tracking number — that is a different provenance, not a contradiction.
- A requested `preferredRateSelection` is **audit metadata, not a confirmed fact** — Shopify may
  ignore it, and the response is the only record.
- Shopify never enters rate comparison or `selectBest`. Auto-ship and batch ship may buy a blind
  purchase only where the client has opted in and configuration makes the choice unambiguous:
  a matching shipping rule names the selection, or it is the ShippingMethod's sole configured,
  package-eligible selection. It must not become a fallback merely because another configured
  seller's rate call failed.
- The Ship page must warn explicitly that price and service are unknown until purchase.
- A shipment carrying a **hard-required special service** must exclude Shopify, since no
  guarantee about special services survives Shopify's unconstrained selection.

**6. Blind purchase is a distinct selectable option in the rate list, never a fabricated rate.**
`ShopifyAdapter::getRates()` currently returns `RateResponse` objects with an invented price, an
invented service name and `Shopify` as the carrier; leaving that in place would guarantee the
implementation drifts back to the model this ADR rejects.

It is modeled as a **priceless offer** — a type of its own, not a `RateResponse` — presented
alongside rates, visually separated, requiring explicit confirmation when a person selects it,
and never entering any comparison or ranking. It is *not* a separate attended action on its own
screen: the packer's workflow is "look at the options, choose one," and moving the single path
that saves ~25% off that screen is how it ends up never being used. Unattended selection is
instead gated by the explicit configuration in decision 5.

**7. The service value carries evidence; the requested preference is separate.** "Requested" is
not a provenance state — we can ask Shopify for Ground Advantage and still end up with a
confirmed, inferred or unknown actual service, so the two coexist rather than compete. The model
is:

| Field | Meaning |
|---|---|
| requested preference | what we asked the source for — **audit metadata**, never the service value |
| service value | the actual service, or null |
| evidence | `confirmed` (the source reported it), `inferred` (derived), or `unknown` |
| inference method + ruleset version | recorded whenever evidence is `inferred` |

**Channel exports publish `confirmed` only.** Amazon's `shipmentConfirmation` treats
`shippingMethod` as optional — `AmazonSource::exportPackage()` already omits it when blank — so
an inferred service is omitted rather than published. A guess sent to a marketplace becomes a
buyer-facing fact we cannot retract, and omitting a field costs nothing.

**8. Unmapped is a valid terminal state.** A package may name a carrier and service that have no
rows, permanently. Nothing blocks, and nothing nags.

### Terminology

Used consistently below, because four of these are routinely collapsed into "service":

| Term | Means |
|---|---|
| **Offer** | one ephemeral, package-specific quote: price, promise, purchase token, expiry |
| **Observed service** | a durable `(source, environment, marketplace, carrierId, serviceId)` identity, with first/last seen and mapping state |
| **External identifier** | the source's own carrier/service IDs, meaningful only within that source and environment |
| **Normalization** | mapping an observed service onto an existing `CarrierService` |
| **Catalog authoring** | deliberately creating new `Carrier` / `CarrierService` identities — a human act, never a side effect of discovery |

## Options considered

**A. Keep the catalog authored; silently drop anything unseeded** (the USPS treatment).
Rejected for Amazon: hand-maintaining service IDs that vary by marketplace and differ in sandbox
goes stale invisibly — a dropped service keeps being requested, a new cheaper one never appears.

**B. Cross-product catalog rows** — "(Shopify) USPS Ground Advantage". Rejected: `service_code`
stops meaning the carrier's own code, moving the conflation ADR-0002 removed from `Carrier` down
into `CarrierService`; the catalog grows as sources × services; and for Shopify the row would
describe a service that was never offered or confirmed.

**C. Map the same carrier service several times, once per source.** Blocked as the schema
stands: `carrier_service_shipping_method` is a bare join table whose composite primary key is
exactly its two columns, with no payload.

**D. Observation → normalization → approval, Amazon only** — this ADR.

**E. Write discovered services straight into `CarrierService`.** Rejected on review: it requires
inventing `Carrier` rows for carriers we have no relationship with, and it lets an external API
mutate authored configuration.

## Trade-off

Deny-by-default versus discovery. Today only a deliberately seeded service can ever be bought,
which is a control a 3PL relies on — "we never ship Priority Mail Express on this client's
account." Pure discovery removes it; pure deny-by-default makes Amazon nearly useless, since its
catalog is only ever visible one quote at a time.

Splitting on **who is choosing** resolves it for Amazon: a packer picking an unfamiliar service
off a rate list has seen the price and taken responsibility; an auto-ship rule picking it at
03:00 has not. Discovery governs what is *visible*; approval governs what automation may *reach*.

That reasoning does not extend to Shopify, and this is the correction the review forced. With
Amazon the packer sees a price and a named service. With Shopify they see neither, so "a human
chose it" is a much weaker safeguard — it is informed consent to a blind purchase, which is why
decision 5 requires an explicit client opt-in rather than treating attended selection as
sufficient.

## Consequences

Easier:

- New Amazon services appear the day Amazon starts offering them.
- Shopify can report any carrier at all without a schema change or a fabricated `Carrier` row.
- Authored configuration stays authored; nothing external rewrites it.

Harder:

- A new store for raw observations, plus a promotion path into the catalog.
- Approval scoping across source × client × environment is more surface than a boolean.
- `CarrierAdapterInterface::getRates(RateRequest, array $serviceCodes)` bakes in "the caller
  knows the codes up front." That is already a fiction for Shopify, where the only codes it can
  filter on are ones we invented and seeded. The parameter wants to become a constraint — class,
  allowlist, price ceiling — rather than an enumerated list. Not required on day one: Amazon can
  filter after the quote.
- Reporting has to tolerate services that existed for exactly one parcel.
- **Amazon normalises to metric internally** and does not say so. It evaluated dimensional
  limits in cm3 against an INCH request and returned `billedWeight` in KILOGRAM for a POUND
  request. Conversion is needed in both directions at the DTO boundary, and a unit assumption
  anywhere in that path is a silent wrong answer rather than an error.
- Eligibility is per-parcel and per-order, so a captured response documents a catalog but never
  a stable offer set. The run behind this ADR used a ten-month-old order whose delivery promise
  had long expired, which skewed its eligible set to fast, expensive services — the catalog it
  showed is representative, the eligibility is not.

To revisit:

- ~~Whether `ineligibleRates[]` is worth harvesting as a discovery surface.~~ **Resolved
  2026-09-02: yes, for identity only.** A production run returned 102 entries across fourteen
  carriers, which is by far the richest catalog view Amazon offers. But every entry carries
  `code: "UNKNOWN"`; the real content sits in sixteen distinct prose `message` strings
  ("Expression 'L * W * H' = 11880 exceeds maximum 2949.67", "This shipping service does not
  deliver from the given source address to the destination address"). Harvest carrier and
  service identity from it; do not build logic that branches on a reason code, because there
  is not one.
- Inferring the purchased *service* from the tracking number. USPS IMpb embeds a three-digit
  Service Type Code identifying mail class, product and extra services, published in a current
  Publication 199 and a separately maintained STC list; UPS documents a service-level indicator
  in bytes 9–10 of a 1Z number, though contract and regional codes should be expected. Label
  parsing is a fallback only — PDF text layers are not guaranteed — and OCR is disproportionate
  unless real data says otherwise. Any implementation must validate tracking-number format and
  check digit before inferring, record the ruleset version used, and measure coverage against
  real Shopify packages. Cost stays unknown regardless.
- Note that tracking-number inference outliving label parsing is a consequence of the *current*
  retention policy — `PurgePiiCommand` clears `label_data` while tracking numbers are kept — not
  a permanent property.

## Implementation

Tracked as issues, not here — sliced in the private ops repo under
`docs/issues/amazon-buy-shipping/` for the discovery and approval work, with the Shopify
blind-purchase half under `docs/issues/shopify-shipping-carrier/` and the shared data-model
changes under `docs/issues/postage-source-split/`. This document records the decision and why;
it is not a checklist and does not get updated as work lands.
