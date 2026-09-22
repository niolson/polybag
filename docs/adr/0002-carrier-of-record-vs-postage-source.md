# ADR-0002: Record the carrier of record separately from the postage source

## Status

Accepted — 2026-09-01. Amended 2026-09-02: the discriminator takes **two** values, not three.

Amended by ADR-0004 (accepted 2026-09-14): the carrier-of-record, service-evidence and
postage-source columns this decision placed on `packages` gain a second home on a
per-purchase `package_labels` row, and the `packages` copy becomes the projection of the
active label. Nothing below changes in meaning; where the two rows disagree, the label
row is authoritative. See `0004-package-label-as-a-record.md`.

Clarified 2026-09-22: the Shipping v2 distinction is between on-Amazon
(`channelType: AMAZON`) and off-Amazon (`channelType: EXTERNAL`) orders. The implemented
Buy Shipping adapter covers only on-Amazon orders and binds to their originating Amazon
`DataSource`; those offers may be carried by Amazon Shipping, USPS, UPS, FedEx, or another
carrier. The API can sell Amazon Shipping for off-Amazon orders, but PolyBag does not yet
implement that path. When implemented, it should select an eligible connected Amazon
`DataSource` for the Shipment's Client; the original `CarrierAccount` statement in decision 9
is superseded because these are still Shipping v2 seller credentials, not direct-carrier
credentials.

`legacy_unknown` was specified below for packages shipped before the split, on the
assumption that some of them had unrecoverable provenance. None do. Shopify Shipping bought
no label before the discriminator landed — in development or in any tenant — so every
package predating it was bought directly, and the backfill records `carrier_account`, which
is what is true of them. The value was also never behaviorally distinct: decision 3 routes
`legacy_unknown` and `carrier_account` to carrier dispatch alike. A shipped package with no
recorded postage source is now impossible rather than merely unexpected, and a null
`postage_source` means only that the package has not shipped. The table and the sentence
below are left as written, as the reasoning at the time.

Amended 2026-09-03: decision 3's Shopify ship-date policy is settled — **8 PM local, held as
`pickup_cutoff_hour` on the Shopify carrier row**.

The original wording called for "the earliest relevant cutoff across the carriers it might
pick." Two things were wrong with it once the candidates were actually enumerated.

*The candidate set was already known and did not need a live purchase to bound it.* Schema
introspection had established Shopify's carrier codes as `usps`, `ups_shipping`,
`dhl_express` and `canada_post`, recorded in `ShopifyShippingLabelService::CARRIER_CODES`.
What a first live purchase adds is how often Shopify overrides `preferredRateSelection` —
evidence for tuning a value, not for choosing a policy.

*Conservatism was assumed to be free, and is not.* The two failure directions cost very
different amounts. Assuming a cutoff later than the carrier Shopify actually picked mis-dates
one label by a day. Assuming an earlier one — low enough to cover DHL Express — re-dates
**every** Shopify package packed after that hour, including the USPS ones that are the entire
reason the integration exists, in the heaviest packing window of the day. Being conservative
by construction is cheap when the pessimistic case is rare; here it is the common case. So
the policy matches the carrier we ask for and expect, and treats an override as the tolerated
exception.

Deriving the hour from other carriers' cutoffs is rejected for a second reason: it would make
Shopify's ship dates a function of which unrelated carriers happen to have a cutoff
configured. Today only USPS does, so it would yield 20 anyway — and would silently move the
day someone configured UPS.

The hour lives on the carrier row rather than in `ShipDateService`, so the policy is data in
one place and reaches Shopify the same way every other carrier's does; the service now holds
no Shopify branch at all. Note that the hour survives into the Shopify request only as a
binary, since `getShipDate()` returns a date at midnight: before the cutoff
`ShopifyShippingLabelService` sends `now() + 5 minutes`, after it, tomorrow at 00:00.

