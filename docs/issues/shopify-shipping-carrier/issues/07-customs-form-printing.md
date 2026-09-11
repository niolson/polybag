# International labels return a customs form PolyBag could not print

Status: ready-for-human — storage and printing shipped 2026-09-10 with two carriers feeding them; the pre-purchase gate waits on `23`'s Amazon and FedEx rows

Repo: `polybag`

## Problem

An international Shopify purchase returns `documentType: CUSTOMS_FORM` as a **separate
document with its own URL**. PolyBag stored one label per package and printed that, so the
file was dropped and only `packages.metadata.shopify_customs_form_url` survived — somebody
opened Shopify and printed the customs form by hand.

That sits exactly where this feature is most attractive: DHL eCommerce and Canada Post are on
the supported carrier list, and USPS CeC covers international.

The document is nothing like the label:

| | `LABEL` | `CUSTOMS_FORM` |
|---|---|---|
| Format | PDF | PDF |
| Pages | 1 | **3** |
| Page size | 288 × 432 (4×6) | **612 × 792 (Letter)** |
| Text layer | none — one bitmap | text-bearing, four embedded fonts |

It is a commercial invoice: waybill number, both parties with tax-ID and EORI fields,
Incoterm `DDU`, reason for export `SALE`, line items.

**A precondition that bites before any of this:** an international Shopify purchase fails
unless every variant has `harmonizedSystemCode` and `countryCodeOfOrigin` set in the Shopify
catalogue, and those **cannot** be sent in the purchase. It also fails when the box weighs
less than Shopify's declared item weights, which is `19`. Both surface after packing.

## Decision — 2026-09-10: print it, with four constraints

Of the three options — surface the URL as a link, print it as a second document, or restrict
Shopify Shipping to domestic — the middle one was taken.

A comment of 2026-09-09 argued the middle option needs "a second printer configured per
workstation, or a document printer Device Settings does not model", and **that was wrong**:
Device Settings already models one, and its own label names this use case —
*"Report Printer (for packing slips, customs forms)"*. `printReport()` prints an 8.5×11 PDF
to it, and routing a print **by document shape** is established too: FedEx 8.5×11 label stock
sets `label_orientation: 'report'`, which the `print-label` listener routes to `printReport()`
instead of the thermal path. So the middle option is materially **cheaper** than that comment
concluded, not larger. Restricting to domestic reads worse than it did — the international
path works and returns a correct commercial invoice.

**1. The column is carrier-neutral, because every source discards the same document.** Not
speculative reuse:

| Source | Where | What was discarded |
|---|---|---|
| Amazon | `AmazonBuyShippingService::labelFrom()` | `packageDocuments` filtered to `LABEL`; SP-API returns `CUSTOMS_FORM` and `EXPORT_DECLARATION` in the same list |
| FedEx | `FedexAdapter` | reads `packageDocuments[0]` by hardcoded index; `shipmentDocuments` never read |
| UPS | `UpsAdapter` | reads `PackageResults[0].ShippingLabel.GraphicImage`; `ShipmentResults.Form.Image` — the return half of the `InternationalForms` the same adapter sends — never read |
| Shopify | `ShopifyShippingLabelService` | URL kept, file dropped |

**2. Store the document, not the URL.** Mirror `label_data`. Downloading on demand would
save ~three Letter pages of base64 per international package and depends on that URL staying
fetchable indefinitely, which nothing has verified, and it is Shopify-specific in a place
that should not be. **`PurgePiiCommand` must null it alongside `label_data`** — a commercial
invoice carries both parties' names, addresses, tax IDs and EORI numbers, so leaving it
behind would quietly undo the purge.

