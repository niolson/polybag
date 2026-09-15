# Direct USPS rates a declared flat-rate packaging

Status: ready-for-human

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

- [ ] Whether the flat-rate variants need a `processingCategory` in the request is
      answered from two sandbox responses, recorded here; the request builder changes
      only if they do
- [ ] The (mailClass, rateIndicator) → `CarrierPackaging` table is recorded in a comment
      on this file with its source, and the classifier takes both
- [ ] `UspsAdapterTest`: a fixture with `SP`, `CP`, the flat-rate envelope and medium
      flat-rate box indicators for a `BOX` package returns `SP` and `CP` as
      `shipperPackaging()` and the two flat-rate ones as `exactly(…)`; the same envelope
      indicator under `PRIORITY_MAIL_EXPRESS` is `exactly(UspsExpressFlatRateEnvelope)`;
      the soft-pack tiers are still dropped for a `BOX` and kept for a `POLYBAG`
- [ ] `ShippingRateService` test: the same fixture for a Package in a
      `UspsMediumFlatRateBox` box size yields only the medium-flat-rate-box rate
- [ ] `resolvePreSelectedRate()` for that Package returns the flat-rate variant, not the
      cheapest `SP`
- [ ] The label body for a flat-rate rate validates against `uspsLabel.json`
- [ ] One sandbox rate-and-buy for a declared flat-rate box, recorded here
- [ ] `vendor/bin/pint --dirty --format agent` clean

## Blocked by

- [`02`](02-pre-selection-filters-before-it-chooses.md) — the USPS variant choice must
  filter first, or an automated rule buys the flat-rate envelope for a plain box
- [`03`](03-box-size-carrier-packaging-replaces-fedex-package-type.md) — the column
