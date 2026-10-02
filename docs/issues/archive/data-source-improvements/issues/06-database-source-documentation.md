# Database data source has no documentation

Status: done

## Problem

The database data source is configurable entirely through the Filament form, and
nothing explains how to configure it. There's no reference for what the queries must
return, how `field_mapping` resolves, what database privileges are needed, when the
SSH tunnel is required, or what the `max_affected_rows` guard does.

This is the integration path for on-prem ERPs running on SQL Server, so it's the one
that gets configured against unfamiliar schemas by someone who isn't the author. It's
also the source that requires credentials into a customer's production ERP database,
which makes "what permissions does this actually need" a question with a security
answer, not just a convenience answer.

## Expected behavior

Add `docs/data-sources/database.md` covering:

**Setup**
- Connection fields per driver, and the driver-specific options (see issue `04` — SQL
  Server TLS/`TrustServerCertificate` in particular, which is the most likely
  first-contact failure against an on-prem instance).
- When the SSH tunnel is needed and how the host-key fields work (`SshTunnel`).
- Where the password is stored — the encrypted `secret_settings` column via
  `DataSource::secret()`, not `settings`.

**Queries**
- The contract for `shipments_query`: what columns it must return for `FieldMapper` to
  produce a valid shipment, and which internal fields are required vs optional.
- The contract for `shipment_items_query`, including the bound `shipment_reference`
  parameter and that it's bound, not interpolated.
- `mark_exported_query` and `export_query`: when they run, what's bound, and how
  `max_affected_rows` bounds the damage a bad `UPDATE` can do.
- That all read queries are constrained to read statements by `RawSqlGuard`, and what
  happens when a query violates that.
- Default table-based mode (`shipments_table` / `filters`) as the simpler alternative
  to custom SQL.

**Field mapping**
- A table of internal field → default source column, derived from `FieldMapper`, and
  how to override.
- The multi-client case: `client_column` and how `_client_column_value` resolves to a
  `Client`.

**Operating it**
- Recommended database privileges: a **read-only** user for import, with write
  access narrowed to only what `mark_exported_query` needs. Include example `GRANT`
  statements for MySQL and SQL Server.
- Scheduling (`schedule_interval`) and how to trigger a manual import.
- Troubleshooting: where import logs land (`storage/logs/shipment-import-*.log`) and
  the common failure modes.

**A worked example**
- One end-to-end example — connection, both queries, field mapping — against a
  realistic order/order-line schema.

Link it from `CLAUDE.md`'s Data Import / Export section and from the Data Source
resource form (a `helperText` link on the driver select, or an infolist note).

## Test notes

Documentation, so no automated tests. Two things to verify manually rather than
assume:

- The field-mapping table must be generated from what `FieldMapper` actually does, not
  from memory — read the class and confirm each default.
- The `GRANT` examples should be run against a real instance before publishing.

The per-ERP worked examples (Business One, Epicor, Sage) can't be written accurately
without access to a real schema. Scope this issue to one generic example plus the
reference material, and add ERP-specific appendices as real deployments happen.

## Comments

**2026-09-07 (Claude):** Implemented. `docs/data-sources/database.md` covers every
section this issue specified: setup (per-driver connection fields, SQL Server TLS, the
SSH tunnel and its host-key handling, `secret_settings`), queries (both read contracts,
the bound `:shipment_reference`, `mark_exported_query`/`export_query`, `RawSqlGuard`,
`max_affected_rows`, table mode, and the query preview), field mapping (default tables,
unmapped-by-default fields, the multi-client `client_column` path), operating it
(privileges, scheduling, manual runs, re-import behavior, troubleshooting), and one
end-to-end worked example against a generic order/order-line schema.

Linked from `AGENTS.md`'s Data Import / Export section — not `CLAUDE.md`, which is a
one-line import of `AGENTS.md` and has no section of its own — and from the form, as a
`helperText` link on the `settings.db_driver` select (only visible for the Database
driver, unlike `source_type`).

Both *Test notes* items were done rather than assumed:

- **The field-mapping tables are read off the code, not memory.** The defaults live in
  `DataSourceFactory::databaseConfigFor()`, not in `FieldMapper` — `FieldMapper` is a
  pure `external => internal` copy with no defaults of its own, which the doc says
  explicitly because it is the thing that makes an override total rather than a merge.
  Required-vs-optional is taken from `ShipmentRowPreparer::validateShipmentData()`
  (rejects on `shipment_reference`, `address1`, `city`, non-numeric `value`; warns on the
  rest).
- **The `GRANT` examples were run against real instances** — MySQL 8.4.11 and SQL Server
  2022 in disposable containers, against the worked example's schema. Verified the grants
  apply, that the least-privilege account can run all four queries (including the bound
  items query and both writes, each affecting exactly 1 row), and that it is refused a
  `DELETE` and an `UPDATE` of any column outside the granted list. Two things only a real
  run would have caught: MySQL merges the two column-level `UPDATE` grants into one
  combined grant on `orders`, and SQL Server's `CHECK_POLICY = ON` rejects a
  non-complex placeholder password — which then cascades, because every later statement
  references a login that was never created.

