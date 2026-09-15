# `box_sizes.carrier_packaging` replaces `fedex_package_type`

Status: needs-triage

Repo: `polybag`

## Parent

[ADR-0005](../../../adr/0005-packaging-form-and-carrier-identity.md), decisions 1 and 2,
and the FedEx half of decision 3.

## What to build

The schema and the one adapter that already understood carrier packaging. After this
slice an operator can say a box size *is* a USPS Medium Flat Rate Box; nothing rates it
yet (`04`, `05`), and FedEx One Rate behaves exactly as before for a box size that used
to say `FEDEX_PAK`.

### Migration

Add `carrier_packaging` (nullable string, cast to `CarrierPackaging`) to `box_sizes`;
copy `fedex_package_type` across; drop `fedex_package_type`. The mapping is every
`FedexPackageType` case to its `CarrierPackaging` twin, and `YOUR_PACKAGING` / null to
null. `FEDEX_BOX`, `FEDEX_10KG_BOX` and `FEDEX_25KG_BOX` map to `FedexBox`,
`Fedex10kgBox`, `Fedex25kgBox` — the enum carries them (`01`) precisely so this is
lossless; the migration must not silently null a row. The migration carries its own
frozen mapping array rather than calling into the enums, for the reason
`package-label-history/02` gives. `FedexPackageType` stays as the wire enum FedEx wants;
it stops being something a `BoxSize` stores.

`down()` reverses the FedEx mapping and **refuses to run** — throws, naming the rows —
if any box size carries a USPS or UPS case, because `fedex_package_type` cannot hold one
and a rollback that nulled it would lose an operator's declaration silently. A rollback
after such rows exist is a decision someone makes with the row list in front of them, not
a side effect of `migrate:rollback`.

### Model, factory, seeder

