# ADR-0005: A box size has a physical form and, independently, a carrier-supplied identity; every rate says which it requires

## Status

Accepted — 2026-09-15. Depends on ADR-0003 (an Amazon offer's packaging requirement is read
off the observed service). Gives `amazon-buy-shipping/08` its answer.

Proposed 2026-09-15 and accepted the same day after four review passes, each of which is
recorded below and under "Options considered" so that the rejected shapes stay rejected.

Written after the 2026-09-11 Amazon live run listed thirteen offers — flat-rate envelopes,
flat-rate boxes and FedEx One Rate — for a parcel in the packer's own 4×6×6 box. The narrow
fix for that is `amazon-buy-shipping/12`; this document is about why the fix is a fourth
hard-coded list and what replaces all four.

The first pass took the draft apart. It attached a packaging restriction to
`CarrierService`, which cannot hold one because ADR-0003 lets several Amazon services with
different packaging alias to one canonical service; it made shipper-supplied letters a case
of the carrier-identity enum, contradicting its own split; it decided product content
classes, automation policy and seed data alongside packaging; and it assumed a per-location
box-size model that does not exist. All four are corrected here, and the content-class half
is deferred to a later ADR rather than folded in. The later passes found that a single
required packaging value cannot express Amazon's FedEx One Rate offers, that a shared filter
on carrier identity cannot also do the adapters' physical-form filtering, that the filter is
bypassed by shipping-rule pre-selection unless it runs inside `resolvePreSelectedRate()`, and
that the pre-selection contract then needs an empty outcome. Each is now a decision.

## Context

A carrier service can be valid only for a particular **packaging**. USPS prices Priority
Mail differently in its own flat-rate envelope, its padded flat-rate envelope, and each of
three flat-rate boxes; FedEx One Rate exists only in FedEx-branded packaging; UPS has a
Letter, a Pak and Express boxes; USPS Cubic pricing has one tier table for boxes and another
for soft packs. The app's knowledge of which of these a `BoxSize` *is* lives in three places,
each carrier-shaped:

- `box_sizes.type` (`BoxSizeType`: `BOX` / `POLYBAG` / `PADDED_MAILER`) — the physical form,
  used by `UspsAdapter::isValidRateIndicator()` to choose cubic tiers. Correct, and the seed of
  this decision.
- `box_sizes.fedex_package_type` (`FedexPackageType`) — FedEx's own enumeration, stored
  directly, used by `FedexAdapter::isOneRateEligible()` and sent as `packagingType`.
- Nothing for UPS: `UpsAdapter` hard-codes `PackagingType` and never offers a Letter or a Pak.

Nothing can say a box size *is* a USPS Priority Mail Small Flat Rate Box. So no adapter can
rate one, and every adapter must *exclude* those services — which `UspsAdapter` does through
a rate-indicator list and the Amazon adapter, after `12`, does through a serviceId-pattern
list. Two copies of the same exclusion, in two vocabularies, and neither can ever be turned
into an inclusion.

**Where the carriers put packaging is not uniform**, and this constrains the design more
than anything else:

| Source | Packaging expressed as | Service identity |
|---|---|---|
| USPS direct | `rateIndicator` on each rate, `processingCategory` on the request | `mailClass` — *one* service (`PRIORITY_MAIL`) covers own packaging and all five flat-rate packagings |
| FedEx direct | `packagingType` on the request; One Rate is a special-service flag | `serviceType` — the same service in own or FedEx packaging |
| UPS direct | `PackagingType` code on the request | `Service.Code` — same |
| Amazon Buy Shipping | **baked into the serviceId** — `USPS_PTP_PRI` and `USPS_PTP_PRI_FRE` are different services; `FEDEX_PTP_..._ONE_RATE` likewise | per serviceId |
| Shopify | not expressed; blind purchase (ADR-0003) | inferred after the fact |

Two things follow. For three of the five sources packaging is a *request parameter* the
adapter must set and a *response attribute* it must read; for one it is part of the
*service's identity*. And under ADR-0003 an operator may alias both `USPS_PTP_PRI` and
`USPS_PTP_PRI_FRE` to the canonical `PRIORITY_MAIL` row — so a packaging restriction cannot
live on `CarrierService`, because one row would need two answers. The only identity that
every source agrees on, and that always knows its own packaging, is the **rate**.

A related fact, deliberately *not* in scope here: Media Mail, Bound Printed Matter and
Library Mail are restricted by **contents**, not packaging. `12` drops them by identifier
and `UspsAdapter` drops them by mail class. Supporting them means a per-product declaration
in the shape of `Product.hazmat_class`, and a policy question about whether automated
selection may ever choose them on price. That is a decision of its own; see "Foreseen, not
decided".

## Decision

