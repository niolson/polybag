# Replace the starter set with common box sizes

Status: done
Category: enhancement
Created: 2026-09-29

Part of [box-size-seed-data](../PRD.md). In scope for `release-polish`: it changes seed
data and adds no feature.

## Problem

`BoxSizeSeeder` is the first set of codes a new install scans against. Its 15 stock
sizes came from one warehouse, and they read badly on the Print Box Size Barcodes sheet:

- Names and stored dimensions disagree in order: `10x8x8` is stored as 8 × 8 × 10, and
  `11x7x6` as 6 × 7 × 11. A packer reads the name.
- The sheet sorts by label, so `10x10x10` prints before `4x4x4`.
- The seeded FedEx Small Box is 8.75 × 2.7 × 2, which is not a FedEx size.
- `updateOrCreate` on plain codes `01`–`27` overwrites a box the operator already gave one
  of those codes.

## What to build

- Split `BoxSizeSeeder` into `CommonBoxSizeSeeder` (the stock sizes) and
  `CarrierPackagingSeeder` (the 11 carrier rows seeded today). `OperationalDefaultsSeeder`
  and the Setup Wizard toggle keep loading both, so the wizard does not change in this
  issue. `03` turns the carrier seeder into the full catalog.
- Replace the stock sizes with a common set. Starting point, editable before building:
  - Boxes: 6×4×4, 6×6×6, 8×6×4, 8×8×8, 10×8×6, 10×10×10, 12×9×3 (mailer box), 12×12×12,
    16×12×8
  - Poly mailers: 10×13, 14.5×19
  - Padded mailers: #0 (6×10), #2 (8.5×12)
- Store each seeded row as length ≥ width ≥ height, with the name in the same order. Store
  flat mailers with the thickness the existing mailer rows use.
- Correct the FedEx Small Box to a published FedEx size. FedEx publishes two shapes each
  for the Small and Medium Box, so seed both shapes with the shape in the label, the way
  the two USPS Medium Flat Rate Boxes are already seeded. Check each dimension against
  FedEx's current published values.
- Give each set its own code prefix so the sets cannot collide with each other, and use
  `firstOrCreate` so a seeder never changes a row that already exists. Codes must still
  pass `BoxSizeResource::codeOutsideScanPrefix()`.
- Sort the barcode sheet by size within type, smallest first.

## Acceptance

- [x] Loading the starter set on an empty database creates the common set and the carrier
      rows, each name matching its stored L × W × H with L ≥ W ≥ H
- [x] Running the seeders when a row with a seeded code already exists leaves that row
      unchanged
- [x] The barcode sheet prints boxes by size within type
- [x] Seeder and sheet-order tests cover the above

## Comments

- 2026-10-12: Reframed. The old list was one warehouse's sizes, not a common set. Carrier
  packaging and an active/inactive flag became `02` and `03`, after the release.
- 2026-10-12: Built. `BoxSizeSeeder` is now a wrapper calling `CommonBoxSizeSeeder` (codes
  `01`–`13`) and `CarrierPackagingSeeder` (`USPS-01`–`07`, `FEDEX-01`–`06`), so the wizard and
  `OperationalDefaultsSeeder` did not change. Both use `firstOrCreate`. The test that
  repaired an older install's padded flat-rate envelope row by matching code `16` was
  replaced by one asserting that an existing row is left alone. That repair contradicted
  the never-overwrite rule, and no live install needs it. FedEx Small and Medium Box use
  the inside dimensions on fedex.com: 12.25 × 10.9 × 1.5 and 13.25 × 11.5 × 2.38, plus the
  second shapes 11.25 × 8.75 × 2.63 and 11.25 × 8.75 × 4.38, labelled "flat" and "deep".
  The sheet sorts in `PrintBoxSizeBarcodes::mount()`; `CacheService` still orders by label
  for its other callers.
