# Which carriers return a separate customs document, and which fuse it into the label

Status: ready-for-human — USPS (fused) and UPS (separate) both answered 2026-09-10 and UPS is wired up; FedEx and Amazon need a document to be requested before one can be observed

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

Only Shopify's behaviour had been observed when this was written, on one international
purchase (`01` question 6, answered 2026-09-09): a separate `CUSTOMS_FORM` document, PDF,
three Letter pages, a UPS commercial invoice. **USPS was answered 2026-09-10** — it fuses,
in both label formats, and needed no new purchase. See the comment below.

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
| USPS | label response | **answered** — fused, 3×4×6 pages (PDF) / 3 `^XA` blocks (ZPL); the gate must not block it |
| UPS | `ShipmentResults.Form.Image` | **answered** — separate, PDF, 3 Letter pages (one invoice in triplicate); read into `customsFormData` |
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

## Comments

### 2026-09-10 — USPS answered, and it needed no purchase at all

The row that decides the gate is closed, from labels PolyBag had already bought. This issue
was filed `ready-for-human` on the assumption that every row needs someone to buy an
international label and look at what comes back. For USPS that was already done: nine
international PDF labels and one ZPL are sitting in `packages.label_data`, across seven
destination countries (BR, JP, PT, SG, SI, SK, UY) and two mail classes.

**USPS fuses the customs declaration into the label, and does it in both formats.**

Every one of the nine PDFs is identical in shape — 3 pages, 432×288 pts (4×6 landscape),
producer `Apache FOP`. Every page of every one is a CP72 customs declaration, and in all
nine it is page 1 that carries `U.S. POSTAGE PAID`; pages 2 and 3 are stamped *"Not Valid
As Proof-of-Payment for US Postage"*. There is no separate document because there is no
separate *thing* — USPS composes the CP72 as the label, and the postage is printed on
ply 1 of the form.

The ZPL label (package 121, First-Class Package International to JP) is the same document
in the other format, which is what makes this a fact about USPS rather than about PDF
rendering:

| `^XA` block | Contents |
|---|---|
| 1 | CP72 + `POSTAGE PAID` |
| 2 | CP72, `Page 2 - Not Valid As Proof-of-Payment` |
| 3 | CP72, `Page 3 - Not Valid As Proof-of-Payment` |

**So the answer to question 1 is no, and the gate must not block a USPS international
purchase on a missing report printer.** The over-block this issue was opened to prevent is
real, and now confirmed rather than suspected: gating `07` on
`AddressData::requiresCustomsDeclaration()` would refuse every USPS international label on
a workstation that has only a label printer — for a document that prints perfectly well on
the 4×6 thermal path it already has.

**Question 2 — does the 4×6 path print all three plies, or silently crop?** Nothing in the
dispatch path limits pages. `printLabel()` in `<x-qz-tray>` hands the whole PDF to QZ with
a `size: {width: 4, height: 6}` config and `scaleContent: true`, and the ZPL branch sends
the whole string raw, so all three blocks reach the printer. This is a read of the dispatch
path, not a printed test — but there is no crop mechanism to find, and the pages are
already the label's own size, so the "separate defect" this question was holding open does
not appear to exist for USPS.

**Question 3 does not arise**, but one thing next to it is worth recording.
`UspsAdapter` could not read a separate document even if USPS sent one:
`LabelResponse::parseBody()` takes `$parts[0]` as the JSON metadata and `$parts[1]` as the
label, asserts only *"at least 2 parts"*, and **discards `$parts[2]` onward without
comment**. That is not what is happening here — the customs form is demonstrably inside
part 1 — and the known third-part candidate in USPS's v3 multipart response is the receipt,
which both label paths suppress with `receiptOption: 'NONE'`. So nothing is being lost
today. It is latent rather than a defect: if `receiptOption` is ever changed, what comes
back is dropped silently instead of erroring, and the next person to look will have the
same question this issue asked.

**What this does not settle.** Only Priority Mail International and First-Class Package
International appear in the sample, and every label was bought through the same USPS
account. The three plies are the CP72's own structure rather than a per-service choice, so
the result should generalise — but a service that files electronically, if USPS ever offers
one, would be a different case. It is not one PolyBag can buy today.

**Remaining rows: UPS, FedEx, Amazon.** Each is still `ready-for-human` and independent.
Worth trying the same shortcut first for each — check `packages.label_data` for an
international label already bought on that carrier before buying a new one.

### 2026-09-10 — UPS, FedEx and Amazon checked the same way, and the premise of the table is wrong

The USPS row above closed from labels already bought, so the other three were tried the
same way before spending anything. None of them closes, but the reason they do not is a
single finding that changes what this issue is asking.

**The table's "Expectation going in" column assumes the document is offered and PolyBag
fails to read it. For UPS and FedEx that is not what happens: the document is never
requested, so there is nothing in the response to read.**

**UPS — blocked, and it spun out [`24`](24-ups-international-forms-are-sent-at-the-wrong-nesting-level.md).**
One international label exists (JP, Worldwide Expedited): a single-frame GIF, 1400×800,
the label alone with nothing fused. The response carried no `Form` key at all. That looks
like an answer until the request is read: `UpsAdapter` puts `InternationalForms` at
`Shipment.InternationalForms`, and UPS's own vendored OpenAPI puts it at
`Shipment.ShipmentServiceOptions.InternationalForms`. UPS ignored it and shipped the parcel
anyway. So every UPS observation available today was taken from a request that never asked
for an invoice, and this row cannot be answered until `24` is fixed.