Two consequences are deliberately left out of scope. **Pickup days are not decided here** —
nothing seeds a `carrier_location` row for Shopify, so it takes the Mon–Fri default and a
Saturday-packed package is dated Monday regardless of any cutoff; that is tracked separately.
(Settled by the 2026-09-04 amendment: Mon–Fri, and as an app-wide default rather than a
Shopify one, because no carrier is seeded a pivot row.)
And no per-package alert is raised when Shopify picks a carrier whose real cutoff differs:
the ship date is fixed by the time `trackingCompany` comes back, `metadata.shopify_tracking_company`
already records what was picked, and an operator who knows the pickup has gone can already
advance the date with End Shipping Day.

Amended 2026-09-03: a Shopify-bought label is **not** terminally untrackable. Unlike the two
amendments above, this one rewrites what it supersedes — decision 3 and the entitlement note
below now describe tracking through the fulfillment, and the sentence they used to carry
("A Shopify-bought label is **terminally untrackable**") survives only in this paragraph,
because leaving a wrong instruction in place next to the right one is how a reader ends up
following the wrong one.

The entitlement note is right that we may never call the USPS Tracking API for a label
carrying Shopify's MID. It then slid from "we cannot call USPS" to "no tracking data exists",
which does not follow. Shopify holds the entitlement, pays for it, and reports the result back
as the merchant's own order data. `Fulfillment.displayStatus` carries the whole delivery
lifecycle — `CARRIER_PICKED_UP`, `IN_TRANSIT`, `OUT_FOR_DELIVERY`, `ATTEMPTED_DELIVERY`,
`DELAYED`, `DELIVERED`, `NOT_DELIVERED` — beside `inTransitAt`, `deliveredAt` and
`estimatedDeliveryAt`, and Shopify populates it itself for every tracking company on its
supported-carriers list, which covers all four carrier codes it will sell postage from.

We were already reading that field. `ShopifyShippingLabelService::isVoidedInShopify()` selects
`displayStatus` on the fulfillment it matches by tracking number, then discards every value
except `LABEL_VOIDED` and `CANCELED`. The tracking answer has been arriving in the void-sync
response since the integration landed.

Neither the axis nor the prohibition changes: tracking still dispatches on the postage source,
and there is still no fallback chain — "try the carrier next" remains a request we are not
entitled to make, whatever Shopify answers. Only the terminal state changes. A Shopify-bought
label tracks through Shopify, exactly as an Amazon-bought one tracks through Amazon.

Amended 2026-09-04: the pickup-day half of the ship-date policy, left out of scope by the
cutoff amendment above, is settled — **Monday–Friday, as the default for every carrier,
with Saturday left to the per-location config that already exists**.

The question was framed as a Shopify one, on the grounds that `CarrierSeeder` seeds no
`carrier_location` row for Shopify and so a Saturday-packed Shopify package is dated Monday
regardless of any cutoff. That framing was wrong in a way worth recording: nothing seeds that
pivot for *any* carrier. USPS, UPS and FedEx run on the same `DEFAULT_PICKUP_DAYS` fallback
on a fresh install. There was never a Shopify-shaped gap to close, only an app-wide default
nobody had written down, and closing it Shopify-only would have meant seeding a pivot row for
one carrier purely to make the statement enforceable.

So the value stays where it is, in `ShipDateService::DEFAULT_PICKUP_DAYS`, and is deliberate
rather than incidental: Mon–Fri never dates a label for a pickup that does not happen, and its
cost — a Saturday-packed parcel carrying Monday's date — falls on warehouses that do hand
parcels over on Saturday, which is exactly the variation the per-carrier, per-location pickup
days config exists to absorb. Seeding Mon–Sat globally would assert something about every
install that is true of only some, and we do not have the Saturday packing volume that would
settle it either way. Note the asymmetry does *not* run the way it did for the cutoff: there,
conservatism was the expensive direction because the pessimistic case was the common one.

Nothing is seeded, so no migration is needed to cover existing installs — they already run on
this default and always have.

