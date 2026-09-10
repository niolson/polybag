# International Shopify labels return a customs form PolyBag cannot print

Status: ready-for-agent — decided 2026-09-10; the per-carrier gate half waits on `23`

Repo: `polybag`

## Problem

A Shopify purchase returns its documents as a list. The label is
`documentType: LABEL`; an international shipment also gets
`documentType: CUSTOMS_FORM` as a **separate document with its own URL**.

PolyBag stores one label per package (`packages.label_data`) and prints that. There is
nowhere for a second document to go, so `ShopifyShippingLabelService` keeps only the
customs form's URL in `packages.metadata.shopify_customs_form_url` and drops the file.

The practical consequence: for an international Shopify label, somebody opens Shopify and
prints the customs form by hand. That sits exactly where this feature is most attractive
— DHL eCommerce and Canada Post are on the supported carrier list, and USPS CeC covers
international.

The URL is deliberately stored rather than the file: downloading a document PolyBag has
no way to print would waste the transfer and bloat the row for nothing.

## Options

- **Surface the URL** in the package UI as a link, so the manual step is at least
  discoverable rather than buried in metadata. Cheapest useful move.
- **Print it as a second document** through QZ Tray, which means a real decision about
  how PolyBag models multi-document labels — this is not Shopify-specific, and other
  carriers' customs forms would benefit.
- **Restrict Shopify Shipping to domestic** via the carrier-service catalogue until one
  of the above lands.

## First

~~Confirm in `01` that an international purchase really does return a second document, and
what format it comes back in.~~ **Done 2026-09-09 — it does.** PDF, but three Letter pages
rather than a 4×6 label. The gate on this issue is cleared and the options above are now
decidable; see the comment of that date, which argues the format changes their relative cost.

## Comments

### 2026-09-08 — the confirming step is now free; fold it into the campaign

"First: confirm in `01` that an international purchase really does return a second
document" was written when a purchase cost real postage. It does not any more — on a
development store these are test labels — so the precondition this issue has been waiting
on is one international test order away.

Both purchases in `01` so far were domestic, which is why nothing here has moved. Create an
international test order on the same store, buy a label through PolyBag, and record whether
a `CUSTOMS_FORM` document comes back and in what format. That is `01`'s question 6, and it
is the whole gate on this issue.

**Do not choose between the three options above before that observation exists.** Each one
assumes a second document; the format it arrives in also decides whether the middle option
is even reachable through QZ Tray. Sequenced after the campaign, not during it.

### 2026-09-09 — confirmed, and the format decides more than expected

`01`'s question 6 is answered. An international purchase to a Canadian destination returned two documents,
and the second one is not a label:

| | `LABEL` | `CUSTOMS_FORM` |
|---|---|---|
| Format | PDF | PDF |
| Pages | 1 | **3** |
| Page size | 288 × 432 pts (4×6) | **612 × 792 pts (Letter)** |
| Text layer | none — a bitmap | text-bearing, four embedded fonts |

It is a UPS commercial invoice: waybill number, both parties with tax-ID and EORI fields,
Incoterm `DDU`, reason for export `SALE`, and the line items.

**This settles the middle option's real cost.** "Print it as a second document through QZ
Tray" was written as a modelling question about multi-document labels. It is also a
**hardware** question: three Letter pages cannot go to the 4×6 thermal printer the label goes
to, so this needs a second printer configured per workstation, or a document printer that
Device Settings does not currently model. That is a materially larger change than the framing
above implies, and it is not Shopify-specific — a FedEx or USPS customs form has the same
shape.

**The cheapest option is now cheaper by comparison.** Surfacing the URL as a link in the
package UI leaves the operator printing from a browser to whatever printer they already use
for paper, which is the workflow the document actually wants.

The third option — restrict Shopify Shipping to domestic — reads worse than it did. The
international path works and returns a correct commercial invoice; withdrawing it would give
up a working capability to avoid a printing problem that exists for our own carriers too.

**A precondition worth recording here**, because it bites before any of this: an
international Shopify purchase fails unless every variant has `harmonizedSystemCode` and
`countryCodeOfOrigin` set in the Shopify catalogue, and those **cannot** be sent in the
purchase — `ShippingLabelPurchaseInput` has no customs fields. It also fails when the box
weighs less than Shopify's declared item weights, which is `19`. Both surface after packing.

### 2026-09-10 — the hardware objection was wrong, and the option ranking inverts

The comment above argues the middle option needs "a second printer configured per
workstation, or a document printer that Device Settings does not currently model."
**Device Settings already models one, and its own label already names this use case:**

```
resources/views/filament/pages/device-settings.blade.php:79
    Report Printer (for packing slips, customs forms)
```

