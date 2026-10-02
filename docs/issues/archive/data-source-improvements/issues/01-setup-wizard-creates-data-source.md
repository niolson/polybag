# Setup wizard data source step doesn't create a DataSource record

Status: done

## Problem

The "Data Source" step of the setup wizard (`app/Filament/Pages/SetupWizard.php`, `saveImportSource()`) still writes legacy `import.*` / `shopify.*` / `amazon.*` keys to `SettingsService`. Nothing reads those settings anymore — shipment imports are driven entirely by `DataSource` records since the switch to the DataSources system. The wizard step collects connection details (DB host/credentials, SSH tunnel config, Shopify domain, Amazon marketplace) and then effectively throws them away.

## Expected behavior

When the user completes the Data Source step with a source other than "None", the wizard should create an actual `DataSource` record:

- **Database**: `driver = App\Services\ShipmentImport\Sources\DatabaseSource`, with `settings.db_driver`, `settings.db_host`, `settings.db_port`, `settings.db_database`, `settings.db_username`, and SSH fields (`settings.ssh_enabled`, `settings.ssh_host`, `settings.ssh_port`, `settings.ssh_user`, `settings.ssh_remote_host`, `settings.ssh_remote_port`, `settings.ssh_host_key`). The password must go through the secret-settings path (`secret_settings` encrypted column — see how `CreateDataSource` / `EditDataSource` pages route `db_password` to `DataSource::secret()`).
- **Shopify**: `driver = ShopifySource`, `settings.shop_domain`, default `settings.channel_name = 'Shopify'`.
- **Amazon**: `driver = AmazonSource`, `settings.marketplace_id`, default `settings.channel_name = 'Amazon'`.

Details:

- Name the record something sensible (e.g. "Imported Orders Database", "Shopify", "Amazon") — user can rename later.
- Set `active = true`, leave `schedule_interval` null (manual) or pick a sensible default — confirm with maintainer.
- Idempotency: re-running the wizard step (back/forward navigation) should not create duplicate records. `updateOrCreate` on driver, or skip when a DataSource already exists.
- The summary step reads `SettingsService::get('import_source')` — update it to summarize the created DataSource instead.
- Remove the now-dead legacy settings writes (`import.*` group) once nothing depends on them.
- The summary/next-steps section should deep-link to the created DataSource edit page so the user can finish configuration (queries, test connection) after the wizard.

## Test notes

`tests/Feature/` has `SetupWizardTest`-style page tests (check for an existing wizard test file) and `DataSourceResourceTest.php` shows the Livewire form conventions. Assert a `DataSource` row exists with the right driver and settings, and that the password landed in `secret_settings`, not `settings`.

## Comments

**2026-06-11 (Claude):** Implemented in `SetupWizard.php`:

- `saveImportSource()` now creates/updates a `DataSource` via `firstOrNew(['driver' => ...])` per driver (`saveDatabaseDataSource` / `saveShopifyDataSource` / `saveAmazonDataSource`). DB password goes through `mergeSecret()` into the encrypted `secret_settings` column; blank password on a step revisit preserves the stored secret.
- Names default to "Imported Orders Database" / "Shopify" / "Amazon" and are preserved if the user renamed the record; `active = true`, `schedule_interval` left null (manual) — flag if a different default is wanted.
- Legacy `import.*` settings writes and the `import_source` setting removed. Tenant-level `shopify.shop_domain` / `amazon.marketplace_id` writes kept — still read as fallbacks by `ShopifyConnector`, `ShopifyOAuthProvider`, and `AmazonSource`.
- Summary step now shows the created DataSource name + driver; Next Steps deep-links to its edit page.
- `mount()` prefills the step's select from an existing DataSource so revisits are idempotent.
- 6 new tests in `SetupWizardTest.php` (creation per driver, no-op for "none", idempotent re-save with secret preservation, prefill).