Two consequences follow for the config that now carries the policy. It has to say what it is
overriding, since both repeaters start empty and an operator wanting Saturday would otherwise
see no row at all; both now state the Mon–Fri default in their section description. And an
empty day set is no longer passed through to `getShipDate()`: it reached the tomorrow fallback
in `getNextPickupDay()` and dated labels for Sundays, so it now falls back to the default, and
the form requires at least one day.

What that config deliberately cannot express is "this carrier never collects at this location".
An empty set and a deleted row both land on the default, and should: a package shipped on that
carrier still needs a ship date, and there is no honest one to give it if the carrier is held
to collect on no day at all. Keeping a carrier out of a location is what carrier account
scoping does. The pickup days say only when a carrier that does serve a location collects, and
removing a row resets that carrier to the default rather than disabling it — which is what the
form now tells the operator, because the opposite reading is the natural one.

Reconciled 2026-09-04, after the last slice landed: three passages had been left standing that
the amendments above had already contradicted. Decision 3 still routed "explicitly-marked
legacy packages" to carrier dispatch, a branch the 2026-09-02 amendment removed; the
trackability item under *To revisit* still enumerated two routes to Shopify tracking, when the
2026-09-03 amendment found a third and shipped it; and the *Migration hazard* section still
described a `metadata.shopify_tracking_company` recovery scheme that was deliberately never
built. Each is now struck or corrected in place rather than deleted, on the same reasoning the
2026-09-03 amendment gives for rewriting what it supersedes: a reader who finds the wrong
instruction beside the right one may follow either. No decision changes here — this pass only
makes the document say what was already true.

## Context

`packages.carrier` is a denormalized string, not a foreign key to `carriers`. It does two
unrelated jobs:

- **Adapter dispatch key** — `TrackingService::refreshPackage()` and
  `EloquentPackageLabelWorkflow` both do `carrierRegistry->get($package->carrier)` to
  answer "who do I call to track or void this?"
- **Carrier of record** — the audit log, `AggregateShippingStats`, the channel export in
  `PackageExportService::buildExportData()`, the manifest grouping in
  `ManifestService::getUnmanifestedPackages()` and `EndOfDay`, and the USPS cutoff rule in
  `ShipDateService::getShipDate()` all read it as "who is carrying this parcel?"

Those were the same value while every label was bought from the carrier that carries it.
Shopify Shipping (daf1bd8, 2026-08-29) ended that: it buys postage on the merchant's
Shopify account and Shopify picks the carrier, so the package is written with
`carrier = "Shopify"` while the real carrier lands in `packages.service` and
`metadata.shopify_tracking_company`. Shopify is not a carrier, and three behaviors are
already wrong because the column says it is:

1. **The channel export reports a non-carrier.** `PackageExportService` passes
   `$package->carrier` to the destination; `AmazonSource::CARRIER_MAP` has no `Shopify`
   key, so a Shopify-bought label on an Amazon order confirms as
   `carrierCode: "Other", carrierName: "Shopify"` carrying a USPS tracking number. The
   buyer gets a tracking link that does not resolve.
2. **Ship-date cutoffs miss.** `ShipDateService` applies the 8 PM USPS cutoff by carrier
   name. A Shopify-bought USPS parcel passes `"Shopify"`, skips the rule, and is dated for
   a pickup it will not make.
3. **Tracking dispatches on the wrong axis.** `ShopifyAdapter::supportsTracking()` is false
   because *Shopify* has no tracking API, so a real USPS parcel with a real USPS tracking
   number reports as untrackable. The reason it stays untrackable turns out not to be the
   model's fault (see the USPS entitlement note below) — but the code arrives at the right
   answer by accident, through a carrier name that is not a carrier.

