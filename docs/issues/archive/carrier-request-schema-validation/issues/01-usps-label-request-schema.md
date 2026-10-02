# Validate USPS label requests against a hand-written schema

Status: done

Repo: `polybag`

## Problem

Nothing checks the shape of the USPS label body we send. The assertions in
`tests/Unit/Services/Carriers/UspsAdapterTest.php` compare against hand-written expected
arrays, which were written at the same time as the code that builds the body — so a
wrong shape frozen into both passes CI and fails at the workstation.

We have already taken this hit. That file contains a test named *"does not surface a
schema validation dump to the packer"*, which exists because USPS rejected a malformed
body with `OASValidation ... [Path '/toAddress'] Instance failed to match all required
schemas`. USPS validates against `labels-v3.yaml` server-side; we found out in
production and handled the symptom.

## Constraint — read before starting

**Do not vendor or transcribe `labels-v3.yaml`, and do not copy text from
developers.usps.com.** USPS's terms bar reproduction without written permission and bar
derivative works. See `../PRD.md`.

Write the schema by reading **our own code** — `UspsAdapter::createShipment()` and the
builders it calls (`buildDomesticAddress()`, `buildInternationalAddress()`,
`buildCustomsForm()`, `buildCustomerReference()`) — and describe the payload we
construct. Field *names* are facts about our own outbound request; the deliverable is a
description of our code's output, not a copy of USPS's document.

## Expected behavior

Add `tests/Fixtures/Schemas/uspsLabel.json` — a hand-written JSON Schema (draft-4
compatible, matching what `justinrainbow/json-schema` validates) covering the body built
for `App\Http\Integrations\USPS\Requests\Label` (`POST /labels/v3/label`).

Scope it to what we actually send:

- Required top-level fields, and required fields on `toAddress` / `fromAddress`
- Types — several USPS numeric-looking fields are strings; get this right, it is the
  most likely real bug class
- The customs block for `InternationalLabel`, if covering that in the same pass

Then wire `assertMatchesApiSchema($body, '<SchemaName>', 'uspsLabel')` into the existing
`Saloon::assertSent(function (Label $request) ...)` closures, the way
`UpsAdapterTest.php` does for `SHIPRequestWrapper`. Cover at least: a domestic label, a
label with special services, and an international label.

`InternationalLabel` (`POST /international-labels/v3/international-label`) has a
different body — either a second schema in the same document or a follow-up issue,
implementer's call.

## Test notes

- The helper lives in `tests/Pest.php`; it resolves `#/definitions/<name>` or
  `#/components/schemas/<name>` automatically, so either layout works.
- Add guard tests alongside `tests/Unit/Integrations/SpApiSchemaValidationTest.php`. A
  schema that silently validates nothing is worse than none — that file exists precisely
  to catch a `$ref` that stops resolving.
- **Prove it bites.** Temporarily break a field in `UspsAdapter` (rename a required key,
  or send a number where a string belongs), confirm the test fails with a useful message,
  then revert. Both prior PRs did this and both found something.
- Note `createUspsAccount()` in `tests/Pest.php` seeds `crid` / `mid`. The UPS work found
  the equivalent UPS fixture used a 12-character account number against a spec requiring
  exactly 6 — worth checking whether the USPS placeholders are realistic before assuming
  a failure is a code bug.

## Comments

**Implemented 2026-09-11.** `tests/Fixtures/Schemas/uspsLabel.json` holds two top-level
definitions, `LabelRequest` and `InternationalLabelRequest`, sharing `DomesticAddress`,
`PackageDescription`, `CustomsForm`, `ImageInfo` and the rest. Both bodies went in one
pass rather than a follow-up — the international one is the domestic one with a
different `toAddress` and a mandatory `customsForm`, so splitting them would have
duplicated most of the file. Each definition's `description` names the adapter method it
was read off, and every object is `additionalProperties: false`, so a renamed key fails
as both "required property missing" and "additional property not allowed".

Nothing was copied from USPS. The one thing worth saying about provenance: `ZIPCode`,
`countryISOAlpha2Code`, `countryofOrigin` (lower-case *of*) and `HSTariffNumber` are
spelled exactly as our code spells them, and our code is what the sandbox and
production accept, so their casing is a fact about our outbound request.

`assertMatchesUspsSchema($body, $schema)` in `tests/Pest.php` wraps the generic helper
with the document name fixed, mirroring `assertMatchesUpsSchema()`. It is wired into
seven `Saloon::assertSent()` closures in `UspsAdapterTest.php`: a domestic label with a
reference, adult signature + $750 declared value (`packageOptions`), a lithium battery
(`contentType: HAZMAT`), a domestic APO/FPO label carrying a customs form, two
international labels (with and without the invoice number), and a new ZPL 300 DPI label
to a business address that exercises `firm`, `secondaryAddress`, the ZIP+4 truncation
and `imageType`. `tests/Unit/Integrations/UspsSchemaValidationTest.php` guards the
schema itself with 32 tests, modelled on the SP-API file.

**It bites.** Renaming `ZIPCode` to `zipCode` and casting `weight` to a string in the
adapter produced, from one test:

```
- toAddress.ZIPCode: The property ZIPCode is required
- toAddress: The property zipCode is not defined and the definition does not allow additional properties
- fromAddress.ZIPCode: The property ZIPCode is required
- fromAddress: The property zipCode is not defined and the definition does not allow additional properties
- packageDescription.weight: String value found, but a number is required
```

followed by the full body. Reverted.

**What the schema turned up.** Not a defect in a body we send today, but a reachable
one: `UspsAdapter::addNameFields()` writes nothing when the address has no first name,
no last name and no company, and `AddressData::fromShipment()` produces exactly that
from a shipment whose `first_name`, `last_name` and `company` are all null. The adapter
sends the nameless address as-is; the schema's `anyOf` (first + last, or firm) rejects
it, and that rejection is the same shape as the production error this issue opened
with — an `anyOf`/`oneOf` failure at `/toAddress`. Nothing in the suite constructs such
an address, so no test fails, but the guard test *rejects an address with no name at
all* records it. Whether to refuse the label earlier with a message a packer can act on
is a small follow-up, not part of this issue.

The `crid` / `mid` placeholders in `createUspsAccount()` do not appear in the label
body (they ride on the payment-authorization step), so the UPS account-number problem
had no equivalent here.

**What it does not do.** `mailClass`, `rateIndicator`, `processingCategory` and
`destinationEntryFacilityType` are typed as non-empty strings only. They are copied from
the rate response, and enumerating them would mean transcribing USPS's vocabulary — the
thing this issue says not to do — for a check the rate step already makes. `state` is
pinned to two characters because our code passes it through untouched and a domestic
label wants a state code, not a name.
