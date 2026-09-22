# `PackagingRequirement` on every rate, and the shared filter at rate shopping

Status: done — shipped 2026-09-15; every rate says `shipperPackaging()` and every Package is null, so nothing visible changed

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
requirement. ADR-0003 keeps it out of rate comparison; explicitly configured blind-purchase automation validates eligibility through its separate offer path.

**Not applied inside `resolvePreSelectedRate()` here.** That is `02`, because it changes
the method's return type.

### Close `amazon-buy-shipping/08`

Its last comment says it closes when the first implementing slice of ADR-0005 lands. Add
the dated comment, set `Status: done`, and update the `amazon-buy-shipping` row in
`docs/issues/README.md`.

## Acceptance criteria

- [x] `CarrierPackaging` and `PackagingRequirement` exist with unit tests for `accepts()`
      across all three constructors, including `anyOf()` rejecting null and
      `shipperPackaging()` rejecting every case
- [x] `RateResponse` round-trips the requirement through `toArray()`/`fromArray()`, with a
      test for each constructor kind and one for a legacy array with no key
- [x] A `ShippingOffer` written from a rate and read back through `rateFromOffer()` carries
      the same requirement, tested for `exactly()` and `anyOf()`
- [x] The purchase path refuses a rate whose requirement does not accept the Package's
      packaging, tested by handing it a hand-built `exactly(…)` rate against a Package with
      no carrier packaging
- [x] `ShippingRateService::getShippingRates()` runs the filter; a unit test with a fake
      adapter returning one `shipperPackaging()` and one `exactly(…)` rate for a plain
      Package gets only the first back, and the second is absent from the quote log
- [x] Every adapter listed above sets `packagingRequirement` explicitly; PHPStan and the
      existing adapter suites pass unchanged
- [x] `amazon-buy-shipping/08` is `done` with a comment pointing here
- [x] `vendor/bin/pint --dirty --format agent` clean

## Blocked by

None — can start immediately.

## Comments

### 2026-09-15 — triaged `ready-for-agent`

> *This was generated by AI during triage.*

### 2026-09-15 — done

> *This was generated by AI during triage.*

What landed, and where:

- `App\Enums\CarrierPackaging` — 27 cases (9 USPS including the three Express envelopes,
  11 FedEx, 7 UPS), snake_case values, `HasLabel`, `carrier(): string`.
- `App\DataTransferObjects\Shipping\PackagingRequirement` — readonly; the three constructors,
  `accepts()`, `isShipperPackaging()`, `describe()` for the refusal message, `toArray()` /
  `fromArray()` as `{kind, packagings}`, and `fromRateMetadata()` reading
  `RATE_METADATA_KEY` (`packagingRequirement`) with a missing key as `shipperPackaging()`.
- `RateResponse::$packagingRequirement` — a nullable constructor parameter resolved to
  `shipperPackaging()` in the body (a private-constructor value object cannot be a parameter
  default), carried by `toArray()` / `fromArray()`.
- `PackageData::$carrierPackaging` after `boxType`, always null from `fromPackage()`;
  `fedexPackageType` untouched.
- Every adapter sets it explicitly: `UspsAdapter`, both `FedexAdapter` sites (the One Rate
  site carries a comment saying it is honestly `exactly(…)` and why it is not yet),
  `UpsAdapter`, `AmazonBuyShippingAdapter`, `FakeCarrierAdapter`,
  `FedexSandboxInternationalRates`, `RuleEvaluator`'s synthetic rate, and
  `rateFromOffer()`.
- Offer round-trip: the Amazon adapter writes the requirement into
  `OfferDraft::rateMetadata` under the key; `rateFromOffer()` restores it with
  `fromRateMetadata()` and stays private. No migration.
- Purchase re-check: `EloquentPackageShippingWorkflow::packagingRefused()`, run in
  `buyPostage()` after the offer's rate is rebuilt and before `markSelected()`, reading the
  packaging through `PackageData::fromPackage()`. Refuses with the new
  `PackageShippingResult::packagingMismatch()` — package left intact, `requiresRequote`
  true, message naming both the required packaging and the package's. *Amended
  2026-09-16 — see the next comment: the requirement it checks now comes from the
  adapter, not the rate.*
