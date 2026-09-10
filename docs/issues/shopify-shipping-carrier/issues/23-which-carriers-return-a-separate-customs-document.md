# Which carriers return a separate customs document, and which fuse it into the label

Status: ready-for-human

Repo: `polybag`

## Problem

`07` decided to print the customs form to the report printer, and to require a report
printer be configured before a package that needs a customs declaration can be bought.
That gate needs a predicate, and the obvious one is wrong in a way that blocks real work.

`AddressData::requiresCustomsDeclaration()` answers **"does this shipment carry a customs
declaration?"** The gate needs **"does this purchase return a second document that has to
be printed on paper?"** The first is a superset of the second. USPS is understood to fuse
the customs form into the label PDF as extra pages rather than returning it separately —
if that holds, gating on the predicate would refuse to buy APO/FPO and territory labels on
workstations that have no report printer and never needed one.

Only Shopify's behaviour has been observed, on one international purchase (`01` question 6,
answered 2026-09-09): a separate `CUSTOMS_FORM` document, PDF, three Letter pages, a UPS
commercial invoice.

So the gate in `07` should be a **per-carrier capability**, and this issue is what fills it
in. `07`'s storage and printing work does not wait on this — only the pre-purchase gate does.

## What to answer

For each source PolyBag can buy an international label through, buy one and record:

1. **Does a second document come back at all**, or is the customs form part of the label
   document?
2. **If it is part of the label**, how — extra pages in the same PDF, or a taller label?
   This decides whether the existing 4×6 thermal path even prints it correctly, which is a
   separate defect from anything in `07` if the answer is "it silently crops".
3. **If it is separate**, in what field, what format, and what page size?

| Source | Field to check | Expectation going in |
|---|---|---|
| USPS | label response | fused into the label; **this is the one that decides the gate** |
| UPS | `ShipmentResults.Form.Image` | separate; the adapter sends `InternationalForms` and never reads the response half |
| FedEx | `shipmentDocuments` | separate when ETD does not file electronically; the adapter reads neither |
| Amazon | `packageDocuments` by `type` | separate; SP-API documents `CUSTOMS_FORM` and `EXPORT_DECLARATION` |
| Shopify | `shippingDocuments` | **answered** — separate, PDF, three Letter pages |

## Why this is `ready-for-human`

It needs real or sandbox purchases against four carrier accounts and someone looking at
what comes back. Sandbox is fine where it produces a document; where it does not, this
waits for a real international parcel rather than guessing.

The four are independent — whoever has one account can answer that row without the others,
the way `14` is structured. USPS is worth doing first and alone, because it is the row the
gate actually turns on.

## Test notes

Whatever this finds lands as a per-carrier capability the `07` gate reads, plus tests that
a carrier which fuses its customs form is **not** blocked by a missing report printer, and
one which returns a separate document **is**. Both directions matter: the first is the
over-block this issue exists to prevent.
