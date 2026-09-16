# Direct USPS rates a declared flat-rate packaging

Status: done — shipped 2026-09-16; direct USPS keeps the flat-rate indicators as `exactly(…)` requirements, and a box size declared a USPS flat-rate envelope or box rates and buys at the flat-rate price — proved by a sandbox purchase

Repo: `polybag`

## Parent

[ADR-0005](../../../adr/0005-packaging-form-and-carrier-identity.md), the USPS half of
decision 3, "Physical-form filtering stays inside the adapters", and the *Trade-off*
section — this is the cost the ADR says is deferred rather than avoided.

## What to build

`UspsAdapter::isValidRateIndicator()` today keeps `SP`/`PA`, the cubic box indicator and
the cubic soft-pack tiers, and discards everything else — which is how the flat-rate
services are excluded. After this slice the flat-rate indicators survive as `exactly(…)`
requirements and the shared filter decides, so a box size declared
`UspsMediumFlatRateBox` rates Priority Mail at the flat-rate price on the direct account
and buys a label for it.

### What the adapter stops doing

`isValidRateIndicator()` keeps its **form** filtering — `BOX_RATE_INDICATORS` and
`SOFT_PACK_RATE_INDICATORS` still choose cubic tiers from `boxType`, exactly as today,
because both cubic tables are `shipperPackaging()` and the shared filter cannot tell them
apart. What it stops doing is dropping the flat-rate indicators.

### Whether the request changes — establish first

