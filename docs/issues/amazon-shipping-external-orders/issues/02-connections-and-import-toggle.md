# Present data sources as Connections and let a connection turn off order import

Status: done

Repo: `polybag`

## What to build

An Amazon or Shopify `DataSource` is really a connected account: it holds credentials,
OAuth state, and Client assignment, and it may import orders, sell postage, or both. Today
every record is assumed to be an importer — `active` means "the import runs", and the
scheduler, `ImportShipmentsCommand` and `ExportPackagesCommand` all select on it. An Amazon
account that exists only to sell Amazon Shipping for other channels (`04` onward) has
nowhere to live.

Two changes, shipped together because the second is what makes the first honest:

1. **Rename in the UI only.** "Data Sources" becomes "Connections" in navigation, page
   titles, labels and notifications. The `DataSource` model and `data_sources` table keep
   their names. Add a `CONTEXT.md` entry saying a Connection is a `DataSource` record, so
   the UI term and the code term are one glossary lookup apart.
2. **Separate "import orders" from `active`.** Add an import-enabled setting (default on;
   backfill existing rows on). `active` keeps meaning "this connection may be used at all".
   When import is off:
   - the scheduled import in `routes/console.php`, `ImportShipmentsCommand` and
     `RunDataSourceImportJob` skip the connection;
   - "Run Import", the schedule interval, and field-mapping/query sections are hidden on
     the form;
   - export behavior is unchanged unless it turns out to depend on import — check
     `ExportPackagesCommand` and `PackageExportService` and record the decision.

`PostageSourceResolver::channelSourceFor()` is **not** changed: postage bound to the
originating Shopify or Amazon connection still requires `active` only, since the order
already exists in PolyBag.

For a Database connection, turning import off leaves an export-only connection. Decide
whether that is allowed or whether the toggle is shown only for Shopify and Amazon, and
record why.

## Acceptance criteria

- [x] Navigation, resource labels and page titles say Connection(s); model/table/class
      names are unchanged
- [x] `CONTEXT.md` defines Connection in terms of `DataSource`
- [x] Migration adds the import-enabled setting, backfilled on for every existing row
- [x] A connection with import off is skipped by the scheduler, the import command and the
      import job; feature tests cover each
- [x] Import-only form sections and the "Run Import" action are hidden when import is off
- [x] Origin-bound channel postage still resolves for a Shipment whose connection has
      import off but is active (test)
- [x] AGENTS.md mentions of the Data Sources UI are updated

## Blocked by

None - can start immediately

## Comments

### 2026-09-22 — implemented

`data_sources.import_enabled` (boolean, default on, so every existing row is backfilled
on). `DataSource::importing()` (active **and** import on) is what the scheduled import in
`routes/console.php` and `shipments:import --all` select; `--source-id` and
`RunDataSourceImportJob` check `importsOrders()` and skip. The resource is labelled
Connection(s); the URL (`/data-sources`), model, table and classes keep their names.

With import off the form hides the import schedule, *Existing Shipments*, the channel and
default shipping method, Amazon lookback and FBA, the Shopify location mapping, and the
Database table/query/mark-exported fields and *Preview Queries*. It also hides *Run
Import* (table and edit page), *Import Historical Orders* and *Activate Fulfillment-Order
Import*. Hidden values are kept on save, so turning import back on restores them.

Decisions:

- **Export is unchanged.** `ExportPackagesCommand` and `PackageExportService` never
  depended on import. The primary destination is the Shipment's own connection with
  `export_enabled`, and global destinations need `active` + `global_export` +
  `export_enabled`. Shipments already imported from a connection keep their write-back
  (Shopify fulfillment, Amazon confirmation) after its import is turned off. That is why
  the write-back toggles stay visible.
- **The toggle is offered for Database connections too.** An export-only Database
  connection is a real use: a *Global Export Destination* already receives tracking for
  every Package, including manual and other-channel ones, whether or not it imports
  anything. Hiding the toggle would force such a connection to also run an import it
  does not need. *Max Affected Rows* stays visible because it caps the export query too.
- **Section titles** "Shopify/Amazon Import Settings" became "… Order Settings",
  because they still hold the write-back toggles when import is off.
- `PostageSourceResolver::channelSourceFor()` is untouched, and a test pins that an
  active connection with import off still sells postage for its own Shipments.
- A Shopify or Amazon connection with import off does not require a channel. Nothing
  outside import reads `settings.channel_name`.
- **The Setup Wizard's order-import step follows `import_enabled`** (found in review). It
  prefills from, summarizes, and links to `DataSource::importing()` only. Choosing a
  driver configures that driver's importing connection if there is one (so a
  postage-only connection beside it stays postage-only) and sets `import_enabled` on.
  "None (manual entry only)" turns import off on every connection with it on, inactive
  ones included (so reactivating one later does not resume importing), and leaves each
  connection's `active` flag as it was, so postage and write-back carry on.
