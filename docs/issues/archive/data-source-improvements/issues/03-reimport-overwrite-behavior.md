# Re-imports silently overwrite local shipment edits

Status: done

## Problem

`App\Services\ShipmentImport\ShipmentBatchWriter::write()` does a blind `Shipment::upsert()` keyed on `(data_source_id, source_record_id)`. Every import run rewrites address fields, shipping method, channel, value, metadata, etc. on already-imported shipments. If a user edits a shipment in PolyBag (fix an address typo, change the shipping method) and the same row comes in on the next import, the local edits are wiped out.

## Proposed behavior

Per-data-source setting `on_existing` (select in the Database Query / import settings section):

1. **Update** — current behavior, default for backward compatibility.
2. **Skip existing** — never touch shipments that already exist; only insert new ones. Fully protects local edits.
3. **Update only if source changed** — store a checksum of the prepared source row (e.g. `source_checksum` column on `shipments`, hash of the normalized prepared-row payload *excluding* volatile fields like timestamps) and skip the update when the checksum matches. Protects local edits in the common case where the remote row hasn't changed, and is a perf win (no-op rows skip the write). Caveat: if the remote row *does* change, local edits are still overwritten — that's by design for this mode.

## Open questions / decisions needed

- Should imports ever update shipments that are already packed/shipped? The upsert currently doesn't care about status. Probably updates should be restricted to open/unshipped shipments regardless of the `on_existing` mode — needs a decision.
- Does `shipmentsUpdated` reporting need to distinguish "skipped (unchanged)" from "updated"? Recommend adding a skipped count to `ShipmentBatchWriteResult` / `ImportResult`.
- Item-level imports (`ShipmentItemImporter`) have the same overwrite semantics — should the mode apply there too? (Checksum over items could be folded into the row checksum by hashing shipment + items together, or items handled separately.)
- Migration: `source_checksum` nullable column; first import after upgrade backfills naturally (null checksum ≠ computed checksum → one extra update pass).

## Context

- Upsert: `app/Services/ShipmentImport/ShipmentBatchWriter.php:45`
- Row prep: `app/Services/ShipmentImport/ShipmentRowPreparer.php`
- Import orchestration: `app/Services/ShipmentImport/ShipmentImportService.php`

## Comments

**2026-06-11 (Claude):** Implemented with maintainer decisions: all three modes with `update_if_changed` as the default for every source (including existing ones); shipped/void shipments are never updated in any mode; items are folded into the checksum.

- `App\Enums\ImportExistingBehavior` (`update` / `skip` / `update_if_changed`), read from `settings.on_existing`, exposed as an "Existing Shipments" select in the General section of the DataSource form.
- Migration adds nullable `shipments.source_checksum` (sha256). Null checksum ≠ computed checksum, so the first import after upgrade does one extra update pass and backfills naturally.
- `ShipmentImportService` now fetches items *before* the batch write (same per-run cost as before — they were always fetched) and computes `source_checksum = sha256(prepared row + item rows)`. Fetched items are passed down to `ShipmentItemImporter`, which no longer fetches.
- `ShipmentBatchWriter` partitions rows: new → insert; existing non-open → skip; existing open → per-mode (checksum compare for `update_if_changed`). Skipped rows are excluded from the upsert entirely.
- Skipped shipments: no item re-import, no `ShipmentUpdated` event, but `markExported` still runs so the remote row stops re-fetching.
- `shipments_skipped` added to `ImportResult` / `ImportRunRecorder` / `shipments:import` output, distinguishing skipped-unchanged from updated.
- Two legacy tests asserting the old clobber semantics were updated to the new default (local channel assignment now survives an unchanged re-import); legacy always-update behavior remains covered by an explicit `update`-mode test. 6 new behavior-matrix tests. Full suite: 943 passed.
- Note: checksum includes locally-resolved IDs (client/channel/shipping method), so adding a new alias mapping counts as "changed" and triggers a re-import — intended, keeps mappings fresh.
