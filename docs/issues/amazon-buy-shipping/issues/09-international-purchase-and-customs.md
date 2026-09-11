# Buy one international label and see what Amazon does with customs

Status: ready-for-human — sandbox ruled out 2026-09-11; PR, GU, MP and AS quoted and all found domestic; a purchase was attempted and refused because the order had shipped; needs an unshipped non-US order placed in the seller account. Fix `10` first

Repo: `polybag`

## Problem

Nothing in the Amazon Buy Shipping adapter knows a shipment is international. `03`
shipped against the `01` capture, which was one domestic parcel, and its one unticked
acceptance criterion is still that nothing has run against a live order at all. Meanwhile
`shopify-shipping-carrier/07`'s pre-purchase gate — "does this purchase return a second
document that has to be printed on paper?" — is waiting on
[`23`](../../shopify-shipping-carrier/issues/23-which-carriers-return-a-separate-customs-document.md)'s
Amazon row, which is **Unknown** because no international Amazon purchase exists.

`23` frames its Amazon row as one observation. Reading the vendored Shipping v2 schema
against the adapter, it is more than that: three things in the purchase path are wrong or
absent for a cross-border parcel, and the observation is what tells us which of them
matter.

### What the adapter does today

- **`getRates` already sends items.** `AmazonOrderItems::shippingItemsFor()` puts
  `itemValue`, `description`, `itemIdentifier`, `quantity` and `weight` on every package.
  That is the customs-shaped data Amazon has from us. It sends no HS tariff number, no
  country of origin and no `invoiceDetails`, though the schema's `Item` accepts
  `productType` and `invoiceDetails`, and `CustomsItem` — the DTO every direct adapter
  builds its declaration from — carries `hsTariffNumber` and `countryOfOrigin` that this
  adapter never reads. `products.country_of_origin` exists; nothing on the product holds an
  HS code.
- **The purchase asks for the label and nothing else.**
  `AmazonBuyShippingService::documentSpecification()` sends
  `requestedDocumentTypes: ['LABEL']`, and `labelFrom()` filters `packageDocuments` to
  `type === 'LABEL'`. The schema's `DocumentType` enum is
  `PACKSLIP | LABEL | RECEIPT | CUSTOM_FORM` — singular `CUSTOM_FORM`, which `23` spells
  `CUSTOMS_FORM`. So a customs form is both un-requested and, if Amazon returned one
  anyway, dropped.
- **`requiresAdditionalInputs` is ignored.** Every rate in the `01` capture set it
  `false`, and the tests fix it there. The schema says a purchase of a rate that sets it
  `true` must carry `additionalInputs` conforming to a JSON schema fetched per rate from
  `GET /shipping/v2/shipments/additionalInputs/schema` (`requestToken` + `rateId`). There
  is no request class for that endpoint, `PurchaseShipmentRequest` is never given
  `additionalInputs`, and a rate that requires them would pass the quote and fail the
  purchase — the same shape as the document-spec failure `03` chose to catch at quote time.
- **There is no `GET /shipping/v2/shipments/{shipmentId}/documents` request** either. If
  `CUSTOM_FORM` is not returned inline on the purchase, that is where it would be.

Which of these the international parcel actually exercises is the question. Amazon's
international offering — where the cross-border rates come from, whether the carrier fuses
the declaration into the label as USPS does, or returns a separate document as UPS and
Shopify do — is not documented anywhere we can read it from; it has to be observed.

## What to find out

Run one international quote-and-purchase and record, per offer:

1. **`requiresAdditionalInputs`** on each international rate. If any set it, fetch the
   schema and capture it — it is the customs vocabulary Amazon wants, and it decides
   whether the adapter needs a new pre-purchase step or just richer `items`.
2. **`packageDocuments` by `type`** on the purchase response, with
   `requestedDocumentTypes` widened to `['LABEL', 'CUSTOM_FORM']`. Whether `CUSTOM_FORM`
   comes back inline, only from the documents endpoint, or not at all because the
   declaration is fused into the label.
3. **The customs document's format and size**, if there is one — Letter PDF is what the
   `07` gate exists to route to a report printer; a 4×6 would belong on the label printer,
   which is the FedEx finding `23` records.
