# Amazon's pattern list classifies instead of filters

Status: needs-triage

Repo: `polybag`

## Parent

[ADR-0005](../../../adr/0005-packaging-form-and-carrier-identity.md), decision 5, and
"What this buys immediately".

## What to build

The first thing an operator can *buy* because of this ADR. A box size declared
`UspsMediumFlatRateBox` makes Amazon's `USPS_PTP_PRI_MFRB` offer buyable for a parcel
scanned into it, with no direct USPS adapter work — Amazon already rates it, and this
classifier is the only code between the declaration and the offer.

### The classifier

`AmazonBuyShippingAdapter::fitsThePackaging()` rules 1 and 2 (`amazon-buy-shipping/12`)
become `packagingRequirementFor(string $serviceId): PackagingRequirement`, called where
the adapter builds each `RateResponse` and, one step earlier, inside `isBuyable()` (see
"The offer store" below):

| serviceId matches | Requirement |
|---|---|
| `_PRI_FRE` / `_PRI_LFRE` / `_PRI_PFRE` | `exactly(UspsFlatRateEnvelope / UspsLegalFlatRateEnvelope / UspsPaddedFlatRateEnvelope)` |
| `_EXP_FRE` / `_EXP_LFRE` / `_EXP_PFRE` | `exactly(UspsExpressFlatRateEnvelope / UspsExpressLegalFlatRateEnvelope / UspsExpressPaddedFlatRateEnvelope)` |
| `_SFRB` / `_MFRB` / `_LFRB` | `exactly(UspsSmall/Medium/LargeFlatRateBox)` |
| `_ONE_RATE` | `anyOf(FedexEnvelope, FedexPak, FedexTube, FedexBox, FedexExtraSmallBox … FedexExtraLargeBox, Fedex10kgBox, Fedex25kgBox)` |
| anything else | `shipperPackaging()` |

Same segment-anchored matching as `12` (`_INTL` and `_CUSTOMS` variants classify to the
same packaging; `USPS_PTP_FC` does not match), now also reading the mail-class segment
before the envelope token: an Express envelope is its own packaging, and a Priority Mail
`_FRE` offer must not be buyable for a parcel in one, nor the reverse — a packer uses the
service printed on the envelope. The flat-rate boxes are Priority Mail only.

### The offer store, and why the adapter still filters

`ratesFrom()` issues a `ShippingOffer` — an expiring purchase-authority row, ADR-0002
decision 4 — for every rate that survives `isBuyable()`, inside the same `map()` that
builds the `RateResponse`. That happens before `ShippingRateService` sees the collection.
If the adapter stamped the requirement and left the dropping to `01`'s filter, every
incompatible envelope, box and One Rate offer — thirteen of thirty-five on the live run —
would get an offer row for a rate nobody is shown, breaking the adapter's invariant that
hidden rates hold no purchase authority and growing the table for nothing.

So `isBuyable()` keeps a packaging predicate, but it is no longer a list of its own: it is
`packagingRequirementFor($serviceId)->accepts($package?->carrierPackaging)` — the same
`accepts()` the shared filter runs, applied one step earlier because this adapter has a
side effect at that step. `01`'s filter in `ShippingRateService` still runs on the
Amazon rates and is a no-op for them; it is the sole line of defence for the adapters
that have no side effect. This is "one filter, shared" in the sense the ADR means — one
predicate, one vocabulary — and the docblock on `isBuyable()` says why it is called from
two places.

`isBuyable()` otherwise keeps `hasPrintableDocument()`, `honoursRequiredServices()`,
`answersRequiredGroupsForFree()` and **rule 3** — the `CONTENT_RESTRICTED_SERVICES` drop
for Media Mail and Bound Printed Matter, which outlives this ADR.

Observations are still recorded before any of this, for every rate. That ordering is
documented on `ratesFrom()` and must not change.

## Acceptance criteria

- [ ] Every `AmazonBuyShippingTest` case from `12` still passes with its expectation
      restated against the requirement: a plain box still sees no flat-rate or One Rate
      offers, `USPS_PTP_FC` / `_PRI` / `_PRI_CUBIC` / `_GAH` are still `shipperPackaging()`,
      Media Mail and BPM are still dropped, the observation for a dropped offer is still
      recorded
- [ ] New: a Package in a `UspsMediumFlatRateBox` box size gets `USPS_PTP_PRI_MFRB` back
      from `ShippingRateService` and **nothing else** from the Amazon fixture — not the
      envelope offers, not the shipper-packaging ones
- [ ] New: a Package in a `UspsExpressFlatRateEnvelope` box size gets `USPS_PTP_EXP_FRE`
      and not `USPS_PTP_PRI_FRE`; a Package in a `UspsFlatRateEnvelope` box size the reverse
- [ ] New: a Package in a `FedexPak` box size gets the `_ONE_RATE` offers and no others
- [ ] New, **at the adapter, not through `ShippingRateService`**: call
      `AmazonBuyShippingAdapter::getRates()` directly for a plain box against a fixture
      carrying `USPS_PTP_PRI_FRE`, `USPS_PTP_PRI_MFRB`, a `_ONE_RATE` id and `USPS_PTP_GAH`;
      assert the `shipping_offers` count equals the number of rates returned (one), that
      no row names a dropped serviceId, and that an `ObservedService` row exists for each
      dropped one. Then the same for a `UspsMediumFlatRateBox` box: exactly one offer row,
      and it is the `MFRB` one. An implementation that filters only in
      `ShippingRateService` passes every other criterion here and fails this one
- [ ] New: the `MFRB` rate survives the Livewire round-trip and the offer store with its
      `exactly()` intact, and the purchase path accepts it for that Package and refuses it
      for a Package with no carrier packaging (`01`'s check, now reachable)
- [ ] `12`'s docblock and its `## Comments` gain a line saying rules 1–2 moved here
- [ ] `vendor/bin/pint --dirty --format agent` clean

## Blocked by

- [`02`](02-pre-selection-filters-before-it-chooses.md) — without it, a rule pre-selecting
  a service Amazon resells would bypass the filter
- [`03`](03-box-size-carrier-packaging-replaces-fedex-package-type.md) — the column the
  Package's packaging is read from
