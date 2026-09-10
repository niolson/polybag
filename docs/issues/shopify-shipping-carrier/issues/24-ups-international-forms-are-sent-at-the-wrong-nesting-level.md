# UPS international forms are sent at the wrong nesting level, so no customs invoice is ever requested

Status: done — fixed 2026-09-10; the misplacement hid four further bugs in the payload, the last of them found on a printed invoice

Repo: `polybag`

## Problem

`UpsAdapter::createShipment()` attaches the international commercial invoice to the
shipment like this:

```php
if ($request->toAddress->country !== 'US' && ! empty($request->customsItems)) {
    $shipment['InternationalForms'] = $this->buildCustomsDetail($request);
}
```

UPS's own OpenAPI document — vendored at `tests/Fixtures/Schemas/upsShipping.json`, from
`UPS-API/api-documentation` — puts `InternationalForms` one level deeper. It is a property
of `Shipment_ShipmentServiceOptions`, alongside `SaturdayDeliveryIndicator`, `COD` and
`DeliveryConfirmation`. `ShipmentRequest_Shipment` has **no** `InternationalForms` property
of its own; it has `ShipmentServiceOptions`.

So the correct placement is `Shipment.ShipmentServiceOptions.InternationalForms`, and what
PolyBag sends is an unknown property one level up. UPS accepts the shipment and ignores it.

## Observed, not deduced

A real international purchase (JP, UPS Worldwide Expedited) is in the `ups-validation` log
for the day it was bought. Four shipment requests were logged that day; the two domestic
ones sent no forms, and the two international ones both sent `InternationalForms` at
shipment level with `FormType: {Code: "01", Description: "Invoice"}`.

The response that came back carries these `ShipmentResults` keys, and no others:

```
BillingWeight, PackageResults, ShipmentCharges, ShipmentIdentificationNumber
```

**No `Form`.** The label stored for that package is a single-frame GIF, 1400×800 — one
image, the label only, with nothing fused into it. The commercial invoice does not exist
anywhere: not as a separate document, not as extra frames, not on paper.

## Why this was not caught

Two independent reasons, and both are worth fixing alongside the placement.

1. **The test named "international" never reaches the branch.** `upsShipRequestTo()`
   passes no `customsItems`, so `! empty($request->customsItems)` is false and
   `InternationalForms` is never added. The test is international by destination address
   only, and `it('builds an international label request that conforms to the UPS Shipping
   schema')` has therefore never validated a request containing forms.
2. **Schema conformance cannot catch a misplaced optional property.** UPS's OpenAPI leaves
   `additionalProperties` unset on `ShipmentRequest_Shipment`, so JSON Schema treats an
   unexpected `InternationalForms` as a permitted extra. `assertMatchesUpsSchema()` passes
   a body that UPS itself will ignore. This is the failure mode the
   `carrier-request-schema-validation` directory is about, seen from the other side:
   validation proves a request is *not malformed*, never that it says what was meant.

## What to fix

- Move the assignment under `ShipmentServiceOptions`, **merging rather than assigning**.
  `ShipmentServiceOptions` is already written wholesale for Saturday delivery a few lines
  above (`$shipment['ShipmentServiceOptions'] = ['SaturdayDeliveryIndicator' => '']`) and
  `unset()` in full on the Saturday-rejection retry. A naive `$shipment['ShipmentServiceOptions']['InternationalForms'] = …`
  is correct only if the Saturday path stops clobbering it, and the retry must drop only
  the Saturday key rather than the whole node — otherwise a Saturday retry silently strips
  the customs invoice from an international shipment.
- Give the schema test customs items, so the branch it claims to cover is actually built.
- Assert the **placement**, not just conformance: a test that the built body has
  `Shipment.ShipmentServiceOptions.InternationalForms` and no `Shipment.InternationalForms`.
  Schema validation will not do this for you, per above.

## What this does not settle

Whether UPS returns `ShipmentResults.Form.Image` once asked correctly. That is `23`'s UPS
row, and it cannot be answered until this is fixed — every observation available today was
taken from a request UPS ignored. The account may also be enabled for paperless invoicing,
which would suppress the document for its own reasons; that is distinguishable only after
the request is right.

`UpsAdapter` reads `PackageResults[0].ShippingLabel.GraphicImage` and never looks at
`ShipmentResults.Form`, so even a correctly requested form would be dropped today. That
half is `23`/`07` work rather than this issue's.

## Related

- `23` — the UPS row is blocked on this
- `07` — the customs-form print gate, which needs `23`
- `carrier-request-schema-validation` — the limits of schema conformance, demonstrated here

## Comments

### 2026-09-10 — fixed, and moving it exposed a second bug underneath

The placement fix is one line each way, and it did not stand alone.

**Moving `InternationalForms` under `ShipmentServiceOptions` made the schema check it for
the first time, and it failed.** While the node sat at an unknown key, JSON Schema skipped
the entire subtree; nested where UPS defines it, `assertMatchesUpsSchema()` immediately
reported two shape errors against UPS's own document:

| Field | Sent | UPS's schema |
|---|---|---|
| `FormType` | `{Code: '01', Description: 'Invoice'}` | array of 2-char strings, up to 6 |
| `Product[].Description` | a string | array of up to 3 strings, 35 chars each |

Both are now `['01']` and `[$description]`. This matters more than the placement: moving a
malformed payload into a place UPS reads would have traded a silently ignored invoice for a
rejected shipment — a purchase failing after the box is taped shut, which is the failure
mode `19` was about. The two fixes only make sense together.

It is also the clearest possible demonstration of the point in the issue body. Schema
conformance proved nothing about this subtree for as long as the subtree was in the wrong
place, and the test asserting conformance passed throughout.

**Tests.** `upsShipRequestTo()` takes customs items and special service codes, so the
"international" schema test now builds an international *forms* request rather than merely
addressing Canada. Added a test asserting the placement directly — `Shipment` has no
`InternationalForms` key, `ShipmentServiceOptions` does, and both array shapes are what UPS
documents — since schema validation cannot catch a misplaced optional property. Added one
that Saturday delivery and the customs invoice coexist under the same node, which is the
merge the fix required. Full suite green: 2179 passed, 2 skipped.

**A third test was written and then removed**, and it spun out
[`25`](25-the-ups-saturday-rejection-retry-is-unreachable.md). The issue body asked for the
Saturday-rejection retry to drop only its own key rather than the whole node, and a test for
that cannot pass: the retry branch is unreachable, because `UpsConnector` sets `$tries = 3`
and Saloon's `throwOnMaxTries` defaults to true, so a UPS 4xx throws into the adapter's catch
instead of returning a failed response. The `unset()` was made surgical anyway — reviving the
retry will not reintroduce this bug — and a comment where the test would have gone says why
it is absent.

**What is still not known** is whether UPS returns `ShipmentResults.Form.Image` now that it
is being asked properly. Nothing here proves it does; it removes the reason it could not.
`23`'s UPS row needs one international purchase to answer, and `UpsAdapter` still reads only
`PackageResults[0].ShippingLabel.GraphicImage`, so the form would be dropped on arrival —
that half belongs to `23`/`07`.

### 2026-09-10 — three fields UPS enforces and its schema does not, then a wrong number on paper

The fix above was correct and insufficient. Sending the forms where UPS reads them means UPS
validates them, and it took three live purchases to get one accepted — each returning exactly
one more field. Recorded in order, because the pattern matters more than the list:

| Attempt | UPS said | Missing |
|---|---|---|
| 1 | `9120800` Missing contact information | `InternationalForms.Contacts.SoldTo` |
| 2 | `120502` InvoiceLineTotal MonetaryValue must be greater than 0 | `Shipment.InvoiceLineTotal` |
| 3 | — accepted | |

Neither is marked required in UPS's OpenAPI: `Contacts` is optional on
`ShipmentServiceOptions_InternationalForms`, and `InvoiceLineTotal` is optional on
`ShipmentRequest_Shipment`. So the schema test passed at every step while UPS refused the
purchase, which is the same lesson as the body above with the emphasis moved — conformance
proves a request is not malformed, and says nothing about whether the carrier will accept it.

`InvoiceLineTotal` was the more embarrassing of the two: `buildRateInvoiceLineTotal()` has
existed on the **rate** path all along, with a docblock recording that a US→Japan quote failed
without it. The ship path simply never had a counterpart. It is now summed from the customs
items rather than from `shipments.value`, so it agrees with the invoice's own lines.

**Then code review found the fourth, and the printed invoice proved it.** `buildCustomsDetail()`
was sending `Unit.Value` as the *extended* line total (`unitValue × quantity`) while also
sending `Unit.Number` as the quantity. UPS prints that field as **"Unit Value"** and multiplies
it by Number. On a real sandbox invoice, three line items with a true goods value of **$164.78**
printed as:

```
Units  U/M  Description               Unit Value   Total Value
   2   PCS  (a two-quantity item)          99.9        199.8
   1   PCS  …                             42.08        42.08
   1   PCS  …                              22.8         22.8
                              Total Invoice Amount:   264.68
```

**$264.68 declared for $164.78 of goods.** Not a rejection — UPS accepted it, printed it, and
the recipient could have been charged duty on the inflated figure. `Unit.Value` is now the
price of one.

That purchase also settled which of the two value fields is authoritative, and it is not the
one the errors made look important: **UPS ignored the `Shipment.InvoiceLineTotal` we sent**
(164.78) and computed the printed total from the line items (264.68). So `InvoiceLineTotal` is
required to get past `120502` and does not reach the paperwork; only the products print. The
test that guards this now reproduces UPS's own arithmetic — summing `Number × Value` across the
lines and asserting it equals the declared `InvoiceLineTotal` — rather than pinning either
field on its own, because the failure mode is the two disagreeing.

**The `Unit.Value` bug predates all of this week's work.** It was unreachable for as long as
UPS discarded the forms, which is the through-line of this issue: one misplaced key kept a
whole subtree from ever executing, and every defect inside it — two shape errors, two missing
required fields, one wrong number — surfaced only once it did. Anything gated behind a
silently-ignored payload is untested by definition, however green the suite looks.
