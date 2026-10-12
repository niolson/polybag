# Make fake carriers good enough to finish a demo

Status: needs-triage

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
