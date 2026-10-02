# "Test Queries" validates syntax but shows no rows

Status: done

## Problem

The `test_queries` action (`app/Filament/Resources/DataSources/Schemas/DataSourceForm.php:427`)
calls `$pdo->prepare()` on each configured query and reports "All queries valid" or
the `PDOException` message. It never executes anything, so the operator learns the SQL
parses but not whether it returns the right rows, or whether the field mapping
resolves to sensible values.

Two further gaps:

- **The syntax check is weaker than it looks on non-MySQL drivers.** It only means
  something because emulated prepares are disabled first, and that's done for MySQL
  only (`if (($get('settings.db_driver') ?? 'mysql') === 'mysql')`). On `sqlsrv` and
  `pgsql`, `prepare()` can succeed without the server validating the statement, so
  invalid SQL may report as valid.
- **Configuring a source is guesswork.** Someone pointing PolyBag at an unfamiliar
  ERP schema has to save, run a real import, and read logs to find out whether their
  query and field mapping are right.

This matters beyond convenience: pasting a query and immediately seeing five real
rows with the mapping applied is the core of a live demo against a prospect's own
database, and it's what makes per-customer implementation cheap enough for the
mid-market to be viable.

## Expected behavior

Replace the notification-only result with a modal preview.

- **Execute the shipments query** with a row limit and render the first ~5 rows in a
  table. Show two views of each row: the raw source columns, and the mapped internal
  fields after `FieldMapper::mapShipment()` runs, so a mismatched mapping is visible
  as an empty or wrong internal field rather than an import-time surprise.
- **Execute the items query** for one previewed shipment, binding
  `shipment_reference` from the first row's resolved source record ID (mirror how
  `DatabaseSource::fetchShipmentItems()` binds it).
- **Read-only, enforced.** Run every previewed statement through
  `RawSqlGuard::assertStatementType($query, RawSqlGuard::READ, ...)` exactly as
  `DatabaseSource::fetchShipments()` does. Do **not** preview
  `mark_exported_query` or `export_query` — those are writes. Keep the existing
  prepare-only check for them so they still get some validation.
- **Row limiting must not rewrite the operator's SQL.** Don't string-append `LIMIT` —
  dialects differ (`LIMIT` vs `TOP` vs `FETCH FIRST`) and the query may already have
  one. Execute and stop consuming after N rows instead (e.g. `PDOStatement::fetch()`
  in a bounded loop, or Laravel's `cursor()`), so the same code path works on all
  three drivers.
- **Audit it.** Preview executes SQL against a customer database, so it belongs in the
  same audit trail as a real import. Follow the `executeLogged()` /
  `AuditAction` pattern in `DatabaseSource`, and see
  `tests/Feature/AuditDataSourceQueryTest.php` for the expected shape.
- **Don't log row contents.** The audit entry records that a preview ran and the
  statement, not the returned data — previewed rows are customer order data,
  including names and addresses. Render them in the modal only.
- Keep the failure path as it is today: per-query error messages, surfaced with the
  query label so the operator knows which one broke.

## Test notes

`tests/Feature/DataSourceResourceTest.php` for the form/action conventions and
`tests/Feature/DatabaseSourceRawSqlGuardTest.php` for exercising the source against a
sqlite/MySQL test connection rather than a live external database.

Worth asserting:

- A `SELECT` preview returns rows and the mapped output contains the expected internal
  keys for a configured `field_mapping`.
- A non-read statement in `shipments_query` is rejected by `RawSqlGuard` and never
  executed.
- `mark_exported_query` and `export_query` are not executed by the preview.
- The row limit holds — a query matching 1,000 rows yields only the preview count.
- An audit row is written, and it does not contain row data.

## Comments

**2026-09-07 (Claude):** Implemented. *Test Queries* is now *Preview Queries* and opens a
modal instead of sending a notification.

**Where the work lives.** `DatabaseSource::preview()` returns a `QueryPreviewResult`
(shipments, items, the reference the items were bound to, and per-label errors). The
Filament action is thin: it opens the same test connection the *Test Connection* button
uses, builds a `DatabaseSource` config from the current form state, and renders the
result. Putting it on the source rather than in the form is what makes the guard, the
audit trail and the field mapping the *same* code an import runs, and lets the
interesting assertions run against a sqlite connection with no Livewire involved.

**Preview and import cannot drift on mapping.** `mapShipmentRow()` was extracted from
`fetchShipments()` and is shared, `_client_column_value` included, so the mapped columns
in the modal are literally what the importer would receive. `DataSourceFactory`'s config
builder was split into a public `databaseConfigFor()` so the form gets the same
field-mapping defaults for an unsaved source; the connection-registering half stays
private, because the preview's connection is already registered (and, with an SSH tunnel,
already repointed at the local end of it).

