# Request schema validation for carrier APIs

Status: reference

Background for the two issues in this directory. Not a work item.

## What exists already

`polybag` validates outbound request bodies against carriers' own API schemas in the
test suite. Vendored specs live in `tests/Fixtures/Schemas/`, and
`assertMatchesApiSchema($body, $schema, $document)` in `tests/Pest.php` validates a body
against a named schema, handling both Swagger 2.0 (`definitions`) and OpenAPI 3
(`components/schemas`). Failures print each violation plus the offending body.

| API | Coverage | Shipped in |
|---|---|---|
| Amazon `confirmShipment` | 3 body shapes | polybag#144 |
| UPS `CreateShipment` | 2 body shapes | polybag#145 |
| UPS `Rate` | written, skipped — spec over-specifies vs. the live API | polybag#145 |
| USPS `Label` / `InternationalLabel` | hand-written schema, 7 body shapes | this directory, `01` |
| FedEx `CreateShipment` | hand-written schema, 13 adapter tests | this directory, `02` |

## Why it is worth extending

The problem it solves is that a golden-array assertion is written at the same time as
the code it checks. If we build the wrong shape and freeze that same shape into the
test, both agree, the suite is green, and the carrier rejects the call at the bench.

This is not hypothetical for USPS. `tests/Unit/Services/Carriers/UspsAdapterTest.php`
has a test named *"does not surface a schema validation dump to the packer"*, which
exists because USPS answered a malformed label body with:

```
OASValidation OpenAPI-Spec-Validation-Labels with resource oas://labels-v3.yaml:
failed with reason: [ERROR - [Path '/toAddress'] Instance failed to match all
required schemas
```

USPS validates our bodies against `labels-v3.yaml` server-side. We already took that
hit in production and handled it at the presentation layer. A local check moves it to
CI.

Both UPS issues found on introduction were real: a 12-character test account number
against a spec that pins `ShipperNumber` to exactly 6, and `buildRateApiRequest()`
sending `Shipment.Package` as an object where our own ship path sends an array.

## The licensing constraint

**USPS and FedEx specs cannot be vendored.** This is settled, not an open question:

- USPS [terms](https://developers.usps.com/terms-and-conditions): material "may not
  under any circumstances be reproduced or used without USPS's prior written
  permission", and separately bars preparing "any derivative work of" it.
- FedEx [FDPLA](https://developer.fedex.com/api/en-tz/legal/fdpla.html): bars derivative
  works (§5(n)) and disclosure of FedEx Technology to third parties (§5(e), §11(a)).
  Note §5 also bars distributing Materials for use in a **multi-carrier system**, which
  is a broader question about the product than anything in this directory.

Amazon (Apache-2.0) and UPS (MIT) could be vendored, and were, with upstream notices in
`tests/Fixtures/Schemas/licenses/`.

So for USPS and FedEx the schema must be **hand-written from our own adapter code** —
a description of the payload *we* construct, not a transcription of theirs. That is our
work product. It is also narrower and arguably more useful: for Amazon only ~8 of 88
definitions mattered.

## What a hand-written schema is expected to catch

The same class the vendored ones catch, and the one that actually bit us: a required
field we stop sending, a field we rename, a scalar we send as the wrong type, an object
where an array belongs. It will not catch business-rule rejections (bad address, closed
account, service unavailable for the lane) — those need the carrier.
