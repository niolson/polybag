# Probe off-Amazon (`EXTERNAL`) rating and purchase against the seller account

Status: done

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
- [x] The same script run against production (`sandbox_mode` off — rates only, nothing
      bought; 2026-09-22 — refused, see Comments)
- [x] Each answer is marked as observed in the sandbox or in production
- [x] The response (redacted as needed) is captured and each question above is answered
      in `## Comments` here, or recorded as unanswerable without an enabled account
- [x] Any enrollment or account prerequisite is recorded, with what a tenant must do to
      satisfy it (as far as the account we have can show — see the 2026-09-22 decision)
- [x] `05` and `06` are updated where the payload differs from what they assume

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

### 2026-09-22 — production run

Run with `sandbox_mode` off, requesting rates only. Captures are `probe-01-prod-*.json`.

- **`EXTERNAL` `getRates` is refused for this seller account** with
  `403 Unauthorized`: "Access denied for this account. Please contact support. (A-101)".
  This answers the enrollment question: an account that isn't set up gets an HTTP 403,
  not an empty rate list and not `ineligibleRates`.
- **The 403 comes from `EXTERNAL` itself, not from the credentials.** The same body on
  `channelType: AMAZON` with a real order ID, sent through the same connection, got past
  authorization and failed on body validation ("Incorrect itemIdentifier was detected in
  the item list"). So the LWA token and the Shipping API role are fine. Only the off-Amazon
  channel is closed.
- The validation error for a missing `items` (`400`) comes back before the 403, which
  suggests Amazon checks the request shape before it checks access to the channel.
  Production agrees with the sandbox that `items` is required.

**What a tenant has to do:** unknown until Amazon support answers. A-101 says "contact
support", and the likely cause is that off-Amazon shipping needs the seller to sign up
separately for Amazon Shipping, apart from Buy Shipping for Amazon orders. That is a
guess and has not been confirmed. Next step: open a Seller Support / SP-API case quoting
A-101 and `channelType: EXTERNAL`, and ask what enables it, whether it has to be done per
seller account, and whether it is limited to certain regions.

**Adapter consequence (for `04`/`05`):** a connection can be allowed to sell on-Amazon
postage and still be refused off-Amazon. Resolution must not assume that a working Amazon
connection can quote `EXTERNAL`. The A-101 403 should mark that connection as not enabled
for off-Amazon shipping, with a message saying so, not a generic Amazon error.

Still open, and blocked on enablement: which carriers and services a real lane returns,
and whether production tracking and cancellation match the sandbox.

### 2026-09-22 — second pass: body rules and label formats

Script: `probe-01b-shape-and-formats.php`. Captures: `probe-01b-validation.json` and
`probe-01b-formats.json`.

**Using production to check bodies.** Production validates the request body before it
checks access, so for this account every body either fails with a `400` that names the
problem or passes and gets the `403 A-101`. The same variants were sent to the sandbox.
The two agreed on every rule. The sandbox's messages leave out Amazon's `D-` codes, and
it priced the variants that production let through.

| Variant | Result (prod / sandbox) |
|---|---|
| `insuredValue` value `0` | passes (sandbox priced it the same) |
| `insuredValue` missing | 400, "must not be null" |
| `items: []` | 400, length must be ≥ 1 |
| item without `weight` | 400 `D-725`, "Item weight is required for all package items" |
| item `weight` 0 | passes |
| items total > package weight: 3 × 0.6 lb in 1.52 lb | 400 `D-703`, "Total items weight exceeds package weight" |
| items total 1.53 lb in 1.52 lb | passes, so there is some small tolerance, size unknown |
| item without `itemIdentifier`, `itemValue` or `description` | passes, all three optional |
| no phone, email or company on either address | passes |
| `amazonOrderDetails` on `EXTERNAL` | 400 `D-722`, "not supported for EXTERNAL channelType" |
| no `channelDetails` | 400, "must not be null" |
| two packages in one request | 400, `packages` length must be ≤ 1 |

The item-weight total is `weight × quantity` summed across the items.

**Label formats (sandbox).** Each format was bought, fetched again with
`getShipmentDocuments` and cancelled, and every call returned 200. Each rate offers PNG,
ZPL and PDF, all 4×6. Every print option offers ZPL at 203 and 300 DPI, `LABEL` as the
only document (no `PACKSLIP` in any format) and file joining `[false]` only. For PNG and
ZPL, `AmazonBuyShippingService::documentSpecification()` builds a spec the sandbox
accepts. For `pdf` its preference list picks PNG, as it does on-Amazon.

**Reprint.** `getShipmentDocuments` works with `shipmentId`, `packageClientReferenceId`
and `format`. In the sandbox it returned a different, numeric tracking ID from the one the
purchase gave (`TBA…`). That is almost certainly the dynamic sandbox making things up,
but it is also a reason for the Label to keep the tracking ID from the purchase and never
overwrite it from a reprint.

### 2026-09-22 — decision: build on the sandbox

The seller account we have is defunct and will never sign up for Amazon Shipping, and we
have no live account that has. The rest of the work goes ahead on these assumptions:

1. **`403 A-101` on an `EXTERNAL` `getRates` means the account is not set up for Amazon
   Shipping.** It is the only signal available: no Shipping v2 or Sellers API operation
   reports whether an account has signed up. It is inferred from a single account.
   Because the body is validated first, a check must send a valid body, or a `400` will
   hide the answer.
2. **The sandbox shows what a set-up account does.** Rates, purchase, reprint, tracking
   and cancel are built and tested against it. What stays unconfirmed until a customer
   with an Amazon Shipping account turns this on: which carriers and services a real lane
   returns (Amazon's docs say Amazon Shipping only), real charges and
   `totalChargeWithAdjustments`, and production tracking and cancellation.

The PRD carries the second point as a project risk.
