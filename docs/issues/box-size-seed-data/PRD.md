# Box size seed data

Status: reference
Created: 2026-09-29 (reframed 2026-10-12)

## Problem

The starter Box Sizes (`BoxSizeSeeder`, the Setup Wizard's "Load recommended starter box
sizes" toggle) mix three unrelated things in one list:

- 15 stock sizes that one warehouse happened to use, not a set most warehouses would stock
- USPS Priority Mail flat-rate packaging
- Some FedEx packaging, with the Small Box stored as 8.75 × 2.7 × 2, which matches none
  of FedEx's published sizes

A warehouse that loads them gets sizes it does not use, and every one of them is on the
Print Box Size Barcodes sheet. The only fix today is deleting rows, and adding one back
later means typing its dimensions again.

Smaller problems in the same list: names and stored dimensions disagree in order (`10x8x8`
is stored as 8 × 8 × 10), the barcode sheet sorts by label, and the seeder's
`updateOrCreate` on plain codes `01`–`27` overwrites any box an operator already gave one
of those codes.

## Decisions

- **Box Sizes can be active or inactive.** An inactive Box Size stays in the catalog but
  is left off the barcode sheet and off the packing and shipping pickers. Deactivating is
  how a warehouse trims the list. Switching a size back on is one click, with no
  dimensions to type again.
- **A common set.** About a dozen generic sizes that box suppliers commonly stock replace
  the old warehouse's list. Loading it stays optional, and its rows start active.
- **Carrier packaging is a catalog, seeded inactive.** Every `CarrierPackaging` case
  is seeded at its published outside dimensions and starts inactive, whatever carriers the
  install uses. A warehouse activates the packaging it stocks. This replaces per-carrier
  toggles in the Setup Wizard.
- **Dimension order is L × W × H with L ≥ W ≥ H**, and a seeded name matches its stored
  dimensions.
- **Seeders never overwrite.** A seeder skips a code that already exists, and each set has
  its own code prefix so the sets cannot collide with each other.

## Issues

1. [Replace the starter set with common box sizes](issues/01-replace-the-starter-set-with-common-box-sizes.md):
   seed data only, so it is in scope for `release-polish`
2. [Mark Box Sizes active or inactive](issues/02-mark-box-sizes-active-or-inactive.md):
   a feature, so it waits until the release closes
3. [Seed the carrier packaging catalog inactive](issues/03-seed-the-carrier-packaging-catalog-inactive.md):
   needs `02`

No live installs exist yet, so nothing migrates or renames rows already seeded on an
existing database.
