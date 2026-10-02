# Add a UI trigger for manual shipment imports

Status: done

## Problem

Imports can currently only be run manually via CLI: `php artisan shipments:import --source-id=N` (also supports `--dry-run` and `--validate-only`). There is no way to trigger an import from the UI. Warehouse admins shouldn't need shell access to pull new orders.

## Proposed behavior

- Add a **"Run Import Now"** header action on `EditDataSource` (and a row action on the DataSources table) that runs the import for that source.
- Imports can be slow (remote DBs, Shopify/Amazon APIs), so don't run them inline in the Livewire request. Dispatch a queued job that calls `ShipmentImportService::forRecord($record)->import()` and sends a database notification to the triggering user with the result summary (created/updated counts, errors) when done. Follow the result shape in `App\Console\Commands\ImportShipmentsCommand::runRecord()`.
- Disable/hide the action when the source is `active = false`.
- Optional nice-to-have: a "Preview Import" action mirroring `--dry-run` (fetch + count + sample, no writes) — this one can run inline with a reasonable timeout since it's read-only, or be cut from scope.
- Guard against concurrent runs of the same source (e.g. `WithoutOverlapping` job middleware keyed by data source id, or a cache lock) so double-clicks and scheduler overlap don't double-import.

## Open questions

- Should the action live in the General section of the form (per original note) or as a page header action? Header action is the Filament-conventional spot; recommend that.

## Test notes

Queue fake + assert job dispatched from the Filament action (`livewire(EditDataSource::class)->callAction('run_import')`), plus a job test that runs against a stubbed source. See `tests/Feature/ImportShipmentsCommandTest.php` for existing import-run fixtures.

## Comments

**2026-06-11 (Claude):** Implemented:

- `App\Jobs\RunDataSourceImportJob` — queued (`timeout` 600s, `tries` 1), `WithoutOverlapping` keyed by `data-source-import:{id}` with `dontRelease()` so a concurrent click is dropped, not retried. Skips missing/inactive sources.
- Notifications reuse the existing `App\Notifications\ImportCompleted`. On success the triggering user gets a database notification; on errors the job stays silent because `ImportRunRecorder` already notifies all active admins — avoids double notifications.
- "Run Import Now" header action on `EditDataSource` (disabled with tooltip when inactive) and a "Run Import" row action on the DataSources table (hidden when inactive), both with confirmation modals and an immediate "Import queued" toast.
- Resolved open question: header action, per Filament convention — not a button in the General form section.
- "Preview Import" (dry-run) cut from scope; trivial to add later on top of the same job/service if wanted.
- Tests: 4 action tests in `DataSourceResourceTest.php`, 4 job tests in `RunDataSourceImportJobTest.php` (success + notification, inactive no-op, error path leaves notification to the pipeline, overlap lock key).
