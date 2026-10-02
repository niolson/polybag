# Packaging form and carrier identity

Status: reference

Implements ADR-0005 (`docs/adr/0005-packaging-form-and-carrier-identity.md`). Background
for the issues in this directory; not a work item.

## The problem in one scenario

A packer scans a parcel into the warehouse's own 4×6×6 box and opens the Ship page. Through
Amazon Buy Shipping it is offered thirteen services the parcel cannot legally travel under —
USPS flat-rate envelopes and boxes, FedEx One Rate — because Amazon rates on dimensions
alone. The direct USPS and FedEx adapters each stop this with a private list in their own
carrier's vocabulary; `amazon-buy-shipping/12` added a third. None of the three can be
turned around: nothing in the app can say a box size *is* a USPS Medium Flat Rate Box, so a
warehouse that stocks them cannot buy the matching offer from any source.

## What changes

A `BoxSize` has two independent axes: its physical form (`type`, unchanged, still drives
USPS cubic tiers) and a nullable carrier-supplied identity (`carrier_packaging`, a closed
`CarrierPackaging` enum). Every rate an adapter returns declares the packaging it requires
— `shipperPackaging()`, `exactly(…)` or `anyOf(…)` — in a `PackagingRequirement` on
`RateResponse`, set by the adapter that produced it because it is the only party that
knows. One shared filter keeps a rate only when its requirement accepts the Package's
carrier packaging, and runs everywhere adapter rates are collected: after rate shopping in
`ShippingRateService`, and inside every `resolvePreSelectedRate()` before a variant is
chosen — which is why that method learns to return null and the auto-ship path learns to
fall through to shopping. Physical-form filtering stays inside the adapters. Amazon's
pattern list stops being a list of its own and becomes the classifier; the adapter still
applies the shared predicate before it issues an offer, because an offer is purchase
authority and a hidden rate must hold none. ADR-0005 decisions 1–5 say which is which and
why.

Two refinements made when slicing, both folded into the ADR under a dated amendment note:
FedEx stamps the packaging it *sent* on every rate — the rule the ADR already gave UPS —
rather than only on One Rate rates, because the weight-based rates for a parcel in a
FedEx Pak are real rates for that packaging and the narrower reading would drop them
(`03`); and Priority Mail Express flat-rate envelopes are their own enum cases (`01`).

## Sequence

| # | Slice | Status | Depends on |
|---|---|---|---|
| [`01`](issues/01-packaging-requirement-on-every-rate-and-the-shared-filter.md) | `CarrierPackaging` enum, `PackagingRequirement`, the field on `RateResponse` and its round-trips, `carrierPackaging` on `PackageData` (always null for now), the shared filter at the rate-shopping site, every adapter stamping `shipperPackaging()`. **No behaviour change.** Closes `amazon-buy-shipping/08` | needs-triage | nothing |
| [`02`](issues/02-pre-selection-filters-before-it-chooses.md) | `resolvePreSelectedRate()` returns `?RateResponse`, filters before choosing a variant, and `selectedRateForAutoShip()` falls through to rate shopping on null | needs-triage | `01` |
| [`03`](issues/03-box-size-carrier-packaging-replaces-fedex-package-type.md) | `box_sizes.carrier_packaging` with a lossless data migration from `fedex_package_type`, the two forms, `PackageData::fromPackage()`, FedEx mapping at the request boundary, `CONTEXT.md` terms | needs-triage | `01` |
| [`04`](issues/04-amazon-pattern-list-classifies-instead-of-filters.md) | `fitsThePackaging()` rules 1–2 become the classifier that stamps each Amazon rate's requirement; rule 3 stays a drop. The first thing an operator can *buy* because of this ADR | needs-triage | `02`, `03` |
| [`05`](issues/05-direct-usps-rates-a-declared-flat-rate-packaging.md) | `UspsAdapter` keeps the flat-rate `rateIndicator`s it discards today as `exactly(…)` requirements, so a declared flat-rate box rates and buys on the direct account | ready-for-human | `02`, `03`, USPS sandbox access |
| [`06`](issues/06-seed-usps-flat-rate-packaging-as-box-sizes.md) | Whether USPS flat-rate packaging is seeded as Box Sizes — global, opt-in, or left to operators | needs-triage | `03` |
| [`07`](issues/07-direct-ups-rates-a-declared-ups-packaging.md) | `UpsAdapter` sends `PackagingType` from the Box Size's UPS packaging and stamps `exactly(…)`, so a UPS Letter, Pak, Tube or Express Box rates and buys on the direct account. Optional — when a customer stocks them | needs-triage | `02`, `03` |

`01` and `02` are the plumbing, and ship with every rate saying `shipperPackaging()` so
nothing a user sees changes; `02` is separate because it changes an interface across five
adapters and carries the one behaviour change worth a release-note line. `03` is the
schema. `04` is the payoff the ADR promises "with no direct USPS adapter work". `05` is the
cost the ADR says is deferred rather than avoided — a working filter in the busiest adapter
changes what it keeps. `06` is a decision, not a step. `07` is the UPS twin of `05`, not
scheduled by the ADR and left until someone stocks UPS packaging; it is here so the enum's
UPS cases have an owner.

Priority Mail Express flat-rate envelopes are their own `CarrierPackaging` cases because
a packer holding one uses the service printed on it.

## Explicitly not in scope

- **Letters and flats.** Physical forms, not carrier identity; `BoxSizeType` cases if ever
  wanted. Nobody has asked.
- **Mail content classes** — Media Mail, Bound Printed Matter. A per-product declaration
  and an automation-policy question; a later ADR. Until then the identifier denylist in
  `amazon-buy-shipping/12` (rule 3) and the mail-class drop in `UspsAdapter::isValidRate()`
  stay.
- **Nonstandard handling and oversize.** No column, no enum. Oversize stays derived from
  measurements.
- **A packaging picker on Manual Ship.** A Manual Ship Package has no Box Size, so it is the
  packer's own packaging of unknown form: never offered a carrier-packaging rate, still
  offered both cubic tier tables. A small follow-on when someone wants it.
- **Making the Amazon classification editable on *Map Carrier Services*.** The pattern list
  covers every serviceId Amazon has returned to date.

## Comments