Amazon Buy Shipping, the next integration, makes the conflation actively lossy rather than
merely wrong. Its `getRates` returns `carrierName` per rate and offers USPS, UPS and Amazon
Shipping side by side; filing all of them under one "Amazon Buy Shipping" carrier row
discards what the API is handing us. Amazon also introduces a third shape the current model
had no room for: **Amazon Shipping on a non-Amazon order**, where the carrier is Amazon
Shipping but the postage is bought through Shipping v2 using a connected Amazon data source
that is not the Shipment's import source.

## Decision

Treat these as two axes and record both on the Package.

**1. `packages.carrier` always names the physical carrier.** USPS, UPS, FedEx, Amazon
Shipping. Never a marketplace, storefront, or reseller. It stays a denormalized string
deliberately, not as a legacy artifact: it records what the carrier of record *was*, including
values we hold no `Carrier` row for and never will. See option D below.

**2. The postage source is where the label was bought**, and is exactly one of:

| Bought through | Pointer | Exists |
|---|---|---|
| A direct carrier account | `packages.carrier_account_id` | yes |
| Sales-channel postage (Shopify Shipping, Amazon Buy Shipping) | `packages.postage_data_source_id` | **new, nullable FK** |

Named `postage_data_source_id`, not `data_source_id`: it records where the postage was bought,
which is not necessarily the shipment's import source and must not be confused with it.

Which of the two applies is recorded by an explicit **discriminator**, not inferred from which
pointer happens to be null. Two nullable columns cannot tell a deliberately recorded legacy
package apart from missing or corrupt data. The discriminator takes exactly three values:

| Value | Pointer set | Meaning |
|---|---|---|
| `carrier_account` | `carrier_account_id` | bought directly from the carrier |
| `postage_data_source` | `postage_data_source_id` | bought through sales-channel postage |
| `legacy_unknown` | neither | shipped before this change; provenance genuinely unrecoverable |

Consistency rule: the discriminator and its matching pointer agree, and no other pointer is set.
`legacy_unknown` is writable only by the backfill, never by a new purchase.

The invariant is scoped to **every new transition to `Shipped`**, and has to be enforced inside
`Package::markShipped()`, which must receive the discriminator as an argument: it writes through
`DB::table('packages')->update()` for optimistic locking, bypassing model events, so a
model-level guard would never fire. Test fixtures are not a domain exception — they satisfy the
invariant like anything else.

**3. Dispatch splits along the axis that actually owns the operation:**

- Rates, label purchase, void, and manifest eligibility → **postage source**. Only whoever
  bought a label can void it or manifest it.
- Ship-date cutoffs, channel export, and reporting → **carrier**, *except for Shopify*, which
  cannot participate: `shippingDatetime` has to be sent in the purchase mutation, before
  Shopify reveals which carrier it picked. Learning afterwards that it chose USPS cannot
  retroactively change the label's ship date. Shopify needs a source-level policy, not a
  carrier-derived one; **that policy is an 8 PM cutoff carried on the Shopify carrier row**,
  settled in the 2026-09-03 amendment under Status.
- Tracking → **by postage source, not as a fallback chain.** The entitlement to tracking data
  follows whoever bought the label, not whoever carries the parcel. An Amazon Buy Shipping
  label tracks through Amazon's `getTracking`. A Shopify-bought label tracks through the
  fulfillment its purchase created — `displayStatus`, `inTransitAt`, `deliveredAt` — matched to
  the package by tracking number the way the void sync already matches it. There is still no
  fallback: "try the carrier next" would be a USPS request we are not entitled to make,
  whatever the source answered. Carrier dispatch applies to direct provenance, and only to
  that — the "explicitly-marked legacy packages" this bullet used to route alongside it are the
  `legacy_unknown` value the 2026-09-02 amendment removed, and there is one branch here now,
  not two. See the entitlement note and the 2026-09-03 amendment.

  Source-reported tracking is coarser than a carrier feed, and that is accepted rather than
  worked around: stage-level statuses with no scan locations, a timeline moving on the
  source's polling cadence rather than the carrier's, and nothing that maps to
  `TrackingStatus::Returned`. An unmatched fulfillment is *no answer*, never a status — the
  same rule that stops it reading as a void.