**Row limiting.** No `LIMIT` is appended to operator SQL — the audit test asserts the
recorded statement is byte-identical to what was configured, which is the guard against
someone "fixing" this later with string concatenation that works on MySQL and breaks on
SQL Server. `fetchBoundedRows()` prepares the statement and stops fetching at N.

Stopping consumption turned out to be only half a bound, which is why this drops to PDO
instead of the connection's `cursor()`. Measured against a 100k-row MySQL table: `cursor()`
with a `break` after five rows still cost **+15–22 MB** and 181 ms, because pdo_mysql
buffers the entire result set into the worker at `execute()` — the operator's query decides
how many rows that is, and an unfiltered ERP table is the normal case here. Setting the
unbuffered attribute on the *handle* (the same option passed to `prepare()` is silently
ignored — also measured) brings the same preview to **+0.7 MB**. It is restored afterwards
because import connections outlive a preview, and the statement's cursor is closed before
the items query runs on the same connection.

**Writes.** `mark_exported_query` and `export_query` are never executed. They keep the
prepare-only check, reported in a *Write queries* section of the modal. The weaker
`prepare()` semantics on `sqlsrv`/`pgsql` noted in the problem statement are unchanged —
the reads no longer depend on that check being meaningful, which was the point, but the
writes still do.

**Audit.** Both previewed reads go through `executeLogged()`, as `preview_shipments` and
`preview_shipment_items`, adding a `preview_rows` count. The bound `shipment_reference`
value is deliberately *not* recorded, unlike `fetch_shipment_items` — it is read out of a
previewed row, and previewed rows stay in the modal. A test greps every audited value out
of the metadata and fails if a name, reference or SKU appears.

**One click, one execution.** Filament evaluates `modalContent` on every render while the
modal is open, so the queries must not live there. The preview is built in `mountUsing()`,
which runs once when the action is mounted, and held server-side under a random token
merged into the mounted action's arguments; only the token travels in the Livewire
snapshot, and the cache key is scoped to the viewer as well. A later render reads the
cached preview; a fresh click runs the queries again, which is what a person clicking
*Preview Queries* a second time means. The test asserts one execution across a `$refresh`
and a field change, and a second on a re-mount.

Past the five-minute TTL the modal says so and asks for a fresh click. It deliberately does
**not** rebuild: a rebuild that is not itself cached puts every later render of that modal
back on the customer database, which is the same defect deferred by the length of the TTL,
and re-caching instead would mean querying a customer database with nobody having clicked
anything. Pinned by a test that travels past the TTL, re-renders, and asserts both the
notice and that no second execution was audited.

Two earlier attempts at this were wrong and are worth recording. `once()` does not memoize
here at all — its hash includes the closure's captured variables and Filament's `Get` is a
new object per render. Caching in `request()->attributes` looked right and was measured
useless: every Livewire round trip is a new request, so an open modal re-ran and re-audited
the customer queries on each one (1, 2, 3, 4 audit rows across a mount and two refreshes).
The test that was supposed to cover this called `getModalContent()` twice inside a single
test request and so never exercised the lifecycle it claimed to.

**Statement timeout.** A connect timeout does not bound a query that reaches the server, so
a lock or a bad plan on the operator's SQL would hold a PHP worker until the proxy returned
a 504. `ImportConnectionConfig::applyStatementTimeout()` caps it at 10s on the preview
connection, per driver: `max_execution_time` on MySQL, `statement_timeout` on PostgreSQL,
`SQLSRV_ATTR_QUERY_TIMEOUT` on SQL Server (which has no server-side setting to `SET`, and
`LOCK_TIMEOUT` only covers time spent blocked). MariaDB spells it `max_statement_time` and
in seconds, so both spellings are tried and whichever is accepted identifies the server —
not hypothetical, the local MariaDB rejects the MySQL one. Failure to set the cap is
swallowed: a server that will not take it is still worth previewing. Verified against a
real server, where a deliberately slow join aborted after 2.1s and surfaced through the
existing per-label error path.

The cap is not applied to the import runtime, only to the preview — imports run in queue
workers, where a long query costs a worker rather than a web request and a 504.

Verified end to end in the local app against a throwaway sqlite "ERP" database: three
order rows and two line rows previewed raw and mapped, items bound to the first
shipment's reference, `Mark exported query: valid`, source rows untouched afterwards, and
exactly two audit entries carrying no row data.