- The shared filter is `App\Services\Shipping\PackagingFilter::keepCompatible()`, applied in
  `ShippingRateService::getShippingRates()` between `fetchRatesConcurrently()` and
  `RateQuoteLogger::logRates()`.

Tests: `tests/Unit/Enums/CarrierPackagingTest.php`,
`tests/Unit/DataTransferObjects/PackagingRequirementTest.php`,
`tests/Unit/Services/Shipping/PackagingFilterTest.php`; `RateResponseTest` gained the
per-kind and legacy-array round-trips; `OfferRedemptionOnShipTest` proves the offer's
requirement, not the browser's, is what the purchase checks (an `exactly()` / `anyOf()`
offer is refused naming the packaging while the browser copy said shipper packaging);
`PackageShippingWorkflowTest` refuses a hand-built `exactly(…)` rate;
`ShippingRateServiceTest` shows the `exactly(…)` rate dropped and absent from `rate_quotes`;
`AmazonBuyShippingTest` shows the key on the issued offer. Full suite green; PHPStan and
Pint clean.

For `02` and `03`: read the Package's packaging at the pre-selection site the way rate
shopping and the purchase re-check already do — `PackageData::fromPackage($package)->carrierPackaging`
— so that `03` wiring `fromPackage()` lights up all three sites together.
The FedEx One Rate comment is the one place where a rate is knowingly mislabelled
`shipperPackaging()` until `03` — `03` should flip it to `exactly(…)` in the same change that
adds the column, or a Package in FedEx packaging loses One Rate at the filter.

## Agent Brief

**Category:** enhancement
**Summary:** Give every `RateResponse` a `PackagingRequirement`, carry a nullable
`CarrierPackaging` on `PackageData`, and filter rates by requirement at rate shopping —
shipped with every rate saying `shipperPackaging()` and every Package null, so nothing
observable changes.

