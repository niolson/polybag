# Seed the carrier packaging catalog inactive

Status: ready-for-agent
Category: enhancement
Created: 2026-10-12

Part of [box-size-seed-data](../PRD.md). Needs `02`.

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

- [ ] A fresh install has a seeded, inactive row for every `CarrierPackaging` case
- [ ] Activating one puts it on the barcode sheet and the Pack lookup with no dimensions
      to enter
- [ ] The starter toggle loads only the common set
- [ ] Adding a `CarrierPackaging` case without a seeded row fails a test

## Comments
