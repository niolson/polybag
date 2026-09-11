# Which carriers return a separate customs document, and which fuse it into the label

Status: ready-for-human — USPS, UPS and FedEx answered 2026-09-10; only Amazon is unobserved, and FedEx's row goes stale the moment `shippingDocumentSpecification` or ETD is sent

Repo: `polybag`

## Problem

`07` decided to print the customs form to the report printer, and to require a report
printer be configured before a package that needs a customs declaration can be bought. That
gate needs a predicate, and the obvious one is wrong in a way that blocks real work.

`AddressData::requiresCustomsDeclaration()` answers **"does this shipment carry a customs
declaration?"** The gate needs **"does this purchase return a second document that has to be
printed on paper?"** The first is a superset of the second — so gating on it would refuse to
buy APO/FPO and territory labels on workstations that have no report printer and never
needed one.

So the gate should be a **per-carrier capability**, and this issue fills it in. `07`'s
storage and printing do not wait on it; only the pre-purchase gate does.

## Where each source stands

| Source | Separate document? | Detail |
|---|---|---|
| USPS | **No — fused** | The CP72 *is* the label: 3 pages of 432×288 (4×6 **landscape**) in PDF, 3 `^XA` blocks in ZPL, postage printed on ply 1 and plies 2–3 stamped *"Not Valid As Proof-of-Payment"* |
| UPS | **Yes** | `ShipmentResults.Form.Image`, PDF, `Code 01` — even when the label itself is a GIF. Three Letter pages, and **read into `customs_form_data` and printed** since 2026-09-10 |
| FedEx | **No — not requested** | `documentRequirements` names an invoice *and* an air waybill; only the AWB comes back, as pages 2–4 of a 4×6 label. No `shippingDocumentSpecification`, no ETD, so the invoice is never generated |
| Shopify | **Yes** | Separate `CUSTOMS_FORM`, PDF, three Letter pages — a commercial invoice |
| Amazon | **Unknown abroad; domestic for every territory** | No cross-border purchase exists. Quoted 2026-09-11 for PR, GU, MP and AS: only plain domestic USPS offered, `requiresAdditionalInputs: false` throughout, every `(Customs)` variant and every USPS International service refused — including for GU/MP/AS, which USPS itself wants a declaration for. `requiresCustomsDeclaration()` is true for all four. The over-block, observed four times. `AmazonBuyShippingService::labelFrom()` filters `packageDocuments` to `type === 'LABEL'`, so a `CUSTOM_FORM` (the schema's spelling) would be dropped rather than never fetched — and it is never requested either. Owned by [`amazon-buy-shipping/09`](../../amazon-buy-shipping/issues/09-international-purchase-and-customs.md), blocked on a real foreign order |

**The paper cost of the gate is three Letter sheets per international parcel, per package**,
for both UPS and Shopify. That is what makes "is a report printer configured" a materially
different question from "does this shipment carry a customs declaration".

## The three findings worth carrying

**1. The premise of the original table was wrong for two carriers.** It assumed the document
is offered and PolyBag fails to read it. For UPS and FedEx the document was **never
requested**, so there was nothing in the response to read. UPS spun out `24` — `InternationalForms`
sent at the wrong nesting level and silently ignored — and UPS returned a `Form` on the first
purchase after that was fixed. FedEx returns nothing because no request sends
`shippingDocumentSpecification`.

So the capability must record **why** a carrier returns nothing, not a bare boolean. FedEx's
"nothing" is the kind that changes the day someone adds `shippingDocumentSpecification` or
wires up ETD — `UploadEtdDocument` and `UploadEtdImage` exist in the repo, but only behind
the `fedex:run-etd-test` certification command, and nothing in `createShipment()` sends
`ELECTRONIC_TRADE_DOCUMENTS`.

**2. A multi-page international label is not evidence of a fused customs form.** This is the
trap the issue exists to avoid, and FedEx is an instance of it: four 4×6 pages, of which
pages 2–4 are byte-identical **air waybill pouch copies** stamped *"FEDEX AWB COPY - PLEASE
PLACE IN POUCH"*. They carry declared data — customs value, per-commodity descriptions,
country of manufacture, the EEI statement — which is what makes them easy to mistake for a
declaration. They are the air waybill. UPS's document is the same shape of thing in the other
direction: 3 Letter pages whose extracted text is byte-identical and **each headed "Page 1"**
— one commercial invoice in triplicate, the customs convention of a copy for export customs,
one for import customs and one for the consignee. `Form.Code 01` is "All Requested
International For**ms**", and that is what the plural means.

**3. The honest FedEx row is stronger than "no separate document".** FedEx *states* that an
invoice is required, hands back the air waybill only, and leaves the invoice to the shipper.
So a FedEx international parcel leaves PolyBag with **no commercial invoice at all** unless
somebody produces one by hand. That is an operational gap rather than a reading gap, and the
gate this issue specifies would correctly not fire for FedEx while the parcel still goes out
short a document FedEx itself listed as required.

## Two latent things found on the way

- **`UspsAdapter` could not read a separate document if USPS sent one.**
  `LabelResponse::parseBody()` takes `$parts[0]` as metadata and `$parts[1]` as the label,
  asserts only "at least 2 parts", and **discards `$parts[2]` onward without comment**.
  Nothing is being lost today — the customs form is inside part 1, and the known third-part
  candidate is the receipt, which both label paths suppress with `receiptOption: 'NONE'`. If
  that is ever changed, what comes back is dropped silently instead of erroring.
- **Format is not carried alongside the bytes.** UPS's PDF happens to line up with
  `printReport()`'s default, which is luck rather than design: a carrier returning its form as
  a GIF would need the format carried. Worth knowing before the FedEx and Amazon rows land.

## What is left

**Amazon.** Buy one international label and read `packageDocuments` by type. The cheapest of
the remaining rows, and `03` records that nothing has run against a live Amazon order at all.
Moved to [`amazon-buy-shipping/09`](../../amazon-buy-shipping/issues/09-international-purchase-and-customs.md)
2026-09-11: the row is an observation, but three things in the adapter's purchase path are
unbuilt for a cross-border parcel, so the work lives with the adapter.

**FedEx, if the invoice is ever wanted:** either `shippingDocumentSpecification` on the ship
request — which should return it in `shipmentDocuments`, though that is now an expectation and
not an observation — or ETD, which files it electronically and drops the paper.

## Test notes

Whatever this finds lands as a per-carrier capability the `07` gate reads, plus tests that a
carrier which **fuses** its customs form is not blocked by a missing report printer, and one
which returns a **separate** document is. Both directions matter; the first is the over-block
this issue exists to prevent.

## Comments

- **2026-09-10** — **USPS answered with no purchase at all**, from nine international PDFs and
  one ZPL already sitting in `packages.label_data` across seven destination countries and two
  mail classes. Every PDF identical in shape. The over-block this issue was opened to prevent
  is therefore real and confirmed rather than suspected. Question 2 — does the 4×6 path print
  all three plies or silently crop — has no crop mechanism to find: `printLabel()` hands the
  whole PDF to QZ with `scaleContent: true` and the ZPL branch sends the whole string raw.
  A read of the dispatch path, not a printed test.
  *Limits:* only Priority Mail International and First-Class Package International appear in
  the sample, all on one USPS account. The three plies are the CP72's own structure, so it
  should generalise — a service that files electronically would be a different case, and is
  not one PolyBag can buy today.
- **2026-09-10** — UPS, FedEx and Amazon tried the same shortcut. UPS blocked on `24`; FedEx
  measured across three services (International Priority, Connect Plus, Economy), which
  **withdrew an earlier claim** that its label is a Letter PDF — it is 4×6 on every page, so
  all four pages belong on the label printer and the paper cost of a FedEx international
  parcel is zero Letter sheets. Sandbox only, one origin, one destination country.
- **2026-09-10** — UPS answered on the first purchase after `24`, and wired up the same day.
  The operator saw one 4×6 label and nothing else, which looked like "UPS returns nothing" and
  was the opposite: `UpsAdapter` read only `PackageResults[0].ShippingLabel.GraphicImage` and
  dropped the `Form`. No production account and no real parcel needed — a sandbox sample
  against fake data. The two remaining rows should be reachable the same way once their
  requests ask for a document at all.

## Related

- `07` — the gate this fills the predicate for
- `24` — the UPS request defect that blocked the UPS row