`BoxSize`: `carrier_packaging` fillable and cast; `fedex_package_type` gone from both.
`BoxSizeFactory` gains a `carrierPackaging(CarrierPackaging)` state. `BoxSizeSeeder`
loses the column (its rows are all the packer's own boxes). Whether USPS packaging is
seeded is `06`.

### `PackageData`

`fromPackage()` reads `carrierPackaging: $package->boxSize?->carrier_packaging`.
`fedexPackageType` is removed from the constructor; the three sites that construct
`PackageData` with it in tests move to `carrierPackaging`.

### Forms

`BoxSizeResource` and `SetupWizard` replace the *FedEx Package Type* select with a
*Carrier packaging* select over `CarrierPackaging`, nullable, placeholder "Own packaging",
grouped or labelled by carrier. Helper text says the thing the ADR's last consequence
asks for: a carrier packaging is only useful for a carrier whose account or reseller can
rate it; declaring a FedEx Pak on a UPS-only install simply yields no matching rates.
`SetupWizard::createBoxSizes()` (or whatever writes `'fedex_package_type' => …` at line
~751) writes the new column. Screenshot in the PR.

### `FedexAdapter`

Maps `CarrierPackaging` → `FedexPackageType` in one private method at the request
boundary and nowhere else. A non-FedEx carrier packaging (a USPS flat-rate box) maps to
`YOUR_PACKAGING`: FedEx rates the parcel as customer packaging, and the shared filter
drops every FedEx rate anyway because they say `shipperPackaging()`. That is the correct
outcome and needs a comment saying so.

**What the adapter sends today, and what changes.** The ordinary rate request
(`buildRateApiRequest()`) sends no `packagingType` at all — FedEx defaults it to
`YOUR_PACKAGING` — and the ship body sends `YOUR_PACKAGING` for every rate that is not
One Rate; only the One Rate request (`fetchOneRateRates()`) names the packaging. So for a
box size that says `FEDEX_PAK` today, the weight-based Express Saver rate is quoted and
labelled as customer packaging, and only the One Rate variant is quoted as a Pak.

Classifying those weight-based rates as `shipperPackaging()` — the ADR's wording as first
accepted, "FedEx from whether the rate came back from the One Rate request" — would have the
shared filter drop them for every Package in FedEx packaging, leaving only One Rate. That
is a behaviour change the ADR did not foresee, and the wrong one: FedEx packaging is
shippable at weight-based prices, and a warehouse that stocks Paks would lose those rates.

So decision 3 now says for FedEx what it already said for UPS — **the adapter stamps the
packaging it sent**:

- Both rate requests send `packagingType` mapped from `carrierPackaging` — `YOUR_PACKAGING`
  for null or a non-FedEx case, the FedEx code otherwise. The ship body sends the same
  value for every rate, from a `packagingType` metadata key set on each rate, in place of
  the `isOneRate ? fedexPackageType : YOUR_PACKAGING` branch at ~line 570.
- Every rate from a request that sent a FedEx code — ordinary, Saturday and One Rate —
  carries `exactly($package->carrierPackaging)`; every rate from a `YOUR_PACKAGING` request
  carries `shipperPackaging()`.

Observable change, for a box size already declared as FedEx packaging: the ordinary
rate request now names the packaging (FedEx may re-price, though weight-based rates do
not depend on packaging type), and the label for a weight-based rate now declares the Pak
instead of customer packaging — which is what is in the packer's hand. Both go in the PR
description. For a box size with no carrier packaging nothing changes.

`isOneRateEligible()` reads `carrierPackaging` and requires it to be a FedEx case; a USPS
case is not One Rate eligible. From here on a rate restored from Livewire state or an
offer cannot be bought for a Package that has since been re-packed into its own box.

ADR-0005 decision 3 was amended 2026-09-15 to say exactly this; the amendment note under
its *Status* records why.

### `AmazonBuyShippingAdapter`

Rule 2 of `fitsThePackaging()` reads `carrierPackaging` and asks whether it is a FedEx
case, in place of the `fedexPackageType` null-or-`YOUR_PACKAGING` test. Mechanical; the
classifier rewrite is `04`. The two tests in `AmazonBuyShippingTest` that set
`fedex_package_type` move to `carrier_packaging`.

### `fedexShip.json`

`carrier-request-schema-validation/02` pins `packagingType` in the ship body. Confirm it
still passes with the mapping in place; the enum of accepted values is FedEx's and does
not change.

### `CONTEXT.md`

Add **Packaging** and **Carrier-supplied packaging** under *Language*, with the ADR's
definitions and its *avoid* list (package type, packaging type).

## Acceptance criteria

- [ ] Migration test: rows with each `FedexPackageType` value, `YOUR_PACKAGING` and null
      migrate to the expected `carrier_packaging`, and none is nulled that was not
      `YOUR_PACKAGING`/null; `down()` restores them, and refuses with the row names when
      a box size carries a USPS or UPS case
- [ ] `grep -rn fedex_package_type app database tests` finds only the migration
- [ ] `BoxSizeResource` and `SetupWizard` create and edit a box size with a carrier
      packaging and with none, Livewire-tested; screenshots in the PR
- [ ] `PackageData::fromPackage()` carries the box size's packaging, tested
- [ ] `FedexAdapterTest`: One Rate is requested for `FedexPak`, not for
      `UspsMediumFlatRateBox`, not for null; the ordinary rate body's `packagingType` is
      `FEDEX_PAK` / `YOUR_PACKAGING` / `YOUR_PACKAGING` for those three; the ship body
      matches for both a weight-based and a One Rate rate
- [ ] `FedexAdapterTest`: for a `FedexPak` Package, *both* the weight-based Express Saver
      rate and its One Rate variant carry `exactly(FedexPak)`, and `ShippingRateService`
      returns both; for a plain box every FedEx rate is `shipperPackaging()`
- [ ] The two observable changes above are in the PR description
- [ ] `AmazonBuyShippingTest`'s One Rate pair passes with the new column
- [ ] `CONTEXT.md` gains the two terms
- [ ] Full `composer run test` green; `vendor/bin/pint --dirty --format agent` clean

## Blocked by

- [`01`](01-packaging-requirement-on-every-rate-and-the-shared-filter.md) — the enum and
  the `PackageData` field
