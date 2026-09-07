# The Database data source

The Database data source reads shipments out of an existing system's database —
typically an on-prem ERP or WMS — and optionally writes tracking back to it. It is
configured entirely through **Integrations → Data Sources**, with the driver set to
**Database (SQL)**.

Everything below is reference material for someone pointing PolyBag at a schema they
did not design. Two things are worth reading before anything else:

- **PolyBag runs your SQL verbatim, on a schedule, against the database you name.**
  The query fields are not a query builder. They are the actual statements. What
  constrains them is [`RawSqlGuard`](#what-the-guard-allows) (statement type) and
  [`max_affected_rows`](#max_affected_rows) (blast radius) — nothing else.
- **The credentials you enter are credentials into someone's production ERP.** Use a
  dedicated least-privilege account. [Database privileges](#database-privileges) has
  verified `GRANT` statements for MySQL and SQL Server.

## Contents

- [Setup](#setup)
- [Queries](#queries)
- [Field mapping](#field-mapping)
- [Operating it](#operating-it)
- [A worked example](#a-worked-example)

---

## Setup

### Drivers and connection fields

Fields live in the **Database Connection** section of the Data Source form. Which ones
appear depends on the driver, because they are not all meaningful for every one.

| Field | Setting key | Applies to | Notes |
| --- | --- | --- | --- |
| Driver | `db_driver` | all | `mysql`, `pgsql`, `sqlsrv`, `sqlite` |
| Host | `db_host` | mysql, pgsql, sqlsrv | Required. Hidden for SQLite. |
| Port | `db_port` | mysql, pgsql, sqlsrv | Defaults to 3306 / 5432 / 1433 when the driver is switched |
| Database | `db_database` | all | For SQLite this is a **file path**, and the field relabels itself accordingly |
| Username | `db_username` | mysql, pgsql, sqlsrv | Required |
| Password | `db_password` | mysql, pgsql, sqlsrv | Stored encrypted — see below |
| Schema | `db_schema` | pgsql only | Sets `search_path`. Blank means `public`. |
| Encrypt Connection | `db_encrypt` | sqlsrv only | Default **on** |
| Trust Server Certificate | `db_trust_server_certificate` | sqlsrv only | Default **off** |

The connection config is assembled in
[`ImportConnectionConfig::build()`](../../app/Services/ShipmentImport/ImportConnectionConfig.php).
Both the import runtime and the form's **Test Connection** action build it there, so the
connection you test is the connection that runs.

`db_port` is only defaulted when it is left empty. Changing the driver in the form
rewrites the port to that driver's default, but a value already saved on an existing
record is honoured as written — a source created before multi-driver support may hold
`3306` alongside a non-MySQL driver, and will keep trying 3306 until you change it.

### SQL Server: the first-contact failure

Microsoft's ODBC Driver 18 defaults to `Encrypt=yes`, and an on-prem SQL Server usually
presents a self-signed certificate. The handshake then fails before credentials are ever
checked, which reads as "wrong password" and is not.

Turn **Trust Server Certificate** on for such a server. Leave **Encrypt Connection** on:
trusting the certificate keeps the connection encrypted, it just stops validating who is
on the other end. Turn Encrypt off only for a server that genuinely cannot do TLS —
that sends the ERP's order data, and the login, in the clear.

Prefer installing a real certificate the PolyBag container trusts, and leaving both
toggles at their defaults. Trust-server-certificate is the pragmatic option, not the
correct one.

### When you need the SSH tunnel

PolyBag connects from its container. Enable the tunnel (in the collapsed **SSH Tunnel**
section) when the database is not reachable from there directly — the usual case being a
customer network where the only inbound path is an SSH bastion.

The tunnel is not a substitute for TLS between PolyBag and the database on a network you
already reach. It is for reachability.

| Field | Setting key | Notes |
| --- | --- | --- |
| SSH Host / Port / User | `ssh_host`, `ssh_port`, `ssh_user` | The bastion, not the database server |
| Remote DB Host / Port | `ssh_remote_host`, `ssh_remote_port` | Only if the database is on a *different* host than the bastion. Blank falls back to the Host/Port above. |
| SSH Host Key | `ssh_host_key` | Required. See below. |
| SSH Public Key | — | Read-only. PolyBag's own key, to install on the bastion. |

**Host key.** `StrictHostKeyChecking=yes` is always on, and there is no first-use
trust-on-connect. You must paste the bastion's host key or the tunnel will not open.
Get it by running, from anywhere that can reach the bastion:

```bash
ssh-keyscan -t ed25519 bastion.example.com
```

Paste the output line **as-is**. The hostname at the start of that line has to match the
**SSH Host** field exactly — `bastion.example.com` and `10.0.0.5` are different entries
even when they are the same machine.

**PolyBag's key.** Generate it once with `php artisan app:generate-ssh-key`, then copy
the **SSH Public Key** field into `~/.ssh/authorized_keys` on the bastion. The field
already prefixes `restrict,port-forwarding`. Narrow it further on the bastion side:

```
restrict,port-forwarding,permitopen="erp-db.internal:1433" ssh-ed25519 AAAA... polybag
```

That `permitopen` is worth adding. It means a compromised PolyBag can forward to the
database and nothing else on that network.

The tunnel is opened per import run and torn down after it, and the connection's host and
port are rewritten to the local end for the duration.

### Where the password is stored

`db_password` is one of `DataSource::SECRET_SETTINGS_KEYS`, so it is written to the
**`secret_settings`** column — cast `encrypted:array`, and hidden from serialization —
not to `settings`. Read it with `$dataSource->secret('db_password')`; it will never
appear in `$dataSource->settings`.

In the form the field shows `Configured (leave empty to keep)` once set, and only
overwrites the stored value when you actually type a new one. Leaving it blank on save
is not "clear the password".

The same applies to every other credential on a Data Source (`client_id`,
`client_secret`, `refresh_token`, `oauth_access_token`). None of them are in `.env`, and
nothing migrates a stray plaintext key into place — a credential that is not in the UI
has to be re-entered there.

---

## Queries

There are two ways to read shipments, and the choice is per source.

### Table mode (the simple path)

Leave **Custom Shipments Query** blank. PolyBag then builds
`SELECT * FROM <shipments_table>` with an optional set of equality filters, and reads
items with `SELECT * FROM <shipment_items_table> WHERE shipment_id = <reference>`.

| Setting | Form field | Default |
| --- | --- | --- |
| `shipments_table` | Shipments Table | `shipments` |
| `shipment_items_table` | Items Table | `shipment_items` |
| `filters` | *(no form field)* | none |

`filters` is a map of column → value, or column → list of values (rendered as `IN`). It
has no form field, so it is settable only by writing `settings.filters` directly. In
practice, anything needing a filter needs a custom query soon after, so table mode is
best treated as the path for a purpose-built staging table you control — one whose
columns already carry PolyBag's default names, and which the ERP populates.

Table mode's items lookup hardcodes the column name `shipment_id`. If your items table
keys on anything else, you need a custom items query.

### Custom queries

Filling in **Custom Shipments Query** overrides table mode entirely — `shipments_table`
and `filters` are then ignored.

#### `shipments_query`

One `SELECT`. Each row it returns becomes one candidate shipment. The column names it
returns are matched against the [field mapping](#field-mapping), and anything unmapped is
dropped silently.

A row is **rejected** — recorded as an import error, no shipment written — when any of
these are missing after mapping:

| Internal field | Why |
| --- | --- |
| `shipment_reference` | The key everything else hangs off |
| `address1` | Cannot ship without it |
| `city` | Cannot ship without it |

`value`, if present, must be numeric and non-negative or the row is rejected.

A row is **accepted with a warning** (imported, `validation_message` set, visible in the
shipment list) for: missing US postal code, malformed US postal code, missing or invalid
US state, missing state where the country requires one, an unparseable phone number, and
an invalid email — which is dropped rather than stored.

Everything else is optional. Country defaults to `US` when absent.

The query gets no bound parameters. It should therefore carry its own "not yet imported"
predicate — see [`mark_exported_query`](#mark_exported_query) for the usual way to make
that predicate mean something.

#### `shipment_items_query`

One `SELECT`, run **once per shipment**, with exactly one parameter available:

```sql
SELECT ... FROM order_lines WHERE order_no = :shipment_reference
```

`:shipment_reference` is **bound**, not interpolated — it is passed to PDO as a
parameter, so the value never becomes part of the statement text and cannot alter it.
Write it bare. Quoting it yourself (`'*:shipment_reference*'`) makes it an ordinary
string literal — PDO's placeholder scan skips quoted text — so the statement ends up with
no placeholder at all and is rejected for having a binding it cannot place
(`HY093` on MySQL). It fails loudly; it does not silently match every row.

Its value is the shipment's resolved source record ID: the mapped `source_record_id` if
your mapping produces one, otherwise `shipment_reference`. With the default mapping,
that is whatever your `id` column returned.

Item rows are only imported when they resolve to a product, which means **`sku` is
effectively required** — an item row without one is skipped silently. `quantity`
defaults to 1. Products are created or updated from item rows automatically unless
`SHIPMENT_IMPORT_AUTO_UPDATE_PRODUCTS=false`.

Note that this query runs per shipment, not per batch. A shipments query returning 2,000
rows means 2,000 executions of the items query, so it wants an index on the join column.

#### `mark_exported_query`

Gated by the **Mark Exported After Import** toggle. One `UPDATE` or `INSERT`, run once
per successfully imported shipment, with `:shipment_reference` bound as above.

This is what stops the next run re-reading the same orders, and it only works in concert
with the shipments query: the shipments query filters on the flag, this sets it.

```sql
UPDATE orders SET wms_exported_at = NOW()
 WHERE order_no = :shipment_reference AND wms_exported_at IS NULL
```

It also runs for a shipment that was *skipped* by the [existing-shipment
behavior](#re-importing) — the local record is left alone, but the source record is still
marked so it stops coming back.

If it throws, the shipment is still imported; the failure is recorded as an import error.
So a broken mark-exported query does not lose orders, it re-imports them.

#### `export_query`

Gated by **Write Tracking Back to Source Database**, in the collapsed **Database Export**
section. One `INSERT` or `UPDATE`, run once per shipped package.

Available bound parameters:

| Parameter | Value |
| --- | --- |
| `:tracking_number` | The package's tracking number |
| `:carrier` | Carrier of record |
| `:service` | **Confirmed** service only — never an inferred guess (ADR-0003, decision 7) |
| `:weight` | Package weight |
| `:shipment_reference` | The originating shipment's reference |
| `:fulfillment_order_id` | The shipment's Shopify fulfillment order ID |
| `:amazon_order_id` | The shipment's Amazon order ID |

The last two are read from the shipment's metadata, so they are null for a shipment this
source imported itself — but *not* for one that came from a Shopify or Amazon source,
which is exactly what a **Global Export Destination** receives.

Only the parameters your query actually names are bound, so you can use any subset. But
**naming a parameter that is not on that list fails the export** with
`SQLSTATE[HY093]: Invalid parameter number` — the parameter is simply not supplied, and
PDO rejects the mismatch. `:cost`, `:height`, `:width` and `:length` are *not* available
by default; asking for them is the most likely way to break a working export.

Widening the list means setting `settings.export_field_mapping` directly (there is no
form field); the derivable values are in `PackageExportService::buildExportData()`.

Export is dispatched per package after shipping and retried by
`php artisan packages:export --scheduled`, which the scheduler runs every five minutes.
A failure classified as permanent — including a `max_affected_rows` rollback — is not
retried.

Turning on **Global Export Destination** (multi-client installs only) makes this source
the destination for *every* shipped package, including manual shipments that came from no
source at all. `:shipment_reference` will be null for those.

### What the guard allows

Every query field is checked by
[`RawSqlGuard`](../../app/Services/ShipmentImport/RawSqlGuard.php), both as a form
validation rule on save and again at execution time.

| Field | Allowed leading keyword |
| --- | --- |
| `shipments_query`, `shipment_items_query` | `SELECT` |
| `mark_exported_query` | `UPDATE`, `INSERT` |
| `export_query` | `INSERT`, `UPDATE` |

The rules, in full:

- **One statement.** A single trailing `;` is fine; a second one is not. `SELECT 1; DROP
  TABLE orders` is rejected with *"must be a single SQL statement (remove the extra
  `;`)"*.
- **Leading keyword only, after comments are stripped.** `-- SELECT` followed by a
  `DELETE` does not sneak through.
- **`WITH` is not a read.** A CTE can lead an `UPDATE` or `DELETE` on MySQL 8 and
  PostgreSQL, so common table expressions are rejected in the read fields even when the
  CTE is innocent. Use a subquery or a view instead.

A violation on save is a form validation error. A violation at execution time — a query
saved before a rule tightened, say — aborts the run with the same message, recorded as a
configuration failure.

This is a guard on statement *type*. It is not a substitute for
[database privileges](#database-privileges): a `SELECT` against a table the account can
read is allowed by the guard, whatever that table holds.

### `max_affected_rows`

**Max Affected Rows Per Write**, default `1`. It applies to `mark_exported_query` and
`export_query` — not to the read queries.

Each write runs inside a transaction. If it affects more rows than the cap, the
transaction is **rolled back** and the operation fails permanently:

```
Query affected 4182 rows, exceeding the configured limit of 1 — rolled back.
```

These are per-record operations, so 1 is correct. A write affecting many rows means a
`WHERE` that does not actually narrow to one — the usual causes being a predicate on a
column that is not unique (a customer or batch reference rather than the order key), or a
statement that uses `:shipment_reference` only in its `SET` clause while the `WHERE`
matches a whole status:

```sql
-- Both trip the cap. Neither is obviously wrong on sight.
UPDATE orders SET wms_exported_at = NOW() WHERE cust_ref = :shipment_reference
UPDATE orders SET last_ref = :shipment_reference WHERE status = 'R'
```

The cap is what turns that from "the ERP's entire order table is now marked shipped" into
one failed export. Check the affected-row count in the audit log after the first live
run: a write that is quietly hitting two rows every time is the thing this catches
before the schema ever holds enough rows to matter.

Raise it only for a schema that legitimately holds several rows per shipment, and only to
the number that schema actually implies.

### Previewing

**Preview Queries**, at the foot of the Database Query section, runs the two read queries
against the configured connection and shows the first five rows twice: as raw source
columns, and as the internal fields your mapping resolves them to. A mapping that is
quietly dropping a column shows up here rather than mid-import.

It never writes. The mark-exported and export queries are *prepared and discarded* — a
syntax and parameter check only. Read queries are bounded by consuming five rows rather
than by rewriting your SQL, and a ten-second statement timeout is applied where the
driver supports one.

A failing items query is reported against its own label instead of aborting, so a broken
items query still lets you see the shipment rows.

---

## Field mapping

`field_mapping` translates the column names your query returns into PolyBag's internal
field names. It is applied by
[`FieldMapper`](../../app/Services/ShipmentImport/FieldMapper.php), which does exactly
one thing: for each `external => internal` pair, if the row has a key named `external`,
copy its value to `internal`. No type coercion, no fallbacks, no case-insensitivity.
A column the mapping does not name is dropped.

### Defaults

These are the defaults in
[`DataSourceFactory::databaseConfigFor()`](../../app/Services/ShipmentImport/DataSourceFactory.php).
The left column is what your query must name the column — with `AS` aliases, usually.

**Shipments** (`field_mapping.shipment`)

| Source column | Internal field | |
| --- | --- | --- |
| `id` | `shipment_reference` | **required** |
| `address1` | `address1` | **required** |
| `city` | `city` | **required** |
| `first_name` | `first_name` | |
| `last_name` | `last_name` | |
| `company` | `company` | |
| `address2` | `address2` | |
| `state` | `state_or_province` | warns if missing where the country needs one |
| `zip` | `postal_code` | warns if missing or malformed for US |
| `country` | `country` | defaults to `US` |
| `phone` | `phone` | normalized to E.164; warns if unparseable |
| `email` | `email` | dropped with a warning if invalid |
| `value` | `value` | rejects the row if non-numeric or negative |
| `shipping_method` | `shipping_method_id` | a *reference*, resolved — see below |
| `channel` | `channel_id` | a *reference*, resolved — see below |

**Items** (`field_mapping.shipment_item`)

| Source column | Internal field | |
| --- | --- | --- |
| `sku` | `sku` | required in practice — no SKU, no product, row skipped |
| `name` | `name` | falls back to the SKU when absent |
| `description` | `description` | |
| `barcode` | `barcode` | |
| `quantity` | `quantity` | defaults to 1 |
| `weight` | `weight` | written to the Product, not the item |
| `value` | `value` | |
| `transparency` | `transparency` | Amazon Transparency code |

### Fields with no default mapping

These internal fields are honoured if you map a column to them, but nothing maps to them
out of the box:

| Internal field | On | Notes |
| --- | --- | --- |
| `source_record_id` | shipment | The key used for dedupe and bound as `:shipment_reference`. Defaults to `shipment_reference`. Map it separately when the ERP's stable internal ID differs from the human-facing order number. |
| `phone_extension` | shipment | Otherwise parsed out of `phone` |
| `deliver_by` | shipment | |
| `metadata` | shipment | JSON-encoded as stored |
| `location_id` | shipment | PolyBag's numeric `Location` ID, not a name or the ERP's warehouse code |
| `source_item_id` | item | A stable per-line key. Without it, re-imports match lines by product, so two lines of the same SKU collapse. |

### Overriding the mapping

**There is no form field for `field_mapping`.** The whole map is replaced, not merged, by
whatever is in `settings.field_mapping` — so an override must list every field you want,
not just the ones that differ.

The practical approach is therefore to **alias in SQL** rather than override the map:

```sql
SELECT o.order_no AS id, o.ship_addr1 AS address1, ...
```

That keeps the mapping at its defaults, keeps the translation visible next to the query
it belongs to, and is what the [worked example](#a-worked-example) does. Reach for an
override only when you cannot alias — table mode against a table you do not control.

To override, write the setting directly:

```php
$source = App\Models\DataSource::find(3);
$source->settings = array_merge($source->settings, [
    'field_mapping' => [
        'shipment' => ['ORDNO' => 'shipment_reference', 'ADDR1' => 'address1', /* … all of them … */],
        'shipment_item' => ['ITEMNO' => 'sku', 'QTY' => 'quantity'],
    ],
]);
$source->save();
```

Then use **Preview Queries** to confirm the mapped columns are what you expect.

### References that get resolved

`shipping_method_id` and `channel_id` are misleadingly named: after mapping they hold the
*source's* string, not a PolyBag ID. Each is resolved against the client's aliases
(Shipping Method Aliases, Channel Aliases), falling back to a literal PolyBag ID if the
string is one.

An unresolved reference is not an error. The shipment imports with
`shipping_method_reference` set and `shipping_method_id` null, which is what surfaces it
for mapping. Aliases are client-scoped, so a multi-client source needs the alias on each
client that uses it.

### Multi-client sources

With multi-client mode on, a Data Source normally belongs to one **Client**. A single ERP
database holding several brands can instead route each row to its own client:

1. Set **Client Column** (`client_column`) to the column in each shipment row that names
   the brand.
2. Leave the source's **Client** field alone — it is ignored per row when a client column
   is set.

The column's value is carried alongside the mapped row as `_client_column_value` and
matched to a `Client` **by name, case-insensitively, trimmed**. Not by code, not by ID.

A value matching no client is **not an error**: the row imports against the source's
default client instead. So a brand renamed in PolyBag but not in the ERP silently starts
landing under the wrong client. Keep client names in step with whatever the ERP calls
them, and check the preview's `client_name` column after adding a brand.

The column is read from the **raw** row, so it needs no mapping entry — but it does need
to be in the query's `SELECT` list.

---

## Operating it

### Database privileges

Give PolyBag its own account. Read-only for everything the import needs, with write
access narrowed to the specific columns `mark_exported_query` and `export_query` touch —
which is a materially different thing from "an account that can write to the orders
table".

Column-level `UPDATE` grants are the mechanism, and both MySQL and SQL Server support
them. The statements below were run against MySQL 8.4 and SQL Server 2022 for the
[worked example](#a-worked-example) schema, and the denials were confirmed as well as the
grants.

**MySQL / MariaDB**

```sql
CREATE USER 'polybag_import'@'%' IDENTIFIED BY 'a-long-random-password';

-- Read: only the tables the queries actually name.
GRANT SELECT ON erp.orders      TO 'polybag_import'@'%';
GRANT SELECT ON erp.order_lines TO 'polybag_import'@'%';

-- Write: only the columns mark_exported_query sets.
GRANT UPDATE (wms_exported_at) ON erp.orders TO 'polybag_import'@'%';

-- Write: only the columns export_query sets. Omit if export is off.
GRANT UPDATE (tracking_no, carrier_name, service_name, actual_weight, shipped_at)
  ON erp.orders TO 'polybag_import'@'%';
```

Narrow `'%'` to the address PolyBag connects from — the app container's, or `127.0.0.1`
when it arrives through an SSH tunnel.

**SQL Server**

```sql
-- The placeholder below is shaped to satisfy CHECK_POLICY; replace the value,
-- keeping the mix of character classes. See the note under this block.
CREATE LOGIN polybag_import WITH PASSWORD = 'Rep1ace-Me-With-A-Random-Value7', CHECK_POLICY = ON;
GO
USE erp;
GO
CREATE USER polybag_import FOR LOGIN polybag_import;
GO

GRANT SELECT ON OBJECT::dbo.orders      TO polybag_import;
GRANT SELECT ON OBJECT::dbo.order_lines TO polybag_import;

GRANT UPDATE ON OBJECT::dbo.orders (wms_exported_at) TO polybag_import;

GRANT UPDATE ON OBJECT::dbo.orders
      (tracking_no, carrier_name, service_name, actual_weight, shipped_at)
      TO polybag_import;
GO
```

With `CHECK_POLICY = ON` the password must satisfy the server's complexity policy — at
least eight characters from three of {uppercase, lowercase, digits, symbols} — or
`CREATE LOGIN` fails with *"Password validation failed"* and every statement after it
fails too, since the user was never created.

Do **not** add the login to `db_datareader`/`db_datawriter`. `db_datawriter` grants
`INSERT`, `UPDATE` and `DELETE` on every table in the database, which gives away the
entire point of the column-level grants.

What this buys, verified against both servers: a `DELETE` is refused, and an `UPDATE` of
any column outside the granted list is refused *by column name*. `RawSqlGuard` already
blocks a `DELETE` in a query field — but the guard protects against a misconfigured
PolyBag, and the grants protect against a compromised one.

Two more, worth checking on the ERP side:

- The read queries run on a schedule against a production OLTP database. Point them at a
  replica where one exists.
- Grant `SELECT` on tables, not on the whole schema. A query that joins a table nobody
  meant PolyBag to read will then fail loudly instead of working.

### Scheduling

**Import Schedule** (`schedule_interval`) on the Data Source: every 5 / 15 / 30 minutes,
hourly, every 6 hours, or daily. Blank means manual only.

Scheduling is driven by the `scheduler` container running `schedule:work`, which rebuilds
the schedule from the Data Source table once a minute — so a new or re-scheduled source
is picked up within a minute, with no restart. A source is scheduled only when its
`active` flag is on *and* it has an interval. Runs are `withoutOverlapping`, so a run
that takes longer than the interval does not stack.

Pick an interval against how long a run takes, remembering the items query runs once per
shipment. Every 5 minutes against a slow ERP over an SSH tunnel is how you end up with
runs perpetually skipped for overlap.

### Running an import manually

- **UI** — **Run Import Now** on the Data Source edit page, or **Run Import** as a row
  action in the Data Sources table. It queues `RunDataSourceImportJob`, which is
  `WithoutOverlapping` per source, and notifies you when it finishes.

  Overlap is **deferred, not discarded**. A second run queued while the first is going is
  released back to the queue and retried 60 seconds later, up to 60 times — so a
  double-click gives you two imports back to back, not one. That is usually harmless,
  because `mark_exported_query` means the second run finds nothing left to read; without
  it, the second run re-imports everything the first just did. Click once.

  The job runs on the **`imports`** queue and connection, which is the `import-queue`
  container's queue — not `default`. If nothing happens when you click Run Import, check
  that worker before anything else.
- **CLI** —

  ```bash
  php artisan shipments:import --source-id=3
  php artisan shipments:import --all            # every active source
  php artisan shipments:import --source-id=3 --validate-only
  php artisan shipments:import --source-id=3 --dry-run
  ```

  In Docker, prefix with
  `docker compose --profile standalone -f docker-compose.yml -f docker-compose.onprem.yml exec app`.

`--validate-only` checks the configuration and opens the connection without reading
anything. It is the fastest way to tell a credential problem from a query problem.

### Re-importing

**Existing Shipments** (`on_existing`) decides what happens when an import sees a
shipment it has already written:

| Option | Behavior |
| --- | --- |
| Update only if source data changed *(default)* | Rewrites only when the row's checksum — shipment fields **and** its items — differs |
| Always update from source | Rewrites every run |
| Skip (keep local changes) | Never rewrites |

Shipped and voided shipments are never updated, whichever is set. And in every case, a
skipped shipment is still passed to `mark_exported_query`.

### Troubleshooting

**Where to look**

| | |
| --- | --- |
| `storage/logs/shipment-import-YYYY-MM-DD.log` | The import log — run stats, per-row errors, connection failures. Daily, 14 days retained. |
| `storage/logs/import-YYYY-MM-DD.log` | Scheduler command output for scheduled runs |
| **Admin → Audit Log** | One `Data Source Query Executed` entry per query execution |
| Notifications | Admins are notified when a run completes *with errors* |

The audit entries record the operation, success/failure, a SHA-256 of the query, the
first 300 characters of it, the **names** of the bound parameters, and the affected-row
count for writes. Deliberately never the parameter values or any returned row — those are
customer order data.

**Common failures**

| Symptom | Cause |
| --- | --- |
| *"Cannot connect to import database. Check connection settings."* | Generic by design — the real driver error is in the import log. On SQL Server, suspect TLS before credentials. |
| *"must be a single SELECT statement…"* | `RawSqlGuard`. A `WITH` clause trips this too. |
| *"must be a single SQL statement (remove the extra `;`)"* | Two statements in one field |
| `SQLSTATE[HY093]: Invalid parameter number` | The query names a parameter that is not supplied — `:cost` in an export query, or a `:name` that is not `:shipment_reference` in an items query |
| *"Query affected N rows, exceeding the configured limit…"* | `max_affected_rows`. The `WHERE` is not narrowing to one row — check it keys on the order number, not a customer or batch reference. |
| Same orders imported every run | `mark_exported_query` is off, failing, or does not set the flag the shipments query filters on |
| Import reports rows but writes no shipments | Rows failing validation. Check the log for *"Validation errors for shipment …"* — usually a missing `address1` or `city` alias. |
| Shipments import, items do not | The items query returned no `sku`, or `:shipment_reference` did not match the items table's key |
| Everything lands under the wrong client | `client_column` value matched no `Client` **name**, so rows fell back to the source's default |
| Tunnel will not open | Host key missing, or its hostname does not match the SSH Host field exactly |

`Preview Queries` answers most of these faster than the log does, because it shows the
raw and mapped columns side by side.

---

## A worked example

A generic order/order-line schema, the shape most mid-market ERPs land somewhere near.
This is the schema the `GRANT` statements above were verified against.

```sql
CREATE TABLE orders (
  order_no        VARCHAR(20) PRIMARY KEY,
  ship_first_name VARCHAR(60),  ship_last_name VARCHAR(60),
  ship_company    VARCHAR(80),
  ship_addr1      VARCHAR(120), ship_addr2     VARCHAR(120),
  ship_city       VARCHAR(60),  ship_state     VARCHAR(40),
  ship_zip        VARCHAR(20),  ship_country   CHAR(2),
  ship_phone      VARCHAR(40),  ship_email     VARCHAR(120),
  order_total     DECIMAL(10,2),
  ship_via        VARCHAR(40),   -- 'GROUND', '2DAY', …
  order_source    VARCHAR(40),   -- 'web', 'edi', …
  brand_name      VARCHAR(80),   -- multi-client installs only
  status_code     CHAR(1),       -- 'R' released, 'H' hold
  wms_exported_at DATETIME NULL, -- written by mark_exported_query
  tracking_no     VARCHAR(40)  NULL,  -- written by export_query
  carrier_name    VARCHAR(40)  NULL,
  service_name    VARCHAR(60)  NULL,
  actual_weight   DECIMAL(8,2) NULL,
  shipped_at      DATETIME     NULL
);

CREATE TABLE order_lines (
  order_no    VARCHAR(20), line_no INT,
  item_code   VARCHAR(40), item_desc VARCHAR(160), upc VARCHAR(20),
  qty_ordered INT, unit_weight DECIMAL(8,3), unit_price DECIMAL(10,2),
  PRIMARY KEY (order_no, line_no)
);
```

### Connection

| Field | Value |
| --- | --- |
| Driver | MySQL / MariaDB |
| Host / Port | `erp-db.internal` / `3306` |
| Database | `erp` |
| Username / Password | `polybag_import` / *(the account created above)* |
| Import Schedule | Every 15 minutes |
| Existing Shipments | Update only if source data changed |

Use **Test Connection** before going further.

### Shipments query

Every column is aliased to the name the default mapping expects, so no `field_mapping`
override is needed. The `WHERE` clause is what makes this incremental.

```sql
SELECT o.order_no        AS id,
       o.ship_first_name AS first_name,
       o.ship_last_name  AS last_name,
       o.ship_company    AS company,
       o.ship_addr1      AS address1,
       o.ship_addr2      AS address2,
       o.ship_city       AS city,
       o.ship_state      AS state,
       o.ship_zip        AS zip,
       o.ship_country    AS country,
       o.ship_phone      AS phone,
       o.ship_email      AS email,
       o.order_total     AS value,
       o.ship_via        AS shipping_method,
       o.order_source    AS channel,
       o.brand_name      AS client_name
  FROM orders o
 WHERE o.status_code = 'R'
   AND o.wms_exported_at IS NULL
```

`client_name` is not in the mapping and is not meant to be — it is there for
**Client Column**, which reads the raw row. Drop it on a single-client install.

### Items query

```sql
SELECT l.item_code   AS sku,
       l.item_desc   AS name,
       l.item_desc   AS description,
       l.upc         AS barcode,
       l.qty_ordered AS quantity,
       l.unit_weight AS weight,
       l.unit_price  AS value
  FROM order_lines l
 WHERE l.order_no = :shipment_reference
 ORDER BY l.line_no
```

`:shipment_reference` receives the `id` column from the row above — `SO-10041` — bound,
not interpolated.

### Mark exported

Toggle **Mark Exported After Import** on:

```sql
UPDATE orders
   SET wms_exported_at = NOW()
 WHERE order_no = :shipment_reference
   AND wms_exported_at IS NULL
```

The second predicate is not required, but it makes the statement idempotent and keeps the
affected-row count at 0 or 1 — never tripping `max_affected_rows`, and never overwriting
the timestamp of the first import.

### Export

Toggle **Write Tracking Back to Source Database** on:

```sql
UPDATE orders
   SET tracking_no   = :tracking_number,
       carrier_name  = :carrier,
       service_name  = :service,
       actual_weight = :weight,
       shipped_at    = NOW()
 WHERE order_no = :shipment_reference
```

Note there is no `:cost` — see the [parameter list](#export_query). Add `cost` to the
schema and to `settings.export_field_mapping` if you need it.

### Settings not on the form

Leave **Max Affected Rows Per Write** at `1`: every write above touches one order row.

### Result

With the seed rows in that schema, the shipments query returns the two released,
un-exported orders and skips the one on hold. `SO-10041` imports with two items;
`SO-10042` with one. Both orders come back with `wms_exported_at` set, so the next
run returns nothing until the ERP releases another order — and shipping `SO-10041` in
PolyBag writes its tracking number, carrier, service and weight back to the `orders` row.

Confirm all of that with **Preview Queries** before turning the schedule on. Then run
`php artisan shipments:import --source-id=N` once by hand and read
`storage/logs/shipment-import-*.log`.

### ERP-specific notes

Appendices for specific products (SAP Business One, Epicor, Sage, Dynamics) are not here,
because writing them accurately needs access to a real instance of each. They will be
added as real deployments happen. The schema above is generic on purpose — the shape
transfers; the table and column names will not.
