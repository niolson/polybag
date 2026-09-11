# UPS international forms are sent at the wrong nesting level, so no customs invoice is ever requested

Status: done — 2026-09-10

Repo: `polybag`

## Problem

`UpsAdapter::createShipment()` attached the commercial invoice as
`$shipment['InternationalForms']`. UPS's own vendored OpenAPI puts it one level deeper — a
property of `Shipment_ShipmentServiceOptions` — and `ShipmentRequest_Shipment` has no
`InternationalForms` property of its own. **UPS accepted the shipment and ignored it.**

Observed, not deduced: a real international purchase sent `InternationalForms` at shipment
level with `FormType: {Code: "01"}`, and the response carried no `Form` key at all. The
stored label was a single-frame GIF — the label only. The commercial invoice did not exist
anywhere: not as a separate document, not as extra frames, not on paper.

## Why it was not caught

1. **The test named "international" never reached the branch.** `upsShipRequestTo()` passed
   no `customsItems`, so the guard was false and the forms were never added. The test was
   international by destination address only.
2. **Schema conformance cannot catch a misplaced optional property.** UPS's OpenAPI leaves
   `additionalProperties` unset, so JSON Schema treats an unexpected `InternationalForms` as
   a permitted extra. `assertMatchesUpsSchema()` passed a body UPS itself would ignore. The
   same lesson `carrier-request-schema-validation` exists for, seen from the other side:
   validation proves a request is *not malformed*, never that it says what was meant.

## What shipped

The assignment moved under `ShipmentServiceOptions`, **merging rather than assigning** —
the node is already written wholesale for Saturday delivery, and the Saturday-rejection
retry `unset()`s it, so a naive assignment would have let a Saturday retry silently strip
the customs invoice from an international shipment. The `unset()` was made surgical.

Tests: `upsShipRequestTo()` now takes customs items and special service codes, so the
schema test builds an international *forms* request rather than merely addressing Canada; a
test asserts the **placement** directly, since schema validation cannot; and one asserts
Saturday delivery and the customs invoice coexist under the same node.

## What the misplacement was hiding

One misplaced key kept a whole subtree from ever executing, and **every defect inside it
surfaced only once it did.** In order:

| Found by | Field | Was | Should be |
|---|---|---|---|
| Schema, immediately | `FormType` | `{Code: '01', Description: 'Invoice'}` | array of 2-char strings |
| Schema, immediately | `Product[].Description` | a string | array of up to 3 strings, 35 chars |
| UPS `9120800` | `InternationalForms.Contacts.SoldTo` | absent | required in practice |
| UPS `120502` | `Shipment.InvoiceLineTotal` | absent | required in practice |
| Code review | `Product[].Unit.Value` | the **extended** line total | the price of **one** |

The two shape errors mattered more than the placement: moving a malformed payload into a
place UPS reads would have traded a silently ignored invoice for a **rejected shipment**,
which is a purchase failing after the box is taped shut.

The two missing fields are marked optional in UPS's OpenAPI, so the schema test passed at
every step while UPS refused the purchase — the same lesson as above with the emphasis
moved. `InvoiceLineTotal` was the more embarrassing: `buildRateInvoiceLineTotal()` has
existed on the **rate** path all along, with a docblock recording that a US→Japan quote
failed without it; the ship path never had a counterpart. It is summed from the customs
items so it agrees with the invoice's own lines.

**`Unit.Value` was a wrong number on a legal document.** UPS prints that field as "Unit
Value" and multiplies it by `Unit.Number`, so three line items with a true goods value of
**$164.78** printed a total of **$264.68**. Not a rejection — UPS accepted it, printed it,
and the recipient could have been charged duty on the inflated figure.

That purchase also settled which value field is authoritative, and it is not the one the
errors made look important: **UPS ignored the `InvoiceLineTotal` we sent** and computed the
printed total from the line items. So `InvoiceLineTotal` is required to get past `120502`
and never reaches the paperwork. The test reproduces UPS's own arithmetic — summing
`Number × Value` across the lines and asserting it equals the declared `InvoiceLineTotal` —
rather than pinning either field alone, because the failure mode is the two disagreeing.

## Spun out

- **`25`** — a test for the Saturday-rejection retry could not be made to pass: the branch
  is unreachable, because a UPS 4xx throws into the adapter's catch rather than returning a
  failed response. A comment where the test would have gone says why it is absent.
- **`23`'s UPS row**, which this unblocked. UPS returned `ShipmentResults.Form.Image` on the
  first purchase afterwards.

## Related

- `23` — which carriers return a separate customs document
- `07` — the customs-form print path that now receives UPS's
- `carrier-request-schema-validation` — the limits of schema conformance, demonstrated here