**A Package's packaging has two independent dimensions: its physical form, and whether it is
a carrier's own supplied packaging. This decision implements the existing parcel forms and
carrier identity only. Letters and flats may later extend form; oversize stays derived from
measurements; other handling characteristics may be added independently without touching
carrier identity.**

**1. `BoxSize` keeps `type` as the physical form and gains a nullable carrier identity.**
`box_sizes.type` (`BoxSizeType`) is unchanged and keeps driving cubic tiers. A new nullable
`carrier_packaging` column (enum `CarrierPackaging`) says whose packaging it is when it is
not the packer's own: `UspsFlatRateEnvelope`, `UspsLegalFlatRateEnvelope`,
`UspsPaddedFlatRateEnvelope`, `UspsSmallFlatRateBox`, `UspsMediumFlatRateBox`,
`UspsLargeFlatRateBox`, `FedexEnvelope`, `FedexPak`, `FedexTube`, `FedexSmallBox` …
`FedexExtraLargeBox`, `UpsLetter`, `UpsPak`, `UpsExpressBox` (and sizes), `UpsTube`. Null
means the packer's own packaging of whatever `type` says. A USPS padded flat-rate envelope is
`type = PADDED_MAILER` with `carrier_packaging = UspsPaddedFlatRateEnvelope`; the two axes
do not collapse.

`fedex_package_type` is replaced by this column with a data migration. `FedexAdapter` maps
`CarrierPackaging` to `FedexPackageType` at the request boundary and nowhere else.

**2. `PackageData` carries both.** `boxType` stays; `carrierPackaging` replaces
`fedexPackageType`. Every adapter receives the same two facts and does with them what its
API allows.

**3. Every rate says which carrier packaging it requires — as a requirement, not a single
value.** `RateResponse` gains `packagingRequirement: PackagingRequirement`, a small value
object with three constructors — `shipperPackaging()`, `exactly(CarrierPackaging)`,
`anyOf(CarrierPackaging ...)` — and one method, `accepts(?CarrierPackaging): bool`. The
package's identity stays exact; the rate's requirement may match several. That asymmetry is
forced by the data: a USPS serviceId names one envelope or box, but Amazon's
`FEDEX_PTP_..._ONE_RATE` says only "FedEx-supplied packaging" and never which — so its
requirement is `anyOf(FedexEnvelope, FedexPak, FedexSmallBox, …)`, while the direct FedEx
One Rate rate, which came back from a request that named the packaging, is `exactly(…)`.
The adapter that produced the rate sets the requirement, in its own vocabulary, because it
is the only party that knows: USPS from `rateIndicator`, FedEx from whether the rate came
back from the One Rate request, UPS from the `PackagingType` it sent, Amazon from the
serviceId. A service sold both in the shipper's packaging and in the carrier's is not one
rate with two answers; it is two rates — FedEx Express Saver and FedEx Express Saver One
Rate — each with one.

**4. One filter, shared, for carrier identity only — applied to every collection of rates an
adapter returns, not at one call site.** A rate is kept only when
`packagingRequirement->accepts($package->carrierPackaging)`. That is the whole of the shared
rule, and it enforces *only* the carrier-identity axis. It is a shared operation on a rate
collection, and it runs wherever adapter rates are collected: in `ShippingRateService` after
`getRates()`, and inside every `resolvePreSelectedRate()` **before the adapter chooses a
variant**. The second site is not optional. A shipping rule that pre-selects a service goes
`EloquentPackageShippingWorkflow::selectedRateForAutoShip()` → the adapter's
`resolvePreSelectedRate()` → `RateSelector::selectForAutomation()` and never passes through
`ShippingRateService`; and `UspsAdapter::resolvePreSelectedRate()` calls its own `getRates()`
and returns the cheapest variant. Today that is safe only because `isValidRate()` has already
discarded the flat-rate indicators. Once they survive as `exactly(…)` requirements, the
cheapest Priority Mail variant for a small box is the flat-rate envelope, and an automated
rule would buy it unseen. So the filter runs on the resolved collection first, and the
variant is chosen from what remains.

**The empty outcome is part of the contract.** Filtering before choosing can leave nothing,
and today every adapter answers "nothing" by returning the rule's own synthetic rate — USPS
when its fetch comes back empty, FedEx, UPS and Amazon unconditionally — which would hand
back the very rate the filter exists to stop. So `resolvePreSelectedRate()` becomes
`?RateResponse`: it returns null when no compatible variant remains, and
`selectedRateForAutoShip()` treats null as "no pre-selection" and falls through to ordinary
rate shopping, where the filter runs again on real rates. A rule's synthetic rate carries
`shipperPackaging()` — a rule names a service, never a packaging — so an adapter that would
return it as-is passes it through the same filter first, and a rule that pre-selects a
service for a Package in carrier packaging falls through to shopping rather than buying on a
rate that was never quoted for that packaging.

