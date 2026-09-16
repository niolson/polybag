# Handle international offers from what the rate response declares

Status: ready-for-human — all four pieces shipped 2026-09-16; `GetAdditionalInputsSchema` verified against the sandbox's static case, and the production run against a domestic offer is the one box left, needing `sandbox_mode` off (`.scratch/amazon-shipping-v2/probe-13-additional-inputs-schema.php`)

Repo: `polybag`

## Problem

[`09`](09-international-purchase-and-customs.md) is blocked on an unshipped foreign order
in the seller account, and there is no near-term way to place one. Until then the adapter
is unsafe for the first international order a tenant receives: a rate with
`requiresAdditionalInputs: true` passes the quote and fails the purchase, and a
`CUSTOM_FORM` document, if one came back, would be dropped by `labelFrom()`.

Amazon's support answer of 2026-09-16 to our sandbox questions
(`.scratch/amazon-shipping-v2/`) changed what is buildable without that order. It said,
in substance, that **the `getRates` response is the contract**:

- a customs document is the document type `CUSTOM_FORM`, returned as a separate package
  document (type, format, Base64 contents), never fused into the label;
- which documents a given offering returns is declared by that offering's
  `supportedDocumentSpecifications` / `supportedDocumentDetails`, and a
  `requestedDocumentSpecification` naming anything not declared there is rejected;
- the `additionalInputs` schema is not published anywhere; the only source is
  `GET /shipping/v2/shipments/additionalInputs/schema` called per rate, and the sandbox
  cannot exercise it (its single static case returns `payload: {}`, which the vendored
  model confirms — every other Shipping v2 operation is `dynamic`).

That is a vendor statement, not an observation, and `23` records it as such. But it means
the two things `09` was waiting to *observe* — whether a separate document comes back, and
what inputs a rate wants — are both readable from responses the adapter already receives,
or can fetch for free, on any offer. So the adapter can be made safe now, and made to
**capture the missing observations itself** the first time a real international offer
appears in production, at any tenant, without anyone buying a label.

## What to build

Four pieces, in the existing seams. None changes behaviour for a domestic offer.

### 1. Drop a rate that requires additional inputs, at quote time

`AmazonBuyShippingAdapter::isBuyable()` gains a fifth predicate: a rate with
`requiresAdditionalInputs: true` is not buyable. Same pattern as `hasPrintableDocument()`
— what would fail the purchase is refused before an offer is issued and before the money.
Record the drop as an observation the way `12`'s packaging drops are recorded, so *Map
Carrier Services* shows the service existed.

Later, when the schema has been seen, this becomes "satisfied or dropped" (`09`'s
criterion); for now it is always dropped. A tenant with a Canadian order gets its direct
USPS / UPS / FedEx offers and no Amazon offer, which is correct.

### 2. `GetAdditionalInputsSchema` request, called on every rate that asks, and the schema recorded

A Saloon request for `GET /shipping/v2/shipments/additionalInputs/schema`
(`requestToken`, `rateId`) under `app/Http/Integrations/Amazon/Requests/`, beside
`GetShippingRates`. `09` established that in production it answers 200 with an empty
schema (`properties: {}`) for a rate that does not require inputs, so the request class,
signing and parsing are **verifiable live today against any domestic offer** — do that
before merging, and note the result in a comment here.

Then: whenever `getRates` returns a rate with `requiresAdditionalInputs: true`, fetch the
schema for it and store it — the JSON schema carries no order data, so it can go in the
observation record for that service (or a log line at `info` with the service ID and the
schema; choose whichever the observation store makes easiest to find later). Fetching is
free and does not touch the order. This is what turns "wait for a test order" into "wait
for a customer": the first cross-border offer any tenant is quoted answers `09`'s finding
1 by itself.

Rate-limit note: call it once per distinct `rateId` that asks, not per page render.

### 3. The `07` gate for Amazon reads `supportedDocumentDetails`, not `requiresCustomsDeclaration()`

`shopify-shipping-carrier/07`'s pre-purchase gate — "will this purchase return a second
document that has to go to a report printer?" — is per-carrier, and for Amazon the
answer is in the offering: if the print option the adapter will choose (the one
`documentSpecification()` picks) lists a `supportedDocumentDetails` entry named
`CUSTOM_FORM`, a separate document will come back and a report printer is required;
otherwise nothing is gated.

This is also what fixes the over-block `09` observed on four US territories: every one
quoted as plain domestic USPS with no `CUSTOM_FORM` in the details, while
`AddressData::requiresCustomsDeclaration()` is true for all of them. A gate on the offering
lets those purchases through; a gate on the predicate refuses them for want of a printer
that would have printed nothing.