`printReport()` in `resources/views/components/qz-tray.blade.php` prints an 8.5×11 pixel
PDF to `reportPrinter`, and pack slips, pick batches and SCAN forms all go through it.
Routing a print *by document shape* is established too: FedEx 8.5×11 label stock sets
`label_orientation: 'report'`, which the `print-label` listener routes to `printReport()`
instead of the thermal path. So "4×6 to the label printer, Letter pages to the report
printer" is a pattern this codebase already runs — no new hardware modelling, and the
middle option is materially **cheaper** than the comment above concluded, not larger.

What remains is real but is plumbing: a package holds one label, and that runs from
purchase through storage to print.

## Decision, 2026-09-10

Take the middle option — print the second document — with four constraints settled.

### 1. The column is carrier-neutral, because every source discards the same document

This is not speculative reuse. Every other shipping source already throws a second
document away:

| Source | Where | What is discarded |
|---|---|---|
| Amazon | `AmazonBuyShippingService::labelFrom()` | `packageDocuments` is filtered to `type === 'LABEL'`; SP-API returns `CUSTOMS_FORM` and `EXPORT_DECLARATION` in that same list |
| FedEx | `FedexAdapter`, label response | reads `packageDocuments[0]` by hardcoded index; `shipmentDocuments`, where the commercial invoice arrives when ETD does not file it electronically, is never read by the adapter at all |
| UPS | `UpsAdapter`, label response | reads `PackageResults[0].ShippingLabel.GraphicImage`; `ShipmentResults.Form.Image` — the return half of the `InternationalForms` the same adapter sends — is never read |
| Shopify | `ShopifyShippingLabelService::buildLabelFromNode()` | URL kept, file dropped |

FedEx is the sharpest case: the adapter builds a full `customsClearanceDetail`, and only
the ETD *test-case command* ever handles a `COMMERCIAL_INVOICE` document. So
`customs_form_data` belongs on `ShipResponse` and on `packages`, spelled carrier-neutrally,
and four adapters gain a home for something they currently drop on the floor.

### 2. Store the document, not the URL

Mirror `label_data`: `Package::applyShipResponse()` writes it, `clearShipping()` nulls it.
Downloading on demand from `shopify_customs_form_url` would save roughly three Letter pages
of base64 per international package, but it depends on that URL staying fetchable
indefinitely, which nothing here has verified, and it is Shopify-specific in a place that
should not be.

**`PurgePiiCommand` must null it alongside `label_data`.** A commercial invoice carries
both parties' names, addresses, tax IDs and EORI numbers. Leaving it behind when the label
is purged would quietly undo the purge — this is the easiest of the three write sites to
miss and the worst to miss.

### 3. Require a report printer where a customs declaration is required

Gate on the **address pair, not the shipping method**. `AddressData::requiresCustomsDeclaration()`
already exists, and its docblock records that this exact distinction has bitten us twice —
customs items reaching a carrier without weight reconciliation, and territory labels
reaching FedEx with no customs value. Military and diplomatic post offices and the
territories cross a customs boundary on domestic-priced services, so a method-based gate
misses them.

**The server cannot see printer configuration.** `labelPrinter` and `reportPrinter` are
browser `localStorage`, read only by JS; nothing sends them anywhere. The precedent for
getting them to the server already exists —
`resources/views/filament/components/batch-ship-local-storage.blade.php` pushes
`labelFormat` and `labelDpi` into Livewire state before a batch runs. `reportPrinter` joins
them there.

### 4. Batch ship skips the purchase rather than dropping the document

`BatchLabelService::getIneligibilityReason()` already returns human-readable skip reasons
that the batch UI surfaces, and it runs in the browser request *before* `createBatch()`
dispatches the queued jobs. "No report printer configured" becomes one more reason string
beside "Not picked" and "Contains transparency-required items" — the mechanism for skipping
a purchase is already built.

The print half needs `print-batch-labels` to interleave each customs form after its label,
and the per-item counter to separate "label printed, invoice did not" from a failed label.

## Two failure modes that need deciding in the implementation

- **`printReport()` swallows its errors** where `printLabel()` throws. "Label printed,
  customs form did not" needs a deliberate answer. The redirect should still happen —
  postage is bought and the label is out — so this wants a carried-over warning through the
  existing `showStatusAfterNavigation`.
- **A missing report printer is currently soft**: a warning, print skipped. For an
  international package that is a parcel that cannot legally move, which is what constraint
  3 above is for.

## What this does not settle

`requiresCustomsDeclaration()` is a **superset** of "a separate document comes back". USPS
is understood to fuse the customs form into the label PDF as extra pages rather than
returning a second document; if so, gating purchase on the predicate would block APO/FPO
and territory shipments that never needed a report printer at all. Only Shopify's behaviour
has been observed. **Split out as `23`** — the gate should be a per-carrier capability, and
`23` is what fills the capability in. Storage and printing do not wait on it.

## Status

`needs-triage` → `ready-for-agent`. The three options are decided, the constraints are
named, and nothing above needs a purchase to proceed.
