# Seed the carrier packaging catalog inactive

Status: done
Category: enhancement
Created: 2026-10-12

Part of [box-size-seed-data](../PRD.md). Needs `02`. Brought into `release-polish` on
2026-10-12.

## Problem

The carrier rows seeded today cover 10 of the 27 `CarrierPackaging` cases. Nothing is
seeded for UPS, for the USPS Priority Mail Express envelopes, or for the FedEx Tube,
Large, Extra Large, 10kg and 25kg boxes. A warehouse that starts using one of those has
to look up its dimensions and type them in. The seeded rows that do exist are all active,
so they all print on the barcode sheet whether the warehouse stocks them or not.

## What to build

- `CarrierPackagingSeeder` seeds one row for every `CarrierPackaging` case, at the
  carrier's published outside dimensions. Packaging the carrier publishes in more than one
  shape gets one row per shape, with the shape in the label.
- Each row has its carrier's maximum weight, a `materials_cost` of 0 where the carrier
  supplies it free, and a code under that carrier's prefix.
- Every carrier row is seeded **inactive**, on every install, whether or not the Setup
  Wizard's starter toggle is on. The warehouse activates what it stocks.
- Relabel the Setup Wizard toggle to load only the common set. Under it, a line says that
  carrier packaging is already in the catalog, inactive, and where to switch it on.
- Add a test that fails when a `CarrierPackaging` case has no seeded row, so a case added
  later can't be missed.

## Acceptance

- [x] A fresh install has a seeded, inactive row for every `CarrierPackaging` case
- [x] Activating one puts it on the barcode sheet and the Pack lookup with no dimensions
      to enter
- [x] The starter toggle loads only the common set
- [x] Adding a `CarrierPackaging` case without a seeded row fails a test

## Comments

- 2026-10-12: Built. `CarrierPackagingSeeder` is a `OnceOnlySeeder` (batch
  `carrier-packaging-v1`) run by `ReferenceDataSeeder`, so every install gets the catalog
  at its first start and a row an Admin deletes stays deleted. It seeds 35 rows covering
  25 of the 27 cases. `FedexBox` and `UpsExpressBox` are generic codes for any box from
  that carrier, with no size to seed; they are listed in `CarrierPackagingSeeder::UNSIZED`
  and the coverage test skips them. A case added later needs a `-v2` batch to reach
  databases already seeded. FedEx dimensions follow FedEx's US One Rate page, which
  changed the Small Box (flat) from `01`'s 12.25 × 10.9 to 12.38 × 10.88. UPS dimensions
  come from UPS's published Express Box sizes, and its 30 lb limit is used for all UPS
  rows. Max weights are informational; nothing enforces them. `BoxSizeSeeder` was
  removed: the Setup Wizard's toggle and `OperationalDefaultsSeeder` call
  `CommonBoxSizeSeeder` directly.