Stamp the answer on the rate the way `PackagingRequirement` is stamped — in
`rate_metadata` at quote time and re-derived at purchase — so the Ship page and the
purchase check read the same thing.

### 4. Request `CUSTOM_FORM` when the offering declares it, and read it into `customsFormData`

`documentSpecification()` already pushes every *mandatory* document detail into
`requestedDocumentTypes`, so a mandatory `CUSTOM_FORM` is requested today and then
**dropped**, because `labelFrom()` keeps only `type === 'LABEL'`. Two changes:

- Request `CUSTOM_FORM` whenever the chosen print option declares it, mandatory or not.
  Never when it is absent — Amazon refuses a specification the offering did not publish.
- Read a returned `CUSTOM_FORM` document into `ShipResponse::customsFormData`, the slot
  `UpsAdapter` and `ShopifyAdapter` fill, so `07`'s storage and report-printer printing
  apply unchanged. Do it beside `labelFrom()`, not by widening its filter: a lone
  `PACKSLIP` or `CUSTOM_FORM` with no `LABEL` must still fail loudly.

Carry the document's `format` — `07`'s storage assumes PDF and a ZPL or PNG customs form
would need the format carried, which `23` flagged before the Amazon row landed.

## Tests

Fixtures synthesised from `tests/Fixtures/Schemas/shippingV2.json` and validated against
it, the way `03` built its suite from `01`'s capture:

- a rate with `requiresAdditionalInputs: true` → no offer issued, observation recorded,
  schema request made once with that rate's token and ID
- a rate whose print option declares `CUSTOM_FORM` → stamped as returning a separate
  document; `requestedDocumentTypes` includes it; a purchase response carrying `LABEL` and
  `CUSTOM_FORM` fills both `labelData` and `customsFormData`
- a rate without `CUSTOM_FORM` → nothing requested, nothing gated, and a `CUSTOM_FORM`
  that arrives anyway is still read (Amazon is the authority on what it sends back)
- the four-territory shape from `09` — domestic USPS, no `CUSTOM_FORM`, address predicate
  true — is not gated
- `GetAdditionalInputsSchema` against a live domestic rate returns an empty schema
  (`tests/External`, or a documented one-off run recorded in a comment)

## Acceptance criteria

- [x] A rate requiring additional inputs is never offered, and the drop is visible as an
      observation
- [x] The additional-inputs schema is fetched and recorded for any rate that asks, with no
      order data in the record
- [ ] `GetAdditionalInputsSchema` verified against production on a domestic offer — sandbox
      static case verified; production run pending `sandbox_mode` off
- [x] The Amazon capability the `07` gate reads is derived from the offering's document
      details, and the territory shape is not gated
- [x] A `CUSTOM_FORM` package document reaches `customs_form_data` and prints through the
      `07` path, format carried
- [x] Fixtures for every shape above, schema-validated

## Still waiting on a foreign order (`09`)

The customs document's real format and size; whether the items the adapter sends (value,
description, weight, no HS code, no origin) are enough or a rejection names what is
missing; and the actual `additionalInputs` payload once a schema has been seen. `09` keeps
those.

## Related

- [`09`](09-international-purchase-and-customs.md) — the observation this makes
  self-capturing; keeps the purchase-only findings
- [`03`](03-amazon-buy-shipping-adapter.md) — `isBuyable()` and the quote-time drop pattern
- [`12`](12-drop-offers-for-packaging-the-package-is-not-in.md) — recording a dropped
  rate as an observation
- [`shopify-shipping-carrier/07`](../../shopify-shipping-carrier/issues/07-customs-form-printing.md)
  — the gate, storage and printing
- [`shopify-shipping-carrier/23`](../../shopify-shipping-carrier/issues/23-which-carriers-return-a-separate-customs-document.md)
  — the Amazon row, now filled provisionally from the vendor statement

## Comments

### 2026-09-16 — opened

Split out of `09` after Amazon's support reply to the sandbox case. The reply confirmed
nothing new about the sandbox — no international, third-party-carrier or
additional-inputs test path exists, which the vendored model already shows — but it did
state that documents are declared per offering in `getRates` and that a customs form is a
separate `CUSTOM_FORM` document. Everything in this issue follows from that statement and
is testable from the schema; `09` narrows to what only a real purchase can show.

### 2026-09-16 — shipped

> *This was generated by AI.*

All four pieces, in the seams named above; nothing changes for a domestic offer, and the
existing 43 Amazon tests pass unchanged beside twelve new ones.

