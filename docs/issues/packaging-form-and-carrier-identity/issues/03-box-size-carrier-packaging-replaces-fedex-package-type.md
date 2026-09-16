# `box_sizes.carrier_packaging` replaces `fedex_package_type`

Status: done — shipped 2026-09-16; a box size can now say it is carrier packaging, and FedEx stamps every rate with the packaging it sent

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

`fromPackage()` already reads `carrierPackaging: $package->boxSize?->carrier_packaging`
— `02` landed that line so its tests could hand-set a packaging, with a `@property`
docblock on `BoxSize` standing in for the column. This slice adds the column and the
cast, and the docblock can go.
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

- [x] Migration test: rows with each `FedexPackageType` value, `YOUR_PACKAGING` and null
      migrate to the expected `carrier_packaging`, and none is nulled that was not
      `YOUR_PACKAGING`/null; `down()` restores them, and refuses with the row names when
      a box size carries a USPS or UPS case
- [x] `grep -rn fedex_package_type app database tests` finds only the migration
- [x] `BoxSizeResource` and `SetupWizard` create and edit a box size with a carrier
      packaging and with none, Livewire-tested; screenshots in the PR
- [x] `PackageData::fromPackage()` carries the box size's packaging, tested
- [x] `FedexAdapterTest`: One Rate is requested for `FedexPak`, not for
      `UspsMediumFlatRateBox`, not for null; the ordinary rate body's `packagingType` is
      `FEDEX_PAK` / `YOUR_PACKAGING` / `YOUR_PACKAGING` for those three; the ship body
      matches for both a weight-based and a One Rate rate
- [x] `FedexAdapterTest`: for a `FedexPak` Package, *both* the weight-based Express Saver
      rate and its One Rate variant carry `exactly(FedexPak)`, and `ShippingRateService`
      returns both; for a plain box every FedEx rate is `shipperPackaging()`
- [x] The two observable changes above are in the PR description
- [x] `AmazonBuyShippingTest`'s One Rate pair passes with the new column
- [x] `CONTEXT.md` gains the two terms
- [x] Full `composer run test` green; `vendor/bin/pint --dirty --format agent` clean

## Blocked by

- [`01`](01-packaging-requirement-on-every-rate-and-the-shared-filter.md) — the enum and
  the `PackageData` field

## Comments

### 2026-09-16 — triage

> *This was generated by AI during triage.*

**enhancement / ready-for-agent.** `01` and `02` are done, and every claim above was
checked against the code: `CarrierPackaging` carries `FedexBox`, `Fedex10kgBox` and
`Fedex25kgBox`, so the migration is lossless; `PackageData::fromPackage()` already reads
`carrier_packaging` behind a `@property` docblock; the ship body's
`isOneRate ? fedexPackageType : YOUR_PACKAGING` branch and the One Rate request are the
only places the adapter names a packaging today; `fedexShip.json` pins `packagingType`.

One correction to the body: `BoxSizeSeeder` is not all the packer's own boxes — codes
`18`–`21` are FedEx Small Box, Medium Box, Envelope and Pak. Those four rows keep their
identity as `carrier_packaging`; the seventeen `YOUR_PACKAGING` rows lose the column.
That is the reading `06` already assumes ("`03` carries every existing FedEx declaration
across"). Row `16`, *USPS Flat Rate Padded Envelope*, is a padded mailer with no carrier
identity today and stays that way here — declaring it is `06`'s decision.

### 2026-09-16 — implemented

> *This was generated by AI during triage.*

Shipped as one branch, `feat/box-size-carrier-packaging`. Full suite green (2468 passed, 2
skipped); Pint and PHPStan clean.

- **Migration** `2026_09_16_000000_replace_fedex_package_type_with_carrier_packaging_on_box_sizes`
  with its own frozen eleven-entry mapping. `down()` refuses with the row list before touching
  the schema. Run against the local MySQL: the four seeded FedEx rows came across. Tested by
  `require`-ing the file and running it both ways from the migrated schema, the way
  `PackageLabelRecordTest` tests the label backfill.
- **`FedexAdapter`**: one private `packagingTypeFor(?CarrierPackaging): FedexPackageType`, an
  exhaustive match; the reverse for classification is derived from it with `array_find` over
  the enum's cases rather than written a second time. Every rate request — ordinary, Saturday
  and One Rate — now sends `packagingType`; every rate carries it as metadata under
  `packagingType` (the old `fedexPackageType` key is gone), and the ship body sends that
  value back. A rate restored from Livewire state before this change has no such key and is
  treated as `YOUR_PACKAGING`, which is what it was quoted as.
- **Saloon quirk found writing the tests**: a mock closure runs when a `PendingRequest` is
  *created*, and `getRates()` creates one through `prepareRateRequest()` before sending
  another, so a closure that counts calls counts double. The new tests read
  `Saloon::mockClient()->getRecordedResponses()` instead, which holds only what was sent.
- **Forms**: one grouped `<select>` per form (USPS 9 / FedEx 11 / UPS 7), placeholder
  *Own packaging*, helper text as the ADR's last consequence asks. `CarrierPackaging::groupedOptions()`
  builds it for both.
- **Not done here**: row `16` of the seeder is still a nameless padded mailer (`06`); the
  Amazon classifier is still a filter (`04`); nothing rates a USPS case (`05`).
