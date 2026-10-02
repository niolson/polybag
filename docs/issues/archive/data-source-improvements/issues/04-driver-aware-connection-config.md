# Database source connection config is MySQL-only for every driver

Status: done

## Problem

The `db_driver` select in `DataSourceForm` offers MySQL, PostgreSQL, and SQL Server
(`app/Filament/Resources/DataSources/Schemas/DataSourceForm.php:294`), but both places
that build the actual connection hardcode MySQL-specific config regardless of the
selected driver.

`DataSourceFactory::buildDatabaseConfig()`
(`app/Services/ShipmentImport/DataSourceFactory.php`, the `config([...])` block) sets:

- `charset => 'utf8mb4'` — not a valid charset for `sqlsrv`; PostgreSQL wants `utf8`
- `collation => 'utf8mb4_unicode_ci'` — MySQL-only; meaningless for `pgsql`/`sqlsrv`
- `strict => true` — MySQL-only (drives `sql_mode`)
- `port => (int) ($settings['db_port'] ?? 3306)` — 3306 default for every driver, where
  PostgreSQL is 5432 and SQL Server is 1433

`DataSourceForm::openTestConnection()` duplicates the same block and adds
`PDO::ATTR_TIMEOUT => 10`, which `pdo_sqlsrv` does not honour the way it does for
MySQL (SQL Server uses a DSN-level `LoginTimeout`, and
`PDO::SQLSRV_ATTR_QUERY_TIMEOUT` for statements).

Two consequences:

1. **SQL Server and PostgreSQL sources are untested and probably broken.** SQL Server
   matters most — it's the dialect behind the on-prem mid-market ERPs (SAP Business
   One, Epicor, Sage 100, Dynamics GP/NAV, Macola) that the database source is the
   integration path for.
2. **The two config blocks can drift.** Because the form's test path and the import
   path build connections independently, it's possible for "Test Connection" to
   succeed while the real import fails, or vice versa. Fixing one would not fix the
   other.

## Expected behavior

Extract a single shared connection-config builder — something like
`App\Services\ShipmentImport\DatabaseConnectionConfig::build(array $settings, string $connectionName): array`
— and have both `DataSourceFactory::buildDatabaseConfig()` and
`DataSourceForm::openTestConnection()` call it. Neither should assemble connection
config inline any more.

The builder resolves per driver:

- **Default port** when `db_port` is empty: `mysql` 3306, `pgsql` 5432, `sqlsrv` 1433.
- **`mysql`**: keep current behavior (`charset` `utf8mb4`, `collation`
  `utf8mb4_unicode_ci`, `strict` true).
- **`pgsql`**: `charset` `utf8`; omit `collation` and `strict`; support optional
  `sslmode` and `search_path` settings.
- **`sqlsrv`**: omit `charset`, `collation`, and `strict` entirely. Expose optional
  `encrypt` and `trust_server_certificate` settings — Microsoft's ODBC Driver 18
  defaults to `Encrypt=yes`, so connecting to an on-prem SQL Server with a
  self-signed certificate fails unless `TrustServerCertificate=yes` is set. This is
  the single most likely first-contact failure for an on-prem ERP deployment, so it
  needs to be reachable from the form, not just config.

Also:

- Add the driver-conditional fields to the form so `sqlsrv`/`pgsql` options are
  visible only for the relevant driver, matching the existing `visible()` pattern
  around `DataSourceForm.php:438`.
- Timeouts: keep `PDO::ATTR_TIMEOUT` for `mysql`/`pgsql`; for `sqlsrv` set
  `LoginTimeout` in the connection config and `PDO::SQLSRV_ATTR_QUERY_TIMEOUT` on the
  statement. The intent documented in the existing comment — fail fast rather than
  hang until the reverse proxy 504s — must hold for all three drivers.
- Existing saved records: a `DataSource` created before this change may have `3306`
  stored in `settings.db_port` with a non-MySQL driver. Decide with the maintainer
  whether to leave those (user-visible and editable) or fix them up in a migration.
  Leaving them is probably fine — there are no known non-MySQL sources in the wild
  yet.

## Test notes