4. **Which carriers are eligible internationally.** `01` found the domestic catalog was
   OnTrac/UPS/USPS against 105 ineligible entries; the international one is a separate
   observation, and it feeds `05`'s aliasing the same way.
5. **Whether the items we send are enough.** A rejection naming a missing HS code or origin
   country on an international quote is the answer to whether `CustomsItem`'s fields need
   to reach the request.

The `01` capture convention holds: raw responses to `.scratch/amazon-shipping-v2/`, never
committed — they carry the order and the addresses.

### The sandbox cannot answer any of this

Tried 2026-09-11, and ruled out. `getRates` and `purchaseShipment` are `dynamic` sandbox
operations, but the dynamic sandbox **ignores `shipTo`**: `01`'s saved request sent with a
US, a Canadian and a British destination returned the same single `AMZN_US` rate, the same
`rateId`, and `requiresAdditionalInputs: false` all three times — only `requestToken` and
the promise windows differed, and both are clock-derived. `getAdditionalInputs` has one
static sandbox case, and it returns `payload: {}`. Amazon's integration test-case guide has
no international, customs or additional-inputs case. So every finding above is
**production-only**, and needs an order in the seller account with a non-US destination —
the guide's own rule is that the order must belong to the account the token was issued
for, so no borrowed example ID works either. `channelType: EXTERNAL` is no way round it:
Amazon Shipping is continental-US only.

The one vocabulary hint anywhere is the documentation example beside `getAdditionalInputs`
in Amazon's OpenAPI spec: a schema with `harmonizedSystemCode` and
`packageClientReferenceId` properties — an HS code keyed per package, which is the shape
`CustomsItem::$hsTariffNumber` would feed if the real schema matches. Called in production
against a rate that does *not* require inputs, the endpoint answers 200 with an empty
schema (`properties: {}`), so the request class can be called on every offer and the
answer read for whether it is empty.

The probes are in `.scratch/amazon-shipping-v2/probe-09-*.php`, alongside their captures.

### What the territories showed

