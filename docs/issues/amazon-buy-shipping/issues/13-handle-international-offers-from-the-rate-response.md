# Handle international offers from what the rate response declares

Status: ready-for-agent

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

- [ ] A rate requiring additional inputs is never offered, and the drop is visible as an
      observation
- [ ] The additional-inputs schema is fetched and recorded for any rate that asks, with no
      order data in the record
- [ ] `GetAdditionalInputsSchema` verified against production on a domestic offer
- [ ] The Amazon capability the `07` gate reads is derived from the offering's document
      details, and the territory shape is not gated
- [ ] A `CUSTOM_FORM` package document reaches `customs_form_data` and prints through the
      `07` path, format carried
- [ ] Fixtures for every shape above, schema-validated

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
