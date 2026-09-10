# International Shopify labels return a customs form PolyBag cannot print

Status: ready-for-human — storage and printing implemented 2026-09-10, now with two carriers feeding them; the pre-purchase gate waits on `23`'s FedEx and Amazon rows

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

`requiresCustomsDeclaration()` is a **superset** of "a separate document comes back".
**Split out as `23`** — the gate should be a per-carrier capability, and `23` is what fills
the capability in. Storage and printing do not wait on it.

`23` answered USPS on 2026-09-10, and it answered in the direction that makes this a real
problem rather than a theoretical one: USPS fuses, in both label formats, so gating purchase
on the predicate **would** block USPS international on a workstation with only a label
printer — for three 4×6 pages that print on the thermal path it already has. The gate cannot
be built on the predicate alone.

The other three rows were checked the same day and reshape the gate further. For **UPS and
FedEx no separate document came back because none was requested** — UPS's `InternationalForms`
was sent at the wrong nesting level and ignored (`24`), and no FedEx request sends
`shippingDocumentSpecification` at all. That observation had a short shelf life, exactly as
predicted: **`24` was fixed the same day, and UPS immediately returned one.**

So UPS is now the second carrier feeding this issue's storage and printing, and the first
that is not Shopify: `ShipmentResults.Form.Image`, a PDF, `Code 01`, read into
`customsFormData` and stored, printed and purged by the paths already built here. It also
settles the question this issue was split over — USPS fuses and UPS does not, so the gate
genuinely is per-carrier rather than one predicate.

**Amazon** stays unobserved, though `labelFrom()` already selects `packageDocuments` by
type, so reading a second type is small once a purchase exists. **FedEx** returns nothing
until a request asks for it. The capability must still record *why* a carrier returns
nothing, because FedEx's "nothing" is the kind that changes the day someone adds
`shippingDocumentSpecification`.

## Status

`needs-triage` → `ready-for-agent` → **partly implemented 2026-09-10**. Constraints 1 and 2
are built and tested; 3 and 4 are not, because the gate they describe needs `23`. See the
comment of that date.

## Comment, 2026-09-10 — storage and printing are in; the gate is all that is left

Constraints 1 and 2 shipped as specified, and nothing about the decision changed on contact.

**`packages.customs_form_data`, carrier-neutral, beside `label_data`.** `ShipResponse` carries
`customsFormData`; `markShipped()` writes it, `clearShipping()` nulls it on a void, and
`PurgePiiCommand` nulls it in the same statement as `label_data`. Only Shopify fills it in so
far — the column is the home the other three adapters were said to lack, not a claim they now
use it. `23` is what establishes what they return before anything reads their responses for it.

**Printing follows the established shape exactly.** `PrintRequest` gains `customsForm`;
`print-label` prints the label through `printLabel()` and then the customs form through
`printReport()`, and the batch listener interleaves each form after its own label rather than
collecting them for the end of the run. No new hardware modelling, as the comment above
predicted.

Three things were decided in the implementation that the issue left open:

**1. `printReport()` now returns whether it printed, rather than swallowing the outcome.** It
still does not throw — a label that printed must not be lost to a failure on the paper half —
but a silent `return` could not answer "label printed, customs form did not". The two callers
that print paperwork on its own ignore the return value, so their behaviour is unchanged.

**2. The redirect still happens when the customs form fails, carrying a warning.** As the
issue argued: the postage is bought and the label is out, so stranding the operator on the
ship page would be the worse failure. `printReport()` has already shown what went wrong;
`showStatusAfterNavigation` carries "The label printed but its customs form did not" to the
next page. A failed *label* still stays put, which is the existing behaviour — and a label on
8.5×11 stock (the FedEx `report` orientation) now stays put too, where it used to redirect past
its own error. That was the intent of the comment in the `catch`; it only ever worked for the
throwing path.

**3. A customs form Shopify will not hand over does not fail the purchase.** The label
download stays fatal, because a purchase with no printable label is not a ship. The customs
form is not: the label is bought and paid for, and the remedy — printing from the Shopify
admin via `shopify_customs_form_url`, which is still recorded — is the workflow this replaces
rather than a new problem. So a failed customs download logs a warning and leaves the column
null.

### A defect found on the way, and fixed: the PII purge has never run

Constraint 2 says `PurgePiiCommand` must null the customs form "alongside `label_data`". It
could not have: `shipments.city` is the only one of the command's PII fields that landed NOT
NULL, and the command nulls them all in one `update()`. So every run threw an integrity
violation on the first eligible shipment and **never reached the packages at all** — neither
`label_data` nor anything else. The command is scheduled daily in `bootstrap/app.php`, so this
has been failing quietly for as long as retention has existed, and a purge that cannot run is
not a retention policy.

Fixed here, because constraint 2 is otherwise notional: `city` is nullable at the database
only — nothing relaxes about what an import must supply — and `tests/Feature/PurgePiiTest.php`
covers both that the purge completes and that the customs form goes with the label. Recorded
separately as [`pii-retention/01`](../../pii-retention/issues/01-purge-pii-has-always-failed-on-a-not-null-city.md),
since it is not a Shopify issue and has an operational tail: retention has not been applied
in any deployment yet, so the first run after this deploys will purge a backlog.

### What is left

Constraints 3 and 4 — requiring a report printer before buying a label that needs a customs
declaration, pushing `reportPrinter` into Livewire state, and the batch skip reason. All of it
is the gate, and the gate needs `23` to know which carriers return a separate document;
gating on `requiresCustomsDeclaration()` today would refuse APO/FPO and territory labels on
workstations that never needed a report printer. Until then a missing report printer stays
soft: the form does not print and the operator is told so, which is strictly better than the
status quo of the form not existing.