1. **The drop.** `AmazonBuyShippingAdapter::isBuyable()` gained `needsNoAdditionalInputs()`
   as its second predicate, after `hasPrintableDocument()`. The observation is recorded
   first, as before, so *Map Carrier Services* sees the service and `last_eligible_at` is
   set; no `ShippingOffer` is issued.
2. **The schema.** `GetAdditionalInputsSchema` (`GET /shipping/v2/shipments/additionalInputs/schema`,
   `requestToken` + `rateId`, NA sandbox region, US business header) beside
   `GetShippingRates`. `AmazonBuyShippingService::additionalInputsSchema()` sends it and
   returns the payload or null — any failure is logged and changes nothing about the quote.
   `recordAdditionalInputsSchemas()` runs after `record()` and before the filter, once per
   `rateId` (a `Cache::add` guard, fifteen minutes, so a reply parsed twice asks once), and
   writes the schema to two new columns on `observed_services` —
   `additional_inputs_schema` (JSON) and `additional_inputs_schema_seen_at` — plus an
   `info` log line with the carrier and service IDs. The observation row was chosen over a
   log line because a log rotates and this is the one input `09`'s payload work needs.
3. **The gate's Amazon half.** `AmazonBuyShippingService::declaresCustomsForm()` answers
   from the offering: any print option among the rate's `supportedDocumentSpecifications`
   with a `supportedDocumentDetails` entry named `CUSTOM_FORM`. Over every option rather
   than the one `documentSpecification()` will pick, because the workstation's label
   format is not known at quote time; the purchase re-derives the same answer from the
   same stored array through `AmazonBuyShippingAdapter::returnsSeparateCustomsDocument(RateResponse)`,
   which mirrors `packagingRequirementFor()`. Stamped in `rate_metadata` under
   `AmazonBuyShippingAdapter::CUSTOMS_DOCUMENT_METADATA_KEY` (`returnsSeparateCustomsDocument`).
   The territory test builds the `09` shape — Puerto Rico, domestic USPS, no `CUSTOM_FORM`
   — and asserts `requiresCustomsDeclaration()` true while the stamp is false. The gate
   itself (`07`'s constraints 3 and 4) shipped 2026-09-16 and reads this through
   `AmazonBuyShippingAdapter::customsDocumentDelivery()`, the `PostageOfferSource` method
   every seller now answers.
4. **`CUSTOM_FORM`.** `documentSpecification()` now requests it whenever the chosen print
   option declares it, mandatory or not, and never otherwise. `labelFrom()` reads a
   `CUSTOM_FORM` beside the label — the `LABEL` filter is untouched, so a lone customs form
   still fails loudly — into `AmazonPurchasedLabel::customsFormData` /
   `customsFormFormat`, and the adapter's `ShipResponse` carries both. **Format carried
   end to end:** `ShipResponse::customsFormFormat` (default `pdf`), a new
   `packages.customs_form_format` column (default `pdf`, reset on void), `PrintRequest`,
   the `print-label` and `print-batch-labels` dispatches, and `printReport()` in the QZ
   component, which already took a format argument nothing was passing. UPS and Shopify
   keep their PDF default. On review: a **ZPL** customs form is label stock, not paper —
   `printCustomsForm()` in the QZ component sends it raw to the label printer at the
   label's DPI, and only PDF and raster forms go to the report printer; and a **failed
   schema fetch is forgotten**, not negatively cached, so the next parse asks again.

**Verification of `GetAdditionalInputsSchema`.** `sandbox_mode` is on locally and is a
shared toggle, so the production run was not made non-interactively. The sandbox's one
static case (`requestToken: amzn1.rq.123456789.101`) was sent through the real request
class and connector: **HTTP 200, `{"payload":{}}`**, parsed as an empty schema — endpoint,
LWA signing, query and parsing all exercised. `probe-13-additional-inputs-schema.php`
does the production half when run with the toggle off: quotes the newest Amazon package
and asks for the schema of every offer, saving the answers beside the other captures.
`09`'s earlier production capture (`probe-09-additional-inputs-not-required-*.json`) shows
the shape to expect: `{"title":"Additional Inputs","properties":[],"type":"object"}`.

Fixtures: `amazonInternationalRate()`, `amazonPurchaseWithCustomsFormResponse()` and
`amazonAdditionalInputsSchema()` in `tests/Feature/AmazonBuyShippingTest.php`, validated
against `Rate`, `PurchaseShipmentResponse` and `GetAdditionalInputsResponse` in the
vendored `shippingV2.json`.