**4. A selectable rate carries an opaque purchase context, not just a description.** Every
rate must carry:

- the specific **postage source instance** it came from;
- an **opaque purchase context** — for Amazon, the `requestToken` and `rateId` from `getRates`,
  both of which `purchaseShipment` requires and neither of which can be reconstructed from a
  carrier and a service name;
- **carrier and service as descriptive facts**, never as purchase identity.

This decision governs *rates*. Shopify is not a rate and is out of scope here: it is a
**blind-purchase option** with no price, no service and no carrier until after purchase — see
ADR-0003 decisions 5 and 6.

The purchase context stays **server-side**. Rates round-trip through the browser today —
`RateResponse::toArray()`/`fromArray()` exist for Livewire serialization — and `requestToken`,
`rateId`, source identity, environment and expiry must not be authoritative browser state. The
browser selects an **opaque internal offer identifier**; the server holds and validates the
real context. One change closes tampering and replay, offer expiry, direct-versus-Amazon
collisions, several offers for one carrier/service, and exact quote-log selection.

That store is new. `rate_quotes` is an audit log on a 60-day purge, not an authoritative
purchase context, and must not be repurposed as one. The offer store needs: an opaque
identifier, binding to both the package and the postage-source instance, an expiry, atomic
consumption so one offer cannot be spent twice, and idempotent recovery so a purchase that
succeeded at the source but failed on our side is found rather than repeated — the property
`ShopifyShippingLabelService` already has and must not lose.

Identity matters because rate shopping across sources makes collisions real rather than
theoretical. `EloquentPackageShippingWorkflow::selectedRateIndex()` matches a pre-selected rate
on `carrier` + `serviceCode` alone, and `RateQuoteLogger::markSelected()` runs an `update()` on
the same pair — so USPS Ground Advantage offered directly *and* through Amazon would resolve to
whichever came first and would mark **both** quote rows selected.

**5. The carrier of record is normalized for behavior while the raw value is preserved.**
Free text is right for audit history, but operational logic cannot depend on a source's
spelling. `USPS`, `US Postal Service` and whatever Amazon returns in `carrierName` must resolve
to one optional normalized carrier identity, with the raw string kept alongside. The normalized
value is **snapshotted onto the package when it ships**, not recomputed on read: normalization
rules are editable, and recomputing would let an alias edit retroactively rewrite what a past
export or report meant. Normalization
must run **before** any carrier-policy lookup, and cannot itself be built on the name-keyed
`CarrierRegistry` — that registry is the consumer of normalization, not its source. Without this
the split does not actually fix the exact-string consumers it is meant to fix —
`ShipDateService::getShipDate()` compares `$carrierName === 'USPS'`, and the channel export
maps through `AmazonSource::CARRIER_MAP`. Normalization may resolve to nothing, which is the
unmapped terminal state.

**6. `CarrierRegistry`, keyed by carrier name, stops being the dispatch mechanism for
purchase-side operations.** It stays correct for carrier-side ones.

**7. `CarrierAdapterInterface` splits rather than growing role markers.** It currently bundles
quoting, purchasing, voiding, tracking, manifests, special-service policy and rate resolution,
which forces `ShopifyAdapter` to implement most of it as advertised rates, empty parsing,
unsupported operations and no-ops. Two seams are now proven: **postage-source operations**
(quote, purchase, void, **manifesting**, entitled tracking — matching decision 3) and **carrier
policy** (normalization, cutoffs, pickup and reporting behavior). Splitting stops Amazon and
Shopify adapters having to pretend to be physical carriers.