**Physical-form filtering stays inside the adapters.** USPS cubic box rates and cubic soft-pack rates are both
`shipperPackaging()` — the shared filter cannot and should not tell them apart — so
`isValidRateIndicator()` keeps choosing tiers from `boxType` exactly as it does today, and
`BOX_RATE_INDICATORS` / `SOFT_PACK_RATE_INDICATORS` stay where they are. Putting a form
requirement on the rate would be a second axis on `RateResponse` that nothing outside USPS
needs yet. What the adapters *stop* doing is excluding carrier-packaging services: the
flat-rate `rateIndicator`s USPS discards today become `exactly(…)` requirements and survive
to the shared filter, and `isOneRateEligible()` reads `carrierPackaging` in place of
`fedexPackageType` but is otherwise unchanged.

**5. An Amazon offer's requirement is read off its serviceId.** The pattern list that
`amazon-buy-shipping/12` ships as a *filter* becomes the *classifier* that sets the
`packagingRequirement` on each Amazon rate; the filter in decision 4 then does the dropping.
Nothing else about `12` changes shape. Whether that classification should later become
editable data on the *Map Carrier Services* page (`amazon-buy-shipping/05`), pre-filled from
the pattern and overridable per observed service, is a follow-on; the pattern list is
sufficient for every serviceId Amazon has returned to date.

**What this buys immediately.** A `BoxSize` declared as `UspsMediumFlatRateBox` makes
Amazon's `USPS_PTP_PRI_MFRB` offer buyable for a parcel scanned into it, with **no direct
USPS adapter work** — Amazon already rates it; the Amazon classifier is the only code
between the declaration and the offer. Rating that same box through the direct USPS account needs `UspsAdapter` to send a
flat-rate `processingCategory` and keep the flat-rate `rateIndicator` it discards today,
and is deferred.

### Terminology

