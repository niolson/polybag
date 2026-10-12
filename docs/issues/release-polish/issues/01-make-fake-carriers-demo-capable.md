# Make fake carriers good enough to finish a demo

Status: done — 2026-10-12

Pass: 1 (found while reviewing the plan)

Severity: high — the demo's fallback cannot print, produce documents, or run End of Day.

## Problem

The demo falls back to `FAKE_CARRIERS=true` when a carrier sandbox fails. On fake
carriers today:

- **Labels are blank.** `FakeCarrierAdapter::createShipment()` returns a 1×1 white PDF.
  It also reports the requested `labelFormat`, so when the workstation asks for ZPL, PDF
  bytes are labeled as ZPL and sent to the raw printer.
- **No customs documents.** `customsDocumentDelivery` defaults to `None`, so an
  international Shipment produces no documents to show.
- **End of Day fails.** `ManifestService::createManifest()` dispatches on carrier name
  straight to `createUspsManifest()`, which resolves a real USPS account and calls
  USPS. It never goes through the adapters, so a fake carrier has nowhere to answer.

## Proposed direction

- **A sample label in each format.** A 4×6 PDF/PNG and a ZPL label (203 and 300 DPI)
  laid out like a real carrier label, carrying the Shipment's address and the fake
  tracking number. It must be obviously synthetic: marked SAMPLE across the face, and
  with no barcode a carrier scanner could read as real. Build it from a template at
  purchase time rather than shipping fixed files, so the address on the label is the
  order's.
- **Sample customs documents** for an international Shipment, marked SAMPLE the same
  way, through whichever `CustomsDocumentDelivery` path a real carrier would use.
- **A fake manifest path.** When fake carriers are on, End of Day produces a sample SCAN
  form PDF listing the Packages, without calling USPS. Ideally this is the start of
  routing manifests through the adapter (`supportsCarrierManifest()` already exists on
  it), rather than one more branch in `ManifestService`.

## Acceptance

- The release PRD's core script runs end to end on fake carriers: a printed label shows
  the address and tracking number, and End of Day returns a form.
- Nothing produced in fake mode could be mistaken for, or scanned as, real postage.
- `FAKE_CARRIERS` stays off in `.env.example` and in CI. The browser tests register their
  own fakes, as `ShippingFlowTest` does.

## Comments

- 2026-10-12 — Done. Fake carriers print a sample 4×6 label (PDF, or ZPL at 203/300 dpi
  when asked for) with the order's addresses and the tracking number as plain text, and no
  barcode of any kind. USPS folds a sample customs declaration into the label, UPS returns a
  sample commercial invoice for the document printer, FedEx returns none, each as its real
  adapter does. End of Day goes through a new `CreatesCarrierManifest` contract that the
  fake implements, so it produces a sample form without calling USPS; the real USPS path
  is unchanged. Found while testing: the fakes quoted only domestic services, so an
  international method had no rates; they now quote an international table when the
  destination country differs from the origin. Letter pages keep their bands half an inch
  inside the edges, after the first invoice printed with its top band cut off. Verified
  on hardware: PDF and ZPL labels, void, UPS label and invoice, USPS declaration, End of
  Day. Not done: a PNG variant (nothing asks for one) and moving the real USPS SCAN form
  onto the new contract.
