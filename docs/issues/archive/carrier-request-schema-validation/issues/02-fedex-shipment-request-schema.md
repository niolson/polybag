# Validate FedEx shipment requests against a hand-written schema

Status: done

Repo: `polybag`

## Problem

Same gap as `01`, for FedEx. `FedexAdapter` builds the largest request bodies in the
codebase — `createShipment()` plus `buildCustomsClearanceDetail()`,
`buildPackageSpecialServices()`, `buildCustomerReferences()`, `buildContact()`,
`buildShipmentSmartPostInfoDetail()` — and nothing validates their shape. The existing
assertions in `tests/Unit/Services/Carriers/FedexAdapterTest.php` and
`tests/Unit/Integrations/FedexConnectorTest.php` check individual fields against
hand-written expectations.

FedEx has more body-bearing request classes than any other integration in
`app/Http/Integrations/` (15), so the surface is correspondingly large.

## Constraint — read before starting

**Do not vendor or transcribe FedEx's OpenAPI specs or portal documentation.** The FDPLA
bars derivative works (§5(n)) and disclosure of FedEx Technology to third parties (§5(e),
§11(a)). See `../PRD.md`.

Write the schema from **our own code** in `FedexAdapter` and the request classes under
`app/Http/Integrations/Fedex/Requests/`.

## Expected behavior

Add `tests/Fixtures/Schemas/fedexShip.json` covering the body built for
`Fedex\Requests\CreateShipment`, and wire `assertMatchesApiSchema()` into the existing
`assertSent` closures.

Start with `CreateShipment` only. `Rates` is a reasonable follow-up but fails soft — a
malformed rate request drops FedEx from the comparison, where a malformed ship request
means no label at the bench.

Worth covering, since these are where the shape varies most:

- Domestic ground, the common path
- International with customs clearance detail
- SmartPost, which takes a different branch
- A shipment carrying special services

## Test notes

- Follow the pattern in `UpsAdapterTest.php` under "Request schema conformance".
- Add guard tests for the schema itself — see `01` and
  `tests/Unit/Integrations/SpApiSchemaValidationTest.php`.
- **Prove it bites** before calling it done: break a required field in `FedexAdapter`,
  confirm a useful failure, revert.
- `FedexAdapterTest.php` asserts on `config(['services.fedex.account_number' => 'test_account'])`
  in several places. If the schema constrains account-number length, check what FedEx
  actually requires before changing the fixture — and change only the FedEx one. UPS and
  FedEx previously shared the string `test_account` for unrelated reasons.

## Open question

The FDPLA §5 clause barring distribution of Materials for use in a **multi-carrier
system** is a broader product question than this issue, since PolyBag is one. It does not
block this work — a schema written from our own code is not FedEx Materials — but it is
worth a separate read. Noted in `../PRD.md`.

## Comments

**Implemented 2026-09-11.** `tests/Fixtures/Schemas/fedexShip.json` holds one top-level
definition, `CreateShipmentRequest`, built from `RequestedShipment`, `Party`, `Contact`,
`Address`, `LabelSpecification`, `PackageLineItem`, `PackageSpecialServices`,
`SmartPostInfoDetail`, `CustomsClearanceDetail`, `Commodity` and the rest. Each
definition's `description` names the adapter method it was read off, and every object
is `additionalProperties: false`, so a renamed key fails as both "required property
missing" and "additional property not allowed". Nothing was copied from FedEx: the
field names are ours, spelled as `FedexAdapter` spells them, and `packagingType` is
enumerated from our own `FedexPackageType` enum.

Beyond names and types it pins the pairings a golden array does not: `resolution` with
`ZPLII` and never `PDF`; each package special service with its detail in both
directions; the SmartPost indicia with its endorsement; exactly one recipient and one
line item. Customs amounts, quantities and weights are pinned as decimal *strings*,
because that is what the adapter sends and production accepts — a change in either
direction now has to be deliberate.

`assertMatchesFedexSchema($body, $schema)` in `tests/Pest.php` wraps the generic
helper with the document name fixed. It is wired into all eleven `CreateShipment`
closures in `FedexAdapterTest.php` — the bare `assertSent(CreateShipment::class)` on
the domestic ground test became a closure — covering domestic ground, three
international/customs shapes (Canada, Puerto Rico, APO), SmartPost `PARCEL_SELECT`,
signature + alcohol + declared value, ground and express battery, three label reference
sources, and the recipient phone fallback. Two new tests reach the branches nothing
exercised at ship level: sub-pound SmartPost (`PRESORTED_STANDARD` +
`ADDRESS_CORRECTION`), and a ZPL 300 DPI One Rate label with Saturday delivery, a ship
date, a two-line shipper address and a company-only recipient with a phone extension.
`tests/Unit/Integrations/FedexSchemaValidationTest.php` guards the schema with 72
tests, modelled on the USPS file.

**It bites.** Renaming `postalCode` to `postal_code` in `buildContact()` and casting the
package weight to a string produced, from one test:

```
- requestedShipment.shipper.address.postalCode: The property postalCode is required
- requestedShipment.shipper.address: The property postal_code is not defined and the definition does not allow additional properties
- requestedShipment.recipients[0].address.postalCode: The property postalCode is required
- requestedShipment.recipients[0].address: The property postal_code is not defined and the definition does not allow additional properties
- requestedShipment.requestedPackageLineItems[0].weight.value: String value found, but a number is required
```

followed by the full body. Reverted.

**What the schema turned up.** No defect in a body a test sends today, but four
reachable shapes the guard tests record, none constructed anywhere in the suite:

- `buildContact()` filters empty values, so a shipment with no first name, last name or
  company sends a contact with only a phone number — the FedEx twin of the USPS
  nameless-address gap in `01`. With no phone either, it sends `[]`, a JSON list.
- `Address` passes `stateOrProvinceCode` and `postalCode` through untouched, so a
  destination without one (a country with no postal codes, say) sends `null` for it.
  Whether FedEx treats that as absent or rejects it is unverified; omitting the key
  would be the safe fix.
- Dimensions are cast to `int`, so a side under an inch reaches FedEx as `0`.
- `resolveAccountNumber()` returns null for an account with no account number
  credential, and nothing checks before sending.

`countryOfManufacture` is pinned to two upper-case letters. The product form caps
`country_of_origin` at two characters but does not upper-case it, so a `cn` entered by
hand would fail here — and probably at FedEx.

The `test_account` placeholder was left alone: the schema types the account number as a
non-empty string and does not constrain its length, since our code does not either.

**What it does not do.** `serviceType` is a non-empty string only — copied from the rate
response, and enumerating it would mean transcribing FedEx's vocabulary. `Rates` is not
covered, per the issue. `FedexConnectorTest::builds correct create shipment request`
hand-builds its own body with `PAPER_4X6` stock and no payment block, so it is a
connector test, not an adapter one, and was not wired.