**3. Require a report printer where a customs declaration is required** — gating on the
**address pair, not the shipping method**. `AddressData::requiresCustomsDeclaration()` exists
and its docblock records that this distinction has bitten us twice: military and diplomatic
post offices and the territories cross a customs boundary on domestic-priced services, so a
method-based gate misses them. **The server cannot see printer configuration** —
`labelPrinter` and `reportPrinter` are browser `localStorage` — but the precedent exists:
`batch-ship-local-storage.blade.php` pushes `labelFormat` and `labelDpi` into Livewire state
before a batch runs, and `reportPrinter` joins them there.

**4. Batch ship skips the purchase rather than dropping the document.**
`BatchLabelService::getIneligibilityReason()` already returns human-readable skip reasons and
runs before `createBatch()` dispatches, so "No report printer configured" becomes one more
reason string beside "Not picked".

## What shipped — constraints 1 and 2

**`packages.customs_form_data`, carrier-neutral, beside `label_data`.** `ShipResponse`
carries `customsFormData`; `markShipped()` writes it, `clearShipping()` nulls it on a void,
and `PurgePiiCommand` nulls it in the same statement as `label_data`.

**Printing follows the established shape.** `PrintRequest` gains `customsForm`; `print-label`
prints the label through `printLabel()` and then the form through `printReport()`, and the
batch listener interleaves each form after its own label rather than collecting them for the
end of the run. No new hardware modelling.

Three things decided in the implementation:

1. **`printReport()` now returns whether it printed** rather than swallowing the outcome. It
   still does not throw — a label that printed must not be lost to a failure on the paper half
   — but a silent `return` could not answer "label printed, customs form did not".
2. **The redirect still happens when the customs form fails, carrying a warning.** The
   postage is bought and the label is out, so stranding the operator would be worse. A failed
   *label* still stays put — and a label on 8.5×11 stock now stays put too, where it used to
   redirect past its own error.
3. **A customs form Shopify will not hand over does not fail the purchase.** The label
   download stays fatal; the form is not, because the remedy — printing from the admin via
   `shopify_customs_form_url`, still recorded — is the workflow this replaces.

**UPS is now the second carrier feeding this, and the first that is not Shopify** (`23`,
`24`): `ShipmentResults.Form.Image` read into `customsFormData` and stored, printed and
purged by these paths.

## A defect found on the way: the PII purge had never run

Constraint 2 says the purge must null the customs form alongside `label_data`. It could not
have: `shipments.city` is the only one of the command's PII fields that landed NOT NULL, and
the command nulls them all in one `update()`. So every run threw an integrity violation on
the first eligible shipment and **never reached the packages at all**. The command is
scheduled daily, so this had been failing quietly for as long as retention has existed, and a
purge that cannot run is not a retention policy. Fixed here, because constraint 2 is
otherwise notional, and recorded separately as
[`pii-retention/01`](../../pii-retention/issues/01-purge-pii-has-always-failed-on-a-not-null-city.md)
— it is not a Shopify issue and it has an operational tail: the first run after it deploys
purges a backlog.

## What is left — constraints 3 and 4

The gate: requiring a report printer before buying a label that needs a customs declaration,
pushing `reportPrinter` into Livewire state, and the batch skip reason.

**It needs `23`**, because `requiresCustomsDeclaration()` is a superset of "a separate
document comes back" and the per-carrier answers are not uniform. `23` settled that in the
direction that makes this real: **USPS fuses** its CP72 into the label in both formats, so
gating on the predicate would block USPS international on a workstation with only a label
printer — for three 4×6 pages that print on the thermal path it already has. **UPS returns a
separate document**, so the gate genuinely is per-carrier. **FedEx returns nothing separate
because nothing is requested**, which is true today and stale the moment
`shippingDocumentSpecification` or ETD lands — so the capability must record *why* a carrier
returns nothing, not a bare boolean. Amazon is unobserved.

Until then a missing report printer stays **soft**: the form does not print and the operator
is told so, which is strictly better than the status quo of the form not existing.

## Related

- `23` — the per-carrier capability the gate reads
- `24` — why UPS returned nothing until 2026-09-10
- `19` — the declared-weight precondition on the same path
- `pii-retention/01` — the purge defect found here
