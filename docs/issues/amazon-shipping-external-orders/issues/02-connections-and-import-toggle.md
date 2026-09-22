# Present data sources as Connections and let a connection turn off order import

Status: needs-triage

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

- [ ] Navigation, resource labels and page titles say Connection(s); model/table/class
      names are unchanged
- [ ] `CONTEXT.md` defines Connection in terms of `DataSource`
- [ ] Migration adds the import-enabled setting, backfilled on for every existing row
- [ ] A connection with import off is skipped by the scheduler, the import command and the
      import job; feature tests cover each
- [ ] Import-only form sections and the "Run Import" action are hidden when import is off
- [ ] Origin-bound channel postage still resolves for a Shipment whose connection has
      import off but is active (test)
- [ ] AGENTS.md mentions of the Data Sources UI are updated

## Blocked by

None - can start immediately