The ADR (as amended) says the rate request sends a flat-rate `processingCategory` **only
if** an `ALL_OUTBOUND` search does not return the flat-rate variants unprompted.
`buildRateApiRequest()` sends no `processingCategory` today: it asks for `ALL_OUTBOUND`
and reads `rateIndicator` and `processingCategory` off each rate that comes back — and
the flat-rate indicators *do* come back, which is why the adapter has code to discard
them. So the first sandbox `search` for a small box, with and without a
`processingCategory` in the request, decides it: the variants arrive unprompted (request
unchanged) or only when asked for (the request builder gains `processingCategory` from
`carrierPackaging`, and `uspsLabel.json`'s sibling rate schema, if one is added, pins it).
Record both responses' shapes in a comment here before writing the classifier.

### The classifier

A `packagingRequirementFor(string $mailClass, string $rateIndicator): PackagingRequirement`
beside `isValidRateIndicator()`, from the pair to the `CarrierPackaging` case — flat-rate
envelope, legal, padded, small/medium/large box — with everything else
`shipperPackaging()`. It takes both inputs because Priority Mail and Priority Mail
Express flat-rate envelopes are different packaging (`01`) and USPS may well use the same
`rateIndicator` for both: the pair (`PRIORITY_MAIL_EXPRESS`, envelope indicator)
classifies to the `UspsExpress…` case, (`PRIORITY_MAIL`, same indicator) to the Priority
Mail one. **The (mailClass, indicator) → packaging table is established from USPS's
Domestic Prices API documentation and the real `search` response above**, and recorded
in a comment here with its source. Note that `PA` sits in `UNIVERSAL_RATE_INDICATORS`
today; confirm what it is before assuming it is universal.

### The label

`createShipment()` already copies `mailClass`, `rateIndicator` and `processingCategory`
from the selected rate's metadata into the label body, and
`tests/Fixtures/Schemas/uspsLabel.json` (`carrier-request-schema-validation/01`) pins
them as non-empty strings. A flat-rate label therefore differs from a Priority Mail
label only in those three values. Extend the schema if USPS's label endpoint wants
anything else for a flat-rate indicator — the point of having the schema first is that
this is where a wrong body fails loudly.

### `resolvePreSelectedRate()`

Already filters before choosing (`02`). A rule that pre-selects Priority Mail for a
Package in a flat-rate box now gets the flat-rate variant, which is the right answer and
worth a test.

### Why `ready-for-human`

The indicator table has to be read off a real USPS `search` response, and the result has
to be proven by a rate-and-buy on the USPS sandbox for a declared medium flat-rate box —
the suite proves the body is well-formed, only USPS proves it is accepted. Both need
sandbox credentials and a person reading what comes back; record the indicator that came
back and the label's rate indicator in a comment here.

## Acceptance criteria

- [x] Whether the flat-rate variants need a `processingCategory` in the request is
      answered from two sandbox responses, recorded here; the request builder changes
      only if they do — they do not; unchanged
- [x] The (mailClass, rateIndicator) → `CarrierPackaging` table is recorded in a comment
      on this file with its source, and the classifier takes both
- [x] `UspsAdapterTest`: a fixture with `SP`, `CP`, the flat-rate envelope and medium
      flat-rate box indicators for a `BOX` package returns `SP` and `CP` as
      `shipperPackaging()` and the two flat-rate ones as `exactly(…)`; the same envelope
      indicator under `PRIORITY_MAIL_EXPRESS` is `exactly(UspsExpressFlatRateEnvelope)`;
      the soft-pack tiers are still dropped for a `BOX` and kept for a `POLYBAG`
- [x] `ShippingRateService` test: the same fixture for a Package in a
      `UspsMediumFlatRateBox` box size yields only the medium-flat-rate-box rate
- [x] `resolvePreSelectedRate()` for that Package returns the flat-rate variant, not the
      cheapest `SP`
- [x] The label body for a flat-rate rate validates against `uspsLabel.json`
- [x] One sandbox rate-and-buy for a declared flat-rate box, recorded here
- [x] `vendor/bin/pint --dirty --format agent` clean

## Classifier invariant (added 2026-09-16)

`UspsAdapter::packagingRequirementFor()` / `classifyPackaging()` is the hook `01` left for
this slice. It classifies from the same `rateIndicator` / `mailClass` the ship body sends,
which is what makes the purchase-time check consistent with the purchase — so the
classifier must be **exhaustive** over the indicators USPS returns for the mail classes
the adapter keeps, and an indicator it does not recognise must not fall through to
`shipperPackaging()`. Refuse it or classify it conservatively; do not default it. The
authority question (the browser restates the metadata) is `postage-source-split/14`, not
this issue.

## Blocked by

- [`02`](02-pre-selection-filters-before-it-chooses.md) — the USPS variant choice must
  filter first, or an automated rule buys the flat-rate envelope for a plain box
- [`03`](03-box-size-carrier-packaging-replaces-fedex-package-type.md) — the column

## Sandbox findings (added 2026-09-16)

Source: `POST /shipments/v3/options/search` against `apis-tem.usps.com` (sandbox mode,
EPS `CONTRACT` pricing), plus two weeks of the same call in
`storage/logs/usps-validation-*.log`. Package 8 × 5 × 1.5 in, 1 lb, 90210 → 10001,
`mailClass: ALL_OUTBOUND`.

### `processingCategory` in the request changes nothing

Three searches — no `processingCategory`, `MACHINABLE`, `FLATS` — returned byte-identical
rate lists. The flat-rate variants arrive unprompted from `ALL_OUTBOUND`; the request
builder stays as it is. The ADR's conditional is resolved on the "unchanged" branch.

### (mailClass, rateIndicator) → packaging, as USPS returns it

| mailClass | rateIndicator | processingCategory | description | classify as |
|---|---|---|---|---|
| `PRIORITY_MAIL` | `FE` | `FLATS` | Flat Rate Envelope | `exactly(UspsFlatRateEnvelope)` |
| `PRIORITY_MAIL` | `FA` | `FLATS` | Legal Flat Rate Envelope | `exactly(UspsLegalFlatRateEnvelope)` |
| `PRIORITY_MAIL` | `FP` | `FLATS` | Padded Flat Rate Envelope | `exactly(UspsPaddedFlatRateEnvelope)` |
| `PRIORITY_MAIL` | `FS` | `MACHINABLE` | Small Flat Rate Box | `exactly(UspsSmallFlatRateBox)` |
| `PRIORITY_MAIL` | `FB` | `MACHINABLE` | Medium Flat Rate Box | `exactly(UspsMediumFlatRateBox)` |
| `PRIORITY_MAIL` | `PL` | `MACHINABLE` | Large Flat Rate Box | `exactly(UspsLargeFlatRateBox)` |
| `PRIORITY_MAIL` | `PM` | `MACHINABLE` | Large Flat Rate Box APO/FPO/DPO | **drop** — see below |
| `PRIORITY_MAIL_EXPRESS` | `E4` | `FLATS` | Express Flat Rate Envelope | `exactly(UspsExpressFlatRateEnvelope)` |
| `PRIORITY_MAIL_EXPRESS` | `E6` | `FLATS` | Express Legal Flat Rate Envelope | `exactly(UspsExpressLegalFlatRateEnvelope)` |
| `PRIORITY_MAIL_EXPRESS` | `E7` | `FLATS` | Express Legal Flat Rate Envelope Holiday Delivery | **drop** — see below |
| `PRIORITY_MAIL_EXPRESS` | `FP` | `FLATS` | Express Padded Flat Rate Envelope | `exactly(UspsExpressPaddedFlatRateEnvelope)` |
| `PRIORITY_MAIL` / `PRIORITY_MAIL_EXPRESS` / `USPS_GROUND_ADVANTAGE` | `SP`, `PA`, `CP`, `P5`–`Q0` | `MACHINABLE` | Single-piece / cubic tiers | `shipperPackaging()` |

International mirrors it with `INTERNATIONAL_SERVICE_CENTER` entry: `PRIORITY_MAIL_INTERNATIONAL`
returns `FE`/`FA`/`FP`/`FB`/`PL` and `PRIORITY_MAIL_EXPRESS_INTERNATIONAL` returns
`E4`/`E6`/`FP`/`PA`, same packaging per indicator. `FS` was absent from every logged
search until this one — USPS omits it when the package does not fit the small box, so a
fixture for it needs small dimensions.

Things the issue text did not anticipate:

- **`FP` is shared** between Priority Mail and Priority Mail Express (padded envelope),
  exactly the case the two-input classifier was designed for. The plain and legal
  envelopes are *not* shared: `FE`/`FA` for Priority Mail, `E4`/`E6` for Express.
- **The envelopes come back as `processingCategory: FLATS`**, and `isValidRate()` drops
  `FLATS` before the indicator check runs. The `FLATS` filter must let the six flat-rate
  envelope indicators through (only those — `PRIORITY_MAIL_INTERNATIONAL` `SP`/`FLATS`
  "Single-piece Large Envelope" is a real flat and should stay dropped).
- **`PM`** (large flat rate box, APO/FPO/DPO price, $29.59 vs `PL` $31.00) is returned
  for a non-military destination. If it classified as `UspsLargeFlatRateBox` it would
  win the cheapest-variant pick for every large flat rate box. Drop it in
  `isValidRate()` until a military-destination check exists.
- **`E7`** is the Express legal envelope priced for Sunday/holiday delivery. Same
  packaging as `E6`, different product; drop it rather than offer two prices for one
  envelope. Neither `PM` nor `E7` may fall through to `shipperPackaging()` — the
  classifier invariant above.
- `PA` is Priority Mail Express's single-piece indicator (its `SP`), not a universal one.
  It is shipper packaging; `UNIVERSAL_RATE_INDICATORS` is right about the form, wrong
  about the name.

## Sandbox rate-and-buy (added 2026-09-16)

Through the real `UspsAdapter`, sandbox, EPS contract pricing. `PackageData` 11 × 8.5 ×
5.5 in, 2 lb, `BOX`, `carrierPackaging: UspsMediumFlatRateBox`, warehouse ZIP to a residential ZIP in the same state (both from an earlier successful sandbox label — USPS rejected two made-up addresses first).

`getRates()` for `PRIORITY_MAIL` kept five variants — `FP` $11.99 (padded envelope),
`FB` $21.17 (medium box), `PL` $31.00 (large box), `SP` $9.10 and `CP` $9.45 (cubic
tier 3, shipper packaging). `FE`/`FA`/`FS` were not quoted: USPS omits them when the
dimensions cannot fit. `PackagingFilter::keepCompatible()` for the medium box left only
`FB`.

`createShipment()` with that rate — label body `mailClass: PRIORITY_MAIL`,
`rateIndicator: FB`, `processingCategory: MACHINABLE`, nothing else changed —
succeeded: a tracking number came back, postage **$21.17** (the flat-rate quote,
not the $9.10 single-piece price), `zone: "00"`, SKU `DPFB1XXXXC00700` — the `FB` in
the SKU is USPS echoing the indicator back. `uspsLabel.json` needed no extension.

## Implementation notes (added 2026-09-16)

- The classifier refuses an unknown pair by throwing
  `UnclassifiablePackagingException`; `EloquentPackageShippingWorkflow::packagingRefused()`
  catches it and returns `packagingMismatch()`, so a browser restating `PM` or `E7` gets
  "get rates again", not a 500. At rate shopping it cannot throw: `isValidRate()` keeps
  exactly the pairs the classifier knows.
- Review (same day, two passes): the shipper-packaging branch is pair-based too. A
  browser-restated `MEDIA_MAIL`/`SP` used to classify as `shipperPackaging()` because
  `SP` is, and a first fix with separate class and indicator allow-lists still let
  `USPS_GROUND_ADVANTAGE`/`PA` and `PRIORITY_MAIL_EXPRESS`/`CP` through as a Cartesian
  product. Now `SHIPPER_PACKAGING_INDICATORS` is mailClass → indicators, read off every
  logged response: Ground Advantage and Priority Mail get `SP` and both cubic tables,
  Express (domestic and international) gets `PA` only, Parcel Select and the
  international parcel classes `SP` only; Global Express Guaranteed has never appeared
  and is absent. `isValidRate()` and `isValidRateIndicator()` read the same table
  (replacing the Library/Media deny-list), so the filter never keeps a pair the
  classifier would refuse. One existing test had fabricated a Ground Advantage/`PA`
  row; its fixture now says Express. Whether a restated mail class is *authorised* is
  still `postage-source-split/14`; this only stops it being *classified*.
- `isValidRate()`'s `FLATS` exemption is by indicator (`FE`, `FA`, `FP`, `E4`, `E6`),
  so `PRIORITY_MAIL_INTERNATIONAL` `SP`/`FLATS` — a real large envelope — stays dropped.
- Not in this slice: offering `PM` to APO/FPO/DPO destinations. It needs a
  military-destination check the adapter does not have; today those addresses get `PL`.
