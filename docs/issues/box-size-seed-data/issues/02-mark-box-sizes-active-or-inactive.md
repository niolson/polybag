# Mark Box Sizes active or inactive

Status: ready-for-agent
Category: enhancement
Created: 2026-10-12

Part of [box-size-seed-data](../PRD.md). A new feature, so it waits until
`release-polish` closes.

## Problem

The only way to take a Box Size off the barcode sheet and off the pickers is to delete it.
A warehouse that trims the list down to one printed page, and later needs a size it
deleted, has to type the dimensions in again.

## What to build

- An `active` boolean on `box_sizes`, default true. Add it to the model, the factory and
  the seeders.
- A toggle on the Box Size form, an active column and filter on the table, and bulk
  activate and deactivate actions so a list can be trimmed quickly.
- Inactive Box Sizes are left out of:
  - the Print Box Size Barcodes sheet
  - the Pack page's scan lookup (`CacheService::getBoxSizesForPacking()`)
  - the box pickers on Manual Ship and the Shipment resource
- When a packer scans the code of an inactive Box Size, tell them it is inactive. Don't
  report it as an unknown code. The packer then knows to ask a manager rather than
  suspect the label.
- An inactive Box Size that is already on a Package, Package Draft or Label Batch stays
  valid. The existence checks in `EloquentPackageDraftWorkflow` and `ManualShip` keep
  accepting it, and history still shows it.
- Codes stay unique across active and inactive rows.

## Acceptance

- [ ] Deactivating a Box Size removes it from the barcode sheet, the Pack lookup and the
      pickers, and activating it brings it back with its dimensions unchanged
- [ ] Scanning an inactive code at Pack shows a message that the box is inactive
- [ ] A Package Draft saved with a Box Size that has since been deactivated can still be
      shipped
- [ ] Bulk activate and deactivate work from the table
- [ ] Tests cover each of the above

## Comments