**Current behavior:**
A rate has no way to say which packaging it is valid in. `PackageData` carries `boxType`
(physical form) and `fedexPackageType` (FedEx's own enum). Three adapters exclude
carrier-packaging services through private constant lists in three vocabularies; nothing
shared enforces anything. The body of this issue and ADR-0005 decisions 3 and 4 are the
specification; this brief only pins down the parts an agent working alone could get wrong.

**Desired behavior:**
As the body says. Two clarifications, found when verifying the body against the code:

1. **The purchase re-check reads the packaging through `PackageData`, not a column.** The
   body writes it as `accepts($package->boxSize?->carrier_packaging)`; that column does
   not exist until `03`. In this slice the purchase path re-checks
   `$rate->packagingRequirement->accepts(PackageData::fromPackage($package)->carrierPackaging)`,
   which is always null here. `03` then wires `fromPackage()` and the purchase check
   follows for free. Do not add the column, and do not hard-code `null` at the purchase
   site.

2. **The offer round-trip is Amazon-only today, and `rateFromOffer()` is private.** Only
   the Amazon adapter issues a `ShippingOffer` (through `OfferStore::issue()` from an
   `OfferDraft`). Store the requirement as a key inside `OfferDraft::rateMetadata`, so the
   `shipping_offers` table needs no migration. Two acceptable ways to test the round-trip:
   drive the purchase workflow with an issued offer whose metadata carries an `exactly()`
   / `anyOf()` requirement and assert on the rate the adapter receives; or put the
   encode/decode pair on `PackagingRequirement` (`toArray()`/`fromArray()` already exist
   for the Livewire round-trip — reuse them under the metadata key) and test that pair
   plus one workflow test. Do not make `rateFromOffer()` public to test it.

Also settled by looking at the code:

- `ShopifyAdapter` builds no `RateResponse` (blind purchase, ADR-0003), so it is correctly
  absent from the list of adapters that must set the requirement explicitly.
- The filter goes between `fetchRatesConcurrently()` and `RateQuoteLogger::logRates()` in
  `ShippingRateService::getShippingRates()`, exactly as the body says.
- Enum cases are PascalCase (`UspsFlatRateEnvelope`), matching the majority convention in
  `App\Enums`; `HasLabel` with a `getLabel()` match, as `BoxSizeType` does.
- The default `packagingRequirement` argument on `RateResponse` is what keeps the ~76
  `new RateResponse(` sites in tests compiling; the acceptance criterion that every
  *adapter* sets it explicitly is a review check, not something the type system enforces.

**Key interfaces:**
- `App\Enums\CarrierPackaging` — new, string-backed, closed, `HasLabel`, with
  `carrier(): string`. Case list in the body.
- `App\DataTransferObjects\Shipping\PackagingRequirement` — new, readonly;
  `shipperPackaging()`, `exactly()`, `anyOf(...)`, `accepts(?CarrierPackaging): bool`,
  `toArray()`/`fromArray()`. `anyOf()` with no cases throws.
- `RateResponse` — gains `packagingRequirement` (defaults to `shipperPackaging()`);
  `toArray()`/`fromArray()` carry it; a missing key on `fromArray()` reads as
  `shipperPackaging()`.
- `PackageData` — gains `?CarrierPackaging $carrierPackaging` after `boxType`, always null
  from `fromPackage()` in this slice; `fedexPackageType` stays.
- `OfferDraft::rateMetadata` — carries the requirement under a named key;
  `rateFromOffer()` restores it.
- The shared filter — one collection operation, `keepCompatible(Collection, ?CarrierPackaging): Collection`,
  either a `PackagingFilter` class beside `RateSelector` or a static on
  `PackagingRequirement`. Carrier-identity axis only; knows nothing of `BoxSizeType`.
- `CarrierAdapterInterface::resolvePreSelectedRate()` — **unchanged** here; `02` owns it.
- `CarrierAdapterInterface::packagingRequirementFor(RateResponse): PackagingRequirement` —
  added on review (see the 2026-09-16 comment): the adapter classifies a rate from the
  metadata it will send, and the purchase re-check asks this rather than reading the
  browser-carried `RateResponse::$packagingRequirement`.

**Acceptance criteria:** the eight in the body, unchanged, plus:
- [x] The purchase re-check reads packaging via `PackageData::fromPackage()`, and no
      reference to a `carrier_packaging` column exists after this slice
- [x] `rateFromOffer()` stays private
- [x] The purchase re-check derives the requirement from the adapter, not from the
      browser-carried field (added on review, 2026-09-16)

**Out of scope:**
- Filtering inside `resolvePreSelectedRate()` or changing its return type (`02`)
- `box_sizes.carrier_packaging`, the `fedex_package_type` migration, any `BoxSize` form
  change, or reading anything real into `PackageData::carrierPackaging` (`03`)
- Any adapter returning anything other than `shipperPackaging()` (`04`, `05`, `07`)
- `blindPurchaseOffersFor()` and the Shopify blind-purchase path
- `CONTEXT.md` terminology additions (the ADR assigns them to "the implementing slice";
  do them here only if trivial — the `03` slice, which adds the column an operator sees,
  is the natural home)

### 2026-09-16 — review finding: the purchase re-check trusted a browser-carried field

> *This was generated by AI during triage.*

Code review found a P2 in the shape above. `packagingRefused()` read
`$rate->packagingRequirement`, and for a direct-carrier rate — one with no `ShippingOffer`
— that field is rebuilt from Livewire state by `RateResponse::fromArray()`. Once `05` makes
USPS emit `exactly(…)`, a modified request could restate it as `shipperPackaging()` and walk
past the check. Not a new hole (the browser already restates `metadata['rateIndicator']`
and friends for direct rates, and the adapter sends them), but a check must not claim
authority it does not have.

**Fix, chosen by the maintainer: rederive from the adapter at purchase time.**

- `CarrierAdapterInterface` gained `packagingRequirementFor(RateResponse $rate): PackagingRequirement`
  (on the quoting interface, not `PostageOfferSource` — a blind source never holds a
  `RateResponse`). The docblock cites ADR-0005 decision 3: the adapter that produced the
  rate is the only party that knows, and it classifies from the same fields it will send.
- Every implementation has it: `UspsAdapter`, `FedexAdapter` and `UpsAdapter` each route
  it through a private `classifyPackaging(array $metadata)` that the `getRates()` site
  *also* calls to stamp `packagingRequirement:` — so quote time and purchase time share one
  function. `AmazonBuyShippingAdapter` does the same with `classifyPackaging(string $serviceId)`,
  called from `ratesFrom()` with the serviceId and from `packagingRequirementFor()` with
  `metadata['amazonServiceId']`, which the workflow rebuilt from the stored offer. All
  return `shipperPackaging()` in this slice; the FedEx One Rate caveat moved into the
  classifier's docblock. `FakeCarrierAdapter` and every test double return
  `shipperPackaging()` directly. `FedexSandboxInternationalRates` is not an adapter and
  still stamps the field itself.
- `packagingRefused()` now resolves the seller through the existing `sellerFor()` and,
  when it is a `CarrierAdapterInterface`, checks
  `$seller->packagingRequirementFor($selectedRate)->accepts(PackageData::fromPackage($package)->carrierPackaging)`.
  A null or non-quoting seller is left to `unsupportedDispatch()`. Message and
  `packagingMismatch()` unchanged, using the rederived requirement's `describe()`.
- The stamped `RateResponse::$packagingRequirement` is now for display and the
  rate-shopping filter only. The offer round-trip key in `rate_metadata` still travels and
  the tests still prove it survives, but **the purchase check no longer depends on it**: it
  depends on the adapter classifying the server-rebuilt rate, which for Amazon reads the
  serviceId out of that same stored metadata.

Tests: `PackageShippingWorkflowTest` — the existing refusal now comes from the mock
adapter's `packagingRequirementFor()` returning `exactly(…)`, and a new test hands the
workflow a browser rate stamped `shipperPackaging()` while the adapter classifies it
`exactly(…)`: refused, `createShipment()` never called. `OfferRedemptionOnShipTest`'s
round-trip tests keep their meaning with a mock that classifies from the rate's metadata
the way Amazon does. `AmazonBuyShippingTest` asserts `packagingRequirementFor()` on a
quoted rate equals what was stamped. Every Mockery adapter that reaches a purchase gained a
`packagingRequirementFor` expectation; the anonymous `DirectCarrierAdapter` doubles in the
tracking, registry and Filament tests gained the method. Full suite green; PHPStan and Pint
clean.

For `02`, `04` and `05`: `classifyPackaging()` is the hook. `04` fills in Amazon's from
`fitsThePackaging()`'s pattern list; `05` fills in USPS's from `rateIndicator`; both then
get the purchase re-check for free, and `02`'s pre-selection filter reads the stamped field
the adapter set from the same function.

### 2026-09-16 — second review finding: consistency, not authority; follow-up filed

> *This was generated by AI during triage.*

Review returned on the fix above: `packagingRequirementFor()` classifies from rate
metadata, and for a direct-carrier rate that metadata is browser state as much as the
requirement was, so the check still establishes no server authority.

True, and not a bypass. The classifier reads exactly the fields the ship body sends —
USPS's `rateIndicator`, FedEx's `isOneRate` — so tampering produces one of two outcomes:
metadata that classifies as carrier packaging is refused for a Package that is not in it,
and metadata that classifies as the shipper's packaging asks the carrier for a
shipper-packaging label, which is a legitimate purchase. No metadata can classify one way
and buy the other, because both read the same bytes. What the check is, is *consistent*;
what it is not is *authoritative*, and the reason is broader than packaging: a direct
rate's carrier, service, price and metadata are all restated by the browser, and were
before this issue existed. The invariant the consistency depends on — read only what the
ship body sends; never let an unrecognised indicator fall through to `shipperPackaging()`
— is now on the interface docblock, and is `05`'s to honour when USPS's classifier
becomes real.

The reviewer's remedy — restore the quoted rate server-side behind an opaque identifier,
as `rateFromOffer()` already does for an offer — is an extension of ADR-0002's offer model
to direct carriers, and is filed as
[`postage-source-split/14`](../../postage-source-split/issues/14-quote-direct-carrier-rates-behind-an-opaque-identifier.md),
`needs-triage`. Not a blocker for this slice; worth landing before or alongside `05`.