**FedEx — nothing comes back, for the same shape of reason.** Three successful sandbox
international shipments carrying `customsClearanceDetail` and **not** using ETD returned
zero `shipmentDocuments` between them. No request sends `shippingDocumentSpecification`,
which is what makes FedEx generate a commercial invoice. What *is* returned is one
`packageDocuments[0].encodedLabel`, a multi-page Letter PDF — and the extra pages are not a
customs declaration but air waybill copies, stamped *"FEDEX AWB COPY - PLEASE PLACE IN
POUCH"*. Worth recording because it is the trap this issue exists to avoid: a multi-page
international label that looks like the USPS fused case and is not one. Sandbox only.

**Amazon — still unobserved, but the cheapest of the three to finish.** No international
Amazon purchase exists, and `03` records that nothing has run against a live order at all.
The code is further along than the other two, though: `AmazonBuyShippingService::labelFrom()`
already selects `packageDocuments` by `type === 'LABEL'`, so a `CUSTOMS_FORM` alongside it
would be dropped by an explicit filter rather than never fetched. Reading a second type is
a small change once there is a purchase to see.

**What this means for `07`.** The gate's per-carrier capability cannot be filled by
observation alone. For USPS it can, and is. For UPS and FedEx the honest current value is
"no separate document is returned, because none is requested" — which is true, and would
make the gate correct today, and would quietly become wrong the moment either request is
fixed. So the capability should be recorded per carrier **with its reason**, not as a bare
boolean, and `24` and the FedEx `shippingDocumentSpecification` question are prerequisites
for the UPS and FedEx rows rather than side quests.

Restating the rows as they now stand:

| Source | Separate document today? | Why |
|---|---|---|
| USPS | **No — fused** | CP72 *is* the label; 3 pages (PDF) / 3 `^XA` blocks (ZPL), postage on ply 1 |
| UPS | **Unknown** | `24` fixed the request (wrong nesting level, plus two malformed field shapes); needs one purchase to observe |
| FedEx | **No — not requested** | no `shippingDocumentSpecification`; label's extra pages are AWB pouch copies |
| Amazon | **Unknown** | no international purchase exists; `labelFrom()` filters to `type === 'LABEL'` |
| Shopify | **Yes** | separate `CUSTOMS_FORM`, PDF, three Letter pages |

### 2026-09-10 — UPS answered, on the first purchase after `24`, and it is the opposite of USPS

`24` was fixed and the next UPS international label — sandbox, Canada, UPS Standard — went
through. The operator saw **one 4×6 label and nothing else**, which looked at first like
"UPS returns nothing" and is the opposite of what happened.

`ShipmentResults` came back carrying a `Form`:

```
Code: 01, Description: "All Requested International Forms"
Image.ImageFormat: PDF
```

So **UPS returns the customs document separately, as its own PDF, even when the label
itself is a GIF.** `UpsAdapter` read only `PackageResults[0].ShippingLabel.GraphicImage`
and dropped it, which is why one label printed. The row is answered, and answered the
opposite way to USPS — which is the case for the per-carrier capability rather than a
single predicate, now demonstrated rather than argued.

**Wired up the same day.** The adapter reads `ShipmentResults.Form.Image.GraphicImage` into
`ShipResponse::customsFormData`, which `07` had already built the whole receiving end for:
`Package::markShipped()` stores it, `PrintRequest` carries it to `printReport()` at 8.5×11,
and `PurgePiiCommand` nulls it with `label_data`. `ShipResponse::success()` gained the
parameter — Shopify reaches it through the constructor, and every other adapter uses the
factory — so FedEx and Amazon can pass one line when their rows are answered.

The PDF format lines up with the report printer's default without a format flag, which is
luck rather than design: `printReport()` defaults to `pdf`, and a carrier returning its
form as a GIF would need the format carried alongside the bytes. Worth knowing before the
FedEx and Amazon rows land.

**What this did not need.** No production account and no real parcel — the label was a
sandbox sample against fake data. The two remaining rows should be reachable the same way
once their requests ask for a document at all.

### 2026-09-10 — what UPS's document actually is: one invoice, three times

Printed end to end on a second sandbox package, to the report printer, and the pages came
out looking identical. They are: the stored PDF is 3 Letter pages (612×792, producer
`ActiveReports 20`) whose extracted text is byte-identical across all three, and **each one
is headed "Page 1"**.

So it is not a three-page document. It is a **one-page commercial invoice returned in
triplicate** — the customs convention of a copy for export customs, one for import customs
and one for the consignee. `Form.Code 01` is "All Requested International For**ms**", and
this is what the plural means. The print path reproduced the document faithfully; there is
nothing to fix.

**The operational fact worth carrying into `07`'s gate is three sheets of Letter per
international UPS parcel.** That is the paper cost of requiring a report printer, and it is
per package rather than per shipment. Shopify's is also three Letter pages (`01` question
6), so two of the three known sources now land three sheets on the report printer, which
makes "is a report printer configured" a materially different question from "does this
shipment carry a customs declaration" — the distinction this issue exists to draw.

Unknown, and cheap to answer whenever someone looks at a printed one: whether the three
copies are meant to be signed individually. Nothing in the extracted text distinguishes
them, so if a signature is needed it is needed three times.
