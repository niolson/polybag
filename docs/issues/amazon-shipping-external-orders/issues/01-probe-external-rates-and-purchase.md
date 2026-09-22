# Probe off-Amazon (`EXTERNAL`) rating and purchase against the seller account

Status: ready-for-human

Repo: `polybag`

Type: HITL — needs live sandbox (and ideally production) calls against the seller account.

## What to build

Before any adapter code is written for off-Amazon Amazon Shipping, find out what Shipping
v2 actually does with `channelType: EXTERNAL` for our seller credentials — the same
role `amazon-buy-shipping/01` played for the on-Amazon path.

Call `getRates` for a representative domestic parcel with no Amazon order ID, record the
response, and, where the sandbox or a test purchase allows, call `purchaseShipment` on one
returned rate. Captures go in `.scratch/amazon-shipping-v2/`, not in this file — they carry
addresses and account identifiers.

Questions the probe must answer:

- Which request fields `EXTERNAL` requires that the `AMAZON` payload does not send
  (item list, declared value, channel details), and which it rejects.
- Which carriers and services come back. The expectation from Amazon's documentation is
  Amazon Shipping only; record whether anything else appears.
- Whether rates carry `requiresAdditionalInputs`, and which documents
  `supportedDocumentSpecifications` declares.
- Whether the seller account needs a separate Amazon Shipping enrollment or ToS acceptance
  before `EXTERNAL` returns anything, and what the error looks like when it does not have
  one (empty rate list vs. `ineligibleRates` vs. an error).
- Whether `getTracking` and `cancelShipment` behave the same for an `EXTERNAL` shipment.

### Sandbox first, then production

Amazon publishes an example `EXTERNAL` body, saved as
`.scratch/amazon-shipping-v2/getrates-request-sandbox-external.json`. It is a Kent, WA →
Seattle, WA parcel with one item and no `amazonOrderDetails`. Amazon marks the Shipping v2
sandbox `dynamic`, and it proved to be one: it validates the body and prices it, so it
answers the request-shape questions as well. It cannot say which carriers a real lane
offers, or what an account that is not enrolled sees. Those still need a production
`getRates`, which is free.

## Acceptance criteria

- [x] A probe script under `.scratch/amazon-shipping-v2/` issues an `EXTERNAL` `getRates`
      with the existing Amazon connection's credentials (`probe-01-external.php`; sandbox
      run 2026-09-22)
- [ ] The same script run against production (`sandbox_mode` off — rates only, nothing
      bought)
- [ ] Each answer is marked as observed in the sandbox or in production
- [ ] The response (redacted as needed) is captured and each question above is answered
      in `## Comments` here
- [ ] Any enrollment or account prerequisite is recorded, with what a tenant must do to
      satisfy it
- [ ] `06` is updated if the payload differs from what it assumes

## Blocked by

None - can start immediately

## Comments

### 2026-09-22 — sandbox run

`probe-01-external.php`, against the sandbox with the existing Amazon connection. Captures
are `probe-01-sandbox-*.json` in `.scratch/amazon-shipping-v2/`.

**Request shape** (sandbox):

- Amazon's own example is refused with `400 InvalidInput`: "Total items weight exceeds the
  declared package weight." Its item weighs 10 lb inside a 1.52 lb package. The sandbox
  validates the body.
- `items` is **required**. Leaving it out fails: `packages.1.member.items ... must not be
  null`. `insuredValue` is required too; value `1` was accepted, and whether `0` is
  accepted as it is for `AMAZON` is still unchecked for `EXTERNAL`.
- `isHazmat` and the `email` fields on either address are optional.
- `itemIdentifier` is free text (`"T shirt"`), not an Amazon order-item ID.
- The body is priced, not canned. The same parcel cost $7.90 to Seattle and $12.16 at
  4.2 lb to Nashville.

**Rates** (sandbox): one rate, Amazon Shipping Ground (`AMZN_US` / `std-us-swa-mfn`),
with `requiresAdditionalInputs: false`, no value-added service groups, no
`ineligibleRates`, and document specs for PNG, ZPL and PDF, all 4×6. Whether a real lane
adds anything besides Amazon Shipping is a production question.

**Purchase** (sandbox): `purchaseShipment` with the adapter's own
`documentSpecification()` returned 200. The response had a `shipmentId`, one PNG `LABEL`
with no packing slip (unlike on-Amazon PDF, which requires a `PACKSLIP`), a `TBA…`
tracking ID, a pickup/delivery `promise`, and `totalCharge`. `packageClientReferenceId`
was echoed back.

**Tracking and cancellation** (sandbox): `getTracking` with carrier `AMZN_US` and
`cancelShipment` both returned 200 and work unchanged. Tracking returns the sandbox's
fixed 2019 event history.

**What this means for `05`:** the `AMAZON` payload builder already sends
`insuredValue` and `items`, so the `EXTERNAL` body is that builder minus
`amazonOrderDetails`, with a different source for `items`. Items currently come from
Amazon order lines (`AmazonOrderItems::shippingItemsFor`). Off-Amazon they have to come
from the Shipment's own items, and their weights come from product records. A Package
whose product weights add up to more than its scanned weight is refused outright, so
`05` needs to cap item weights at the package weight. Bad product weights are known to
occur.

**Still open, for production:** which carriers and services a real lane returns, whether
the account needs an Amazon Shipping enrollment, and what an account without one sees.
