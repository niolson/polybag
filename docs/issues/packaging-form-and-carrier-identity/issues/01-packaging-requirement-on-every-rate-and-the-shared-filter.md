# `PackagingRequirement` on every rate, and the shared filter at rate shopping

Status: needs-triage

Repo: `polybag`

## Parent

[ADR-0005](../../../adr/0005-packaging-form-and-carrier-identity.md), decisions 3 and 4
(the rate-shopping site only; the pre-selection site is `02`).

## What to build

The vocabulary and the plumbing, shipped with **no behaviour change**: every rate says
`shipperPackaging()`, every Package's carrier packaging is null, so the filter keeps
everything. What this slice proves is that the requirement reaches every place a rate
goes — adapter, `ShippingRateService`, Livewire state, the offer store, the purchase —
before anything depends on it.

### `CarrierPackaging` enum

`App\Enums\CarrierPackaging`, string-backed, `HasLabel`, closed. Cases per the ADR:
`UspsFlatRateEnvelope`, `UspsLegalFlatRateEnvelope`, `UspsPaddedFlatRateEnvelope`,
`UspsSmallFlatRateBox`, `UspsMediumFlatRateBox`, `UspsLargeFlatRateBox`,
`UspsExpressFlatRateEnvelope`, `UspsExpressLegalFlatRateEnvelope`,
`UspsExpressPaddedFlatRateEnvelope`; `FedexEnvelope`,
`FedexPak`, `FedexTube`, `FedexBox`, `FedexExtraSmallBox`, `FedexSmallBox`,
`FedexMediumBox`, `FedexLargeBox`, `FedexExtraLargeBox`, `Fedex10kgBox`, `Fedex25kgBox`;
`UpsLetter`, `UpsPak`, `UpsTube`, `UpsExpressBox`, `UpsExpressBoxSmall`,
`UpsExpressBoxMedium`, `UpsExpressBoxLarge`. The FedEx list is every `FedexPackageType`
case except `YOUR_PACKAGING`, including the generic `FEDEX_BOX` and the 10 kg / 25 kg
international boxes, so that `03`'s migration is lossless. The three Express envelope
cases are an addition to the ADR's list, decided when slicing: USPS sells Priority Mail
Express flat-rate envelopes as separate stock from the Priority Mail ones, and a packer
holding one uses the service printed on it — so an Express envelope is not a Priority
Mail envelope, and `exactly(UspsFlatRateEnvelope)` must not accept it. No column yet;
that is `03`.

A `carrier(): string` method (`'USPS'` / `'FedEx'` / `'UPS'`) is worth adding now — `03`'s
form helper text and `04`'s tests both want it.

### `PackagingRequirement`

`App\DataTransferObjects\Shipping\PackagingRequirement`, readonly. Three named
constructors — `shipperPackaging()`, `exactly(CarrierPackaging)`,
`anyOf(CarrierPackaging ...)` — and `accepts(?CarrierPackaging): bool`:
`shipperPackaging()` accepts only null; `exactly()` accepts only that case; `anyOf()`
accepts any listed case and never null. `toArray()` / `fromArray()` for the round-trip
below. `anyOf()` with no cases is a programming error and throws.

### `RateResponse::packagingRequirement`

A constructor parameter defaulting to `shipperPackaging()`, so the ~80 `new RateResponse(`
sites in tests compile unchanged, but **every adapter sets it explicitly** —
`UspsAdapter`, `FedexAdapter`, `UpsAdapter`, `AmazonBuyShippingAdapter`,
`FakeCarrierAdapter`, `FedexSandboxInternationalRates` — as `shipperPackaging()` in this
slice, so that a reader of any adapter sees the decision being made rather than a default
being taken. `RuleEvaluator`'s synthetic pre-selected rate and
`EloquentPackageShippingWorkflow::rateFromOffer()` set it too (see round-trips).

### Round-trips

The ADR's consequence list names the Livewire one; there are three:

1. **`RateResponse::toArray()` / `fromArray()`** — rates cross Livewire state on the Ship
   page through this. A missing key on `fromArray()` reads as `shipperPackaging()`, which is
   the safe direction (it accepts only the packer's own packaging).
2. **`ShippingOffer`** — `rateFromOffer()` rebuilds the rate the purchase runs on from the
   stored offer, not from the browser. The requirement has to be stored with the offer and
   restored from it; a key inside `rate_metadata` is enough, and avoids a migration on a
   table `03` does not otherwise touch. Say which in the PR.
3. **The purchase itself.** A rate reaching `createShipment()` carries the requirement, and
   the purchase path re-checks it against the Package — `accepts($package->boxSize?->carrier_packaging)`
   — and refuses with a message naming the packaging when it does not. Today that check
   can never fail; it exists so that `04` cannot ship without it.

### `PackageData::carrierPackaging`

`?CarrierPackaging`, constructor parameter after `boxType`, **always null in this slice**
— `fromPackage()` does not read anything yet, because there is nothing to read. `03` wires
it. `fedexPackageType` stays until `03` removes it.

### The shared filter

One operation on a collection of rates, in one place — a `PackagingFilter` class under
`app/Services/Shipping/` or a static on `PackagingRequirement`, whichever reads better
next to `RateSelector`. Signature in the shape of
`keepCompatible(Collection<RateResponse> $rates, ?CarrierPackaging $packaging): Collection`.
It enforces the carrier-identity axis only; it knows nothing about `BoxSizeType`.

Applied in `ShippingRateService::getShippingRates()` on the fetched collection, before
`RateQuoteLogger` — a dropped rate was never offered and should not be logged as one.
`blindPurchaseOffersFor()` is untouched: a blind purchase has no rate to carry a
requirement, and ADR-0003 already keeps it out of every automated path.

**Not applied inside `resolvePreSelectedRate()` here.** That is `02`, because it changes
the method's return type.

### Close `amazon-buy-shipping/08`

Its last comment says it closes when the first implementing slice of ADR-0005 lands. Add
the dated comment, set `Status: done`, and update the `amazon-buy-shipping` row in
`docs/issues/README.md`.

## Acceptance criteria

- [ ] `CarrierPackaging` and `PackagingRequirement` exist with unit tests for `accepts()`
      across all three constructors, including `anyOf()` rejecting null and
      `shipperPackaging()` rejecting every case
- [ ] `RateResponse` round-trips the requirement through `toArray()`/`fromArray()`, with a
      test for each constructor kind and one for a legacy array with no key
- [ ] A `ShippingOffer` written from a rate and read back through `rateFromOffer()` carries
      the same requirement, tested for `exactly()` and `anyOf()`
- [ ] The purchase path refuses a rate whose requirement does not accept the Package's
      packaging, tested by handing it a hand-built `exactly(…)` rate against a Package with
      no carrier packaging
- [ ] `ShippingRateService::getShippingRates()` runs the filter; a unit test with a fake
      adapter returning one `shipperPackaging()` and one `exactly(…)` rate for a plain
      Package gets only the first back, and the second is absent from the quote log
- [ ] Every adapter listed above sets `packagingRequirement` explicitly; PHPStan and the
      existing adapter suites pass unchanged
- [ ] `amazon-buy-shipping/08` is `done` with a comment pointing here
- [ ] `vendor/bin/pint --dirty --format agent` clean

## Blocked by

None — can start immediately.