**Packaging** — what a Package is enclosed in, described on two axes: its physical form
(box, polybag, padded mailer) and, when it is not the packer's own, the carrier-supplied
identity it carries (a Priority Mail Small Flat Rate Box, a FedEx Pak). A property of the
**Box Size**. *Avoid*: package type (FedEx's word, and ambiguous with Package), packaging
type (UPS's word).

**Carrier-supplied packaging** — packaging the carrier provides and prices specifically. A
Box Size with a `carrier_packaging` value. A rate that *requires* one is valid in nothing
else.

Added to `CONTEXT.md` in the implementing slice.

### Foreseen, not decided

**Letters and flats are physical forms.** A shipper's envelope is not carrier-supplied
packaging; if letters and flats are ever wanted they are `BoxSizeType` cases (`LETTER`,
`FLAT`), and `carrier_packaging` stays null for them. They bring their own problems — a
letter must be under ¼" thick and flexible, a flat under ¾", neither is captured by scanning
a box code and reading a scale, letters carry no tracking, and the indicia is not a 4×6
label — and the `processingCategory` mapping in `UspsAdapter` would gain two rows. Nobody
has asked, and Ground Advantage under 1 lb covers most of what they would be used for.

**Oversize** stays derived from `length`, `width`, `height`, weight and each carrier's
rules. Nothing here adds a flag for it and nothing should.

**Nonstandard handling** is not the same thing and is not fully derivable from
measurements. Irregular shape, exposed contents, cylindrical packaging and the like can
overlap any physical form and any carrier identity, and nothing on `BoxSize` represents
them today. If one is ever needed it is a third, independent axis beside form and carrier
identity. Reserved, not designed; no column, no enum.

**Mail content classes** — Media Mail, Bound Printed Matter — are a per-product declaration
by the party liable for it (misdeclared Media Mail is a postal offence), importable through
a `DataSource` field mapping, never inferred; a Package qualifies only when every item does;
and automated selection must not choose one on price alone. That is a later ADR. Until it
exists the identifier denylist in `12` and the mail-class drop in `UspsAdapter` stay.

**Seeding carrier packaging as box sizes.** USPS publishes the dimensions of its flat-rate
packaging and the services ignore weight to 70 lb, so a seeder could create them with
`materials_cost = 0`. `BoxSize` has no location scope, so there is no per-location choice to
offer and no packaging inventory to model; whether seeding is global, opt-in, or left to
operators creating rows by hand is for the implementing issue.

**Regional Rate boxes, Priority Mail Express boxes, UPS and FedEx tubes.** More
`CarrierPackaging` cases when a customer stocks them. The enum is closed by design and open
to additions.

**Manual Ship.** A Manual Ship Package has no Box Size, so `carrierPackaging` is null and it
is treated as the packer's own packaging of unknown form: never offered a carrier-packaging
rate, still offered both cubic tier tables as today. A packaging picker on that page is a
small follow-on.

**International variants.** Amazon's `_INTL` and `_CUSTOMS` flat-rate serviceIds are the same
packaging with a different destination, and destination is already a filter. They classify
to the same `CarrierPackaging` value.

## Options considered

**Leave the rules in the adapters.** The status quo, extended by `12`. Rejected because it is
already three lists in two vocabularies with a fourth arriving, and none can ever be turned
into support for the service it excludes.

**One enum: extend `BoxSizeType` with the carrier packagings.** Simpler schema. Rejected
because it conflates form with supplier. Cubic tiers key on form — a USPS *padded* flat-rate
envelope is a soft pack and a flat-rate box is a box — and a single enum would need every
carrier case to also carry a form, or the cubic code would need to know each carrier case.

**Keep `fedex_package_type` and add `usps_packaging`, `ups_packaging`.** One column per
carrier. Rejected: a box size is one physical thing, and a packer holding a FedEx Pak is not
holding a USPS envelope. Three nullable columns invite a row that claims to be both.

**`accepted_packaging` on `CarrierService`.** The first draft. Rejected under review:
ADR-0003 aliases several Amazon services with different packaging to one canonical row, so
the row cannot answer; and a null-or-list column cannot express a service sold in both the
shipper's and the carrier's packaging. Putting the requirement on the rate resolves both,
because the rate is the one identity every source agrees on and every adapter can classify.

**A constraint object on `getRates()` instead of a service-code list** — the open question
in `amazon-buy-shipping/08`. This decision answers it without changing the signature:
packaging is a fact on `PackageData`, adapters shape their request from it where the API
takes it, and a shared post-quote filter enforces it everywhere. `08` can close as "leave
the signature; the constraint travels on the package".

**Decide content classes here.** The first draft. Rejected as scope: they are contents, not
packaging, and carry a policy question (automated selection) and a product-attribute
question that packaging does not. Separated so that this decision can be accepted on the
evidence it has.

## Trade-off

One enum, one column, one field on each of two DTOs plus a small value object, and a data
migration for `fedex_package_type`,
to replace three private constant arrays. The constants were free; the column is truth an
operator can see. What is given up is adding a new carrier packaging without a code change —
the enum is closed so that every adapter mapping is exhaustive under PHPStan, which is worth
more than an operator typing a packaging name no adapter understands.

The real cost is deferred rather than avoided: rating a declared USPS flat-rate box through
the *direct* USPS account means changing a working filter in the busiest adapter, keeping
rates it discards today, and it wants `carrier-request-schema-validation`'s USPS schema in
place first. This decision makes that a mapping change rather than a design change, and
leaves it for its own issue.

## Consequences

- The Ship page stops offering a service the parcel cannot legally travel under, on every
  postage source, for the same reason on each.
- A warehouse that stocks USPS flat-rate packaging can declare it and buy the matching
  offers through Amazon at once; buying them on the direct account follows when the USPS
  mapping lands.
- `UspsAdapter::isValidRateIndicator()` keeps its form filtering and additionally classifies
  the flat-rate indicators it discards today as `exactly(…)` requirements; `isValidRate()`
  keeps its mail-class and processing-category drops until the content-class ADR and the
  letter/flat forms respectively remove them.
- `amazon-buy-shipping/12`'s pattern list stops dropping rates and starts classifying them.
  It does not go away.
- `PackageData` and `RateResponse` change shape; every carrier adapter test that builds
  either is touched. The `->shipped()` factory state and the rate fixtures are the bulk of it.
- `PackagingRequirement` must round-trip through `RateResponse::toArray()` / `fromArray()`.
  Rates cross Livewire state on the Ship page through that explicit serialization, and a
  requirement that does not survive it would let a rate chosen from the page be bought
  without the check that hid its siblings.
- `FedexAdapter::isOneRateEligible()` reads `carrierPackaging` instead of `fedexPackageType`
  and is otherwise unchanged.
- `CarrierAdapterInterface::resolvePreSelectedRate()` changes return type to `?RateResponse`;
  five implementations and `selectedRateForAutoShip()` change with it. A rule that
  pre-selects a service the Package's packaging cannot use now rate-shops instead of buying,
  which is a behaviour change worth a line in the release notes.
- A `BoxSize` with a `carrier_packaging` set is only useful for a carrier whose adapter or
  reseller can rate it; nothing prevents declaring a FedEx Pak on a UPS-only account, and the
  outcome is simply no matching rates. The Box Size form can say so.

## Implementation

Not yet sliced. When this ADR is accepted, issues go under
`docs/issues/packaging-form-and-carrier-identity/`, ordered so that `RateResponse` and the
shared filter ship first, wired at both sites, with every adapter returning
`shipperPackaging()` (no behaviour change), then the `BoxSize` column and the FedEx migration, then the Amazon classifier
replacing `12`'s filter, and last the direct USPS mapping. This document records the decision
and why; it is not a checklist and does not get updated as work lands.