`tests/Feature/DataSourceResourceTest.php` shows the Livewire form conventions;
`tests/Feature/DatabaseSourceRawSqlGuardTest.php` shows how the database source is
exercised without a live external DB.

The valuable tests here are unit tests on the extracted builder — assert the exact
config array per driver, including that `collation`/`strict` are **absent** (not
null) for `sqlsrv`, and that port defaults resolve per driver. That gets coverage
without needing a real SQL Server.

Add a Filament form test asserting the `sqlsrv`-only fields appear when
`settings.db_driver` is `sqlsrv` and are hidden for `mysql`.

A real SQL Server round-trip needs an actual instance and can't run in CI as-is.
Note in the PR whether it was manually verified against one; if not, say so
explicitly — this issue is specifically about a path nobody has run yet.

## Comments

**2026-09-07 (Claude):** Closing as `done` — this shipped 2026-08-26 in `65e54cc`,
"Add working PostgreSQL and SQL Server support for database imports", four days after
the 2026-08-22 premise re-verification recorded in `docs/issues/README.md`. The ticket
was never updated, so it has been reading as open work since.

Verified against `main` rather than taken from the commit message. Every item under
*Expected behavior* is present in `app/Services/ShipmentImport/ImportConnectionConfig.php`:

- Single shared builder, `ImportConnectionConfig::build()`. Both call sites go through
  it — `DataSourceFactory` and `DataSourceForm::openTestConnection()` — so the drift
  risk named in the problem statement is closed, not just the config shape.
- Per-driver default ports via `defaultPort()` (3306 / 5432 / 1433), used for the form
  default and by the SSH tunnel's remote-port fallback.
- `pgsql`: `charset` `utf8`, no `collation`/`strict`, plus `sslmode` and `search_path`.
- `sqlsrv`: no `charset`/`collation`/`strict`, plus `encrypt` and
  `trust_server_certificate` — reachable from the form, as the issue required.
- Timeouts resolve per driver in `withConnectTimeout()`.

Three things went further than the issue asked, worth recording because they change
what a reader should expect to find:

- **`sqlite` was added as a fourth driver**, with its own branch in `build()` that
  returns a file-path config and no host. `usesHost()` is the predicate the form and
  tunnel key off, so the driver-conditional field logic is written in terms of "does
  this driver connect over TCP", not a per-driver list.
- **The real blocker was the image, not the config.** Neither driver existed at
  runtime — the Dockerfile installed only `pdo_mysql`. It now installs `pdo_pgsql`,
  and `pdo_sqlsrv` via unixODBC plus a version-pinned MS ODBC Driver 18 `.deb` with a
  hardcoded per-arch SHA256. The config fix alone would not have made either driver
  work.
- **`PDO::ATTR_TIMEOUT` on `sqlsrv` was worse than "not honoured"**, which is what this
  issue assumed. `pdo_sqlsrv` throws `SQLSTATE[IMSSP]` on the attribute before reaching
  the server, so it would have failed every SQL Server connection outright regardless of
  credentials. That reasoning is in the docblock on `withConnectTimeout()`.

Tests: `tests/Unit/ImportConnectionConfigTest.php` (128 lines, the per-driver config
assertions this issue specified) and `tests/Feature/DataSourceFactoryConnectionTest.php`
(72 lines, including SQL Server TLS settings reaching the connection).

Two things this issue asked for that are **not** covered, so they are not implied by
this closure:

- No Filament form test asserting the `sqlsrv`-only fields appear for `sqlsrv` and are
  hidden for `mysql`. The `visible()` closures exist and are correct by inspection;
  nothing pins them.
- **No live round-trip against a real SQL Server or PostgreSQL instance is recorded
  anywhere.** The issue asked for this to be stated explicitly if it did not happen, and
  as far as the repo shows it did not. The path is now plausibly correct and covered by
  unit tests; it is still a path nobody has run end to end.

The migration question — existing `DataSource` rows holding `3306` with a non-MySQL
driver — was resolved by leaving them, which the issue judged "probably fine". Port is
user-visible and editable, and `build()` only falls back to the driver default when
`db_port` is empty, so a stored `3306` is honoured as written rather than silently
corrected.