**8. Hard-required special services are evaluated at the offer/postage-source seam**, not purely
as carrier policy. `serviceCapability()` reads as carrier policy only because every offer used
to come from a direct carrier. Concretely: an **Amazon** offer is judged on the capabilities the
offer itself returns (`availableValueAddedServiceGroups` is per-rate, not per-carrier); a
**direct-carrier** offer consults carrier policy as it does today; **Shopify** is unsupported
outright, since nothing about its unconstrained selection can guarantee a required service.
Carrier policy remains the right home for capabilities that really are carrier-wide.

**9. Postage-source resolution has explicit precedence.** "Follow the `CarrierAccount` pattern"
is not enough to bind an offer to a source instance. The rules:

- **Shopify binds to the shipment's originating data source and nothing else.** A purchase is
  keyed to a fulfillment order that exists in exactly one Shopify account, so a Shopify source
  is never a candidate for a shipment that did not come from it. This is already how
  `ShopifyShippingLabelService::dataSourceFor()` behaves; the ADR records it as a rule rather
  than an implementation detail.
- **Amazon Buy Shipping binds the same way** — the order lives in one seller's account.
- **Amazon Shipping on non-Amazon orders** is not implemented. The original decision placed it
  under `CarrierAccount` scoping; the 2026-09-22 clarification above supersedes that detail.
  It needs an explicit rule for selecting an eligible connected Amazon `DataSource` for the
  Shipment's Client without pretending that source was the Shipment's import source.
- **One source is quoted per carrier by default.** Quoting several sources for the same carrier
  is opt-in, mirroring `CarrierAccountScope::rate_shop`, because each extra source is another
  API call on the packer's critical path.
- Ties are resolved by the same priority ordering `CarrierAccount::resolveForShipment()` uses;
  an unresolvable tie is a configuration error surfaced to the operator, never an arbitrary
  pick.

## Options considered

**A. Status quo — the carrier string doubles as the dispatch key.** Rejected: it is already
producing the three defects above, and it discards Amazon's per-rate `carrierName`.

**B. Every postage source becomes a `Carrier` row** (the current trajectory, and what
Shopify did). Rejected: it requires "Amazon Buy Shipping" carrier rows that throw away the
real carrier; it leaves `ShipDateService`, the manifest grouping, and the channel export
keyed on a value that is not a carrier; and `CarrierService` rows become a source × carrier
× service product rather than a catalog of services.

**C. Split the two axes** — this ADR.

**D. Full normalization — replace the string with `packages.carrier_id`.** Rejected, and the
denormalized string is now a deliberate choice rather than a legacy artifact. Shopify picks
the carrier itself and may pick one we have no row for and no reason to create — an Italian
courier on a single parcel. A shipped package must be able to name a carrier that does not
exist in our catalog, so **unmapped is a valid terminal state** and the carrier of record has
to survive as free text. See ADR-0003.

## Trade-off

The cost lands on the selection axis. `ShippingMethod → CarrierService` is how an operator
says "this ships Ground Advantage." Under option B, "buy through Shopify, let Shopify
choose" is expressible as a `CarrierService` row, which is why it was built that way. Under
this decision it is not a carrier service at all and needs its own representation.

We accept that cost. With Shopify the carrier genuinely is not known until the purchase
response comes back, and encoding "unknown" as a fake carrier named after the storefront is
precisely what produces the three defects. A model that admits the carrier is unknown at
quote time is the honest one.

## Consequences

Easier:

- Tracking an Amazon Buy Shipping label through Amazon's `getTracking`, which today would
  have no route at all.
- Reporting a real carrier code to Amazon and Shopify on export.
- The USPS cutoff, per-carrier stats, and per-carrier pickup days all mean what they say.
- Adding a postage source no longer requires inventing a carrier that does not exist.

Harder:

- Two-key dispatch instead of one, and `CarrierAdapterInterface` splits in two (decision 7).
- Every rate now carries purchase identity, so rate DTOs, Livewire round-tripping, quote logging
  and pre-selected-rate matching all have to key on more than carrier + service.
- Every existing query on `packages.carrier` has to be audited for which of the two meanings
  it intended.