`RawSqlGuard`'s behavior was likewise exercised rather than read: the doc's stated
rejections (statement stacking, `WITH`, a comment-hidden `DELETE`, a `DELETE` in the
mark-exported slot) and acceptances (a lone trailing `;`, all four example queries) are
its actual output, error strings included.

**One code fix came out of writing this.** The Export Query helper text advertised
`:cost` as an available parameter. It is not: `PackageExportService`'s default
`export_field_mapping` supplies only `tracking_number`, `carrier`, `service`, `weight`,
`shipment_reference`, `fulfillment_order_id` and `amazon_order_id`, and
`DatabaseSource::exportPackage()` binds only the subset the query names — so an export
query following that hint fails at execution with `SQLSTATE[HY093]: Invalid parameter
number`. Confirmed against a live PDO connection. The helper text now lists what is
actually bound, with a comment on why the list is exactly that. Note that
`DataSourceFactory`'s `export.field_mapping` block (which does list `cost`, `height`,
`width`, `length`) is dead config — nothing reads it — and is presumably where the
`:cost` claim came from.

Scope kept as the issue directed: one generic example plus the reference material, with
the ERP-specific appendices (Business One, Epicor, Sage) named in the doc as deferred
until there is a real schema to write them from.

Not covered, so not implied by this closure: no live PostgreSQL or SQLite round-trip was
run for the doc. Both drivers' connection fields are documented from
`ImportConnectionConfig`, and the query and mapping material is driver-independent, but
the PostgreSQL-specific claims (`search_path` via the Schema field, `sslmode`) rest on
reading `build()` rather than on a connection. This matches the gap issue `04` already
recorded for SQL Server and PostgreSQL.

**2026-09-07 (Claude), review follow-up:** Four review findings, all correct, all fixed.
Each was re-verified against the code or a live connection rather than accepted on
description.

- **Export parameter list was incomplete.** The helper text and its comment claimed to
  list exactly `PackageExportService`'s default `export_field_mapping` while omitting
  `:fulfillment_order_id` and `:amazon_order_id`. Those two are not dead weight on a
  database source: `buildExportData()` reads them from shipment metadata, and a source
  flagged **Global Export Destination** receives packages from Shopify and Amazon
  sources, where they are populated. Both added to the helper text and to the doc's
  parameter table, which had wrongly annotated them "null here". The list is now checked
  against the source programmatically — it matches the default mapping exactly, with no
  extras.
- **SQL Server placeholder password failed its own note.** `'a-long-random-password'` is
  lowercase and symbols only, so it does not satisfy the `CHECK_POLICY` rule documented
  three paragraphs below it — the example would have failed exactly the way the note
  warns. Replaced with a placeholder carrying all four character classes, and the whole
  SQL Server block was then extracted from the doc and run verbatim against SQL Server
  2022 to confirm the login is created.
- **Wrong failure mode for `max_affected_rows`.** The doc claimed a quoted
  `:shipment_reference` leaves the predicate matching everything. It does not: PDO's
  placeholder scan skips quoted text, so the statement has no placeholder, and the
  supplied binding is then rejected (`HY093` on MySQL; verified via PDO). It fails loudly
  and affects zero rows. Replaced with the failure shapes that actually trip the cap —
  a `WHERE` on a non-unique column, and `:shipment_reference` used only in the `SET`
  while the `WHERE` matches a whole status — both confirmed to affect 3 rows against a
  3-row fixture. The two other places repeating the quoting claim were corrected too.
- **Concurrency claim was stale.** The doc said a double-clicked import is dropped,
  inherited from this directory's issue `02` comment, which records `dontRelease()`.
  `RunDataSourceImportJob` no longer does that: its `WithoutOverlapping` uses
  `releaseAfter(60)` with `tries = 60`, so an overlapping run is deferred and retried,
  and repeated clicks produce sequential imports. Documented as written, including why it
  is usually harmless (`mark_exported_query` leaves the second run nothing to read) and
  why it is not when that query is off. **Issue `02`'s comment is now inaccurate on this
  point** — flagged here rather than edited, since it is a historical record of what
  shipped then.

Also noted while fixing the last one: the job runs on the `imports` queue and connection,
not `default`, so a stalled `import-queue` container is the first thing to check when
Run Import appears to do nothing. Added to the doc.

The reviewer reported the targeted feature test as unrunnable — Pest Browser could not
open a listening socket in the sandbox. That is environmental. The form assertions here
need no browser: a plain `Livewire::test()` against `CreateDataSource` renders the
helper text and passes.