The account has shipped to four US territories and nowhere further abroad, so one order to
each was quoted (free; nothing bought): Puerto Rico ×3, Guam, the Northern Marianas and
American Samoa. **Every one returned only plain domestic USPS with `requiresAdditionalInputs:
false`** — Priority Mail Express for PR and AS (old orders, past their promise, so the
Express-only skew is `01`'s), Ground Advantage at $10.05 for GU and $6.24 for MP. The
ineligible list is the finding: Amazon's USPS catalog carries parallel `(Customs)` variants
of Priority Mail Express, Priority Mail, Ground Advantage and the Large Flat Rate Box, and for
all four territories **every `(Customs)` variant and every USPS International service is
refused while the plain domestic one is offered**. That holds for GU, MP and AS, which sit
outside the US customs territory and for which USPS's own rule (DMM 608.2.4) wants a
declaration — so whatever Amazon's `(Customs)` variants are for, it is not territories, and
no US-addressed parcel this account can produce ever reaches `getAdditionalInputs`.

Meanwhile `AddressData::customsZone()` puts each of these in its own zone (`US-PR`, `US-GU`,
…), so `requiresCustomsDeclaration()` is **true** for all of them. A `07` gate on that
predicate would refuse every one of these purchases for want of a report printer, and
Amazon would have returned a domestic label. That is the over-block `23` predicted, observed
through Amazon on four territories rather than inferred. Whether the label comes back as one
4×6 page or a fused multi-page CP72 the way USPS direct returns it is a purchase-only
question — but nothing in the quote gives a separate paper document anywhere to come from.

Also noted: UPS Worldwide Expedited/Express/Saver were refused for PR and GU with a
*different* message from everything else — "cannot be purchased; please try a different
selection" — so UPS evaluated them as international and fell at the account, not the
destination. The MP and AS addresses are PO boxes, common there, which took UPS and DHL out
with "carrier cannot ship to a po box" before that.

What the territories do **not** settle is the cross-border case. Findings 1–3 and 5 still
need a non-US, non-territory order.

### The purchase attempt, and what stopped it

A buy-and-void was tried on the Guam order (Ground Advantage, $10.05) and then on the newest
territory order in the account (Northern Marianas, 2025-11, $6.24). Amazon refused the
adapter's body twice before looking at the order — the document specification, then the
value-added services — which is [`10`](10-purchase-body-is-refused-by-amazon.md), a shipped
defect on every carrier in both formats, with the rules now established by oracle. With a
valid body both orders were refused at the last step: *"Order doesn't exist in Rigel"* —
Amazon's open-order pool. **A shipped order cannot be bought against, however recent.** So
the purchase path, `03`'s live-run criterion and the shape of the returned documents all wait
on an order that has not shipped yet; the customs half additionally needs it to be foreign.

## What to build

Whatever the run finds, wired into the existing seams rather than beside them:

- **`CUSTOM_FORM` → `ShipResponse::customsFormData`**, the same base64 slot `ShopifyAdapter`
  and `UpsAdapter` fill, so `07`'s storage and report-printer printing apply without
  change. Request it in `requestedDocumentTypes`; read it in `labelFrom()`'s sibling rather
  than widening the label filter.
- **`additionalInputs`**, if any international rate demands them: a `GetAdditionalInputsSchema`
  request, and either a pre-purchase drop of the rate at quote time (the `03` pattern for
  what would fail the purchase) or a payload built from `CustomsItem`, depending on what the
  schema asks for. The choice waits on finding 1.
- **`23`'s Amazon row**, filled in with the observation, and the per-carrier capability the
  `07` gate reads.
- **Tests from the capture**, the way `03` built its 22 from `01`'s: an international rate
  with `requiresAdditionalInputs` true, a purchase response carrying both `LABEL` and
  `CUSTOM_FORM`, both validated against `shippingV2.json`.

## Acceptance criteria

- [ ] An international `getRates` and `purchaseShipment` have been run and captured, with
      the environment recorded
- [ ] `23`'s Amazon row says Fused / Separate / Not offered, and why
- [ ] A separately-returned `CUSTOM_FORM` reaches `customs_form_data` and prints to the
      report printer through the `07` path
- [ ] A rate that requires additional inputs is either satisfied or dropped at quote time,
      never failed at purchase
- [ ] Fixtures for the international shapes, schema-validated

## Blocked by

An **unshipped** Amazon order in the seller account with a genuinely foreign destination.
Shipped orders are refused at purchase ("doesn't exist in Rigel"), and the account has no
foreign ones anyway — only US territories, which Amazon treats as domestic — so one has to be
placed: the shipping template must offer a non-US country, a buyer at a foreign
address orders, the quote and `getAdditionalInputs` are read for free, then one label is
bought and voided, and the order cancelled.

## Related

- [`03`](03-amazon-buy-shipping-adapter.md) — the adapter; its "no live run" criterion is
  what this run also discharges
- [`04`](04-per-api-sandbox-host.md) — why the sandbox is reachable
- [`shopify-shipping-carrier/07`](../../shopify-shipping-carrier/issues/07-customs-form-printing.md)
  — the gate waiting on this
- [`shopify-shipping-carrier/23`](../../shopify-shipping-carrier/issues/23-which-carriers-return-a-separate-customs-document.md)
  — the per-carrier table; this issue owns its Amazon row
- [`shopify-shipping-carrier/26`](../../shopify-shipping-carrier/issues/26-a-zero-value-customs-item-breaks-or-understates-the-invoice.md)
  — the zero-value refusal runs in front of this adapter too, so the test order needs
  valued items

## Comments

### 2026-09-11 — opened

Split out of `23`'s Amazon row because the row is an observation and the adapter work
behind it is not: `requiresAdditionalInputs`, `CUSTOM_FORM`, and the documents endpoint
are all unhandled today, and the international issues rolled into
`shopify-shipping-carrier` during the Shopify campaign already stretch that directory past
its name.

### 2026-09-11 — sandbox ruled out, four territories quoted

Ran the sandbox with three destinations and got one answer; ran production `getRates` against
the account's Puerto Rico, Guam, Northern Marianas and American Samoa orders and got
USPS-domestic with no additional inputs from all of them. Then tried to buy: two refusals of
the adapter's own body (spun out as `10`) and, once the body was valid, a refusal at the
order because it had shipped. All recorded in the body. Nothing bought. The `getAdditionalInputs` path was not reached, because
no offer asked for it — so the request class, `additionalInputs` on the purchase and the
`CUSTOM_FORM` read all still wait on a foreign order.