To revisit:

- ~~Whether Shopify-bought labels ever become trackable. Only two routes exist: Shopify adds a
  tracking API, or the merchant obtains a USPS Merchant Access Token from Shopify. Neither is
  in our control.~~ **Resolved by the 2026-09-03 amendment.** Both named routes were routes to
  a *carrier* feed, and the enumeration missed the one that did not need one: Shopify already
  reports the delivery lifecycle back as the merchant's own order data, on the fulfillment, in
  a field the void sync was already selecting. What remains open is how coarse that feed is in
  practice, not whether it exists.
- Whether `CarrierService` is the right selection primitive for channel postage at all.

## Note: tracking entitlement is not ours to grant

USPS changed tracking access control on 2026-04-01. Free access to the Tracking API requires
either that the MID embedded in the package barcode is registered to us, or that the shipper
authorized us through one of their active Merchant Access Tokens in the USPS Business Portal.
Everything else is paid: a signed IP agreement and Tracking Data Services, entry tier around
$599/month for 100k calls, reportedly on a non-cancellable annual term.

A Shopify- or Amazon-bought USPS label carries **their** MID, not the merchant's. So no free
entitlement exists for those parcels and the Merchant Access Token route means persuading
Shopify to authorize an individual merchant, which is not a realistic path. Directly-bought
labels are unaffected — our own MID, free, exactly as today.

This is why tracking dispatches on the postage source rather than the carrier. USPS's own API
now keys on who bought the label, which is the same distinction this ADR draws, enforced from
the carrier side. USPS PTR2 tracking semantics are documented separately; this note covers entitlement only.

What this note does **not** establish is that those parcels are untrackable. It answers one
question — whether we may call the carrier's API for them — and the answer is no. Reading the
parcel's status from the source that bought the label is untouched by any of the above: that
is the merchant's own order data, not a carrier feed. See the 2026-09-03 amendment.

## Migration hazard

`ManifestService::getUnmanifestedPackages()` groups by `packages.carrier` and `EndOfDay`
filters the same way. Shopify-bought labels are excluded from the USPS SCAN form **only**
because their carrier column reads `"Shopify"` — correct behavior for the wrong reason.
Rewriting those rows to `"USPS"` without simultaneously adding a postage-source filter would
sweep labels we did not buy, and cannot manifest, into a USPS SCAN form. The query change
must land in the same commit as the backfill, not after it.

~~The backfill window is small and closing: Shopify Shipping shipped 2026-08-29, days before
this ADR, so almost no affected rows exist yet. `carrier` is recoverable from
`metadata.shopify_tracking_company`, falling back to `service`; `postage_data_source_id` from the
shipment's data source. This is the cheapest this change will ever be.~~

**Superseded 2026-09-04.** The rule in the paragraph above stands and was honoured — the
postage-source filter landed in the same commit as the backfill. The recovery scheme in this
one was never built, and should not be read as describing what runs.

The window was not small but **empty**: no Shopify Shipping label has ever been bought, in
production or in development, before or during this work. So there is nothing to recover
`carrier` *from*, and inferring one from `metadata.shopify_tracking_company` would have meant
reconstructing a carrier of record by guesswork — the thing decision 1 exists to stop.
`2026_09_02_193821_backfill_package_postage_source` therefore records the one provenance every
pre-split row actually has, `carrier_account`, and **throws** on any row that looks
Shopify-bought rather than rewriting it. Expected matches: zero, and zero is what it found.

The general lesson is worth keeping even though the specific scheme is not: a backfill that
would have to infer a fact should fail on the rows it cannot know rather than invent them, and
a window this narrow is better closed with a guard than with a heuristic.

## Implementation

Tracked as issues, not here — the work is sliced in the private ops repo under
`docs/issues/postage-source-split/`, with the Amazon follow-on in
`docs/issues/amazon-buy-shipping/`. This document records the decision and why; it is not a
checklist and does not get updated as work lands.
