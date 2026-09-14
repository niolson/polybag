# `package_labels` table and the one-active-label invariant

Status: done

Repo: `polybag`

## Problem

ADR-0004 decisions 1–5, and the `cascadeOnDelete()` that is decision 7's whole
mechanism. This slice creates the record, wires the four write paths, backfills shipped
packages, and makes the invariant true and tested. **It changes no
reader.** The `packages` columns stay exactly as they are and keep being written exactly
as they are — with one exception this slice makes on purpose: two Filament forms stop
being writers.

Not here, by ADR-0004 decisions 6 and 7: the label documents (they stay on `packages`,
so the PII purge is untouched) and any change to the delete or archive paths (labels
cascade with their package). Not here, by later issues: print count and first-print
time (`03`), what the carrier says back on void (`04`), anything a user sees (`05`).

## What to build

### Migration

`package_labels`:

| Column | Notes |
|---|---|
| `id`, `timestamps` | |
| `package_id` | FK with `cascadeOnDelete()`, indexed; not unique — one package, many labels over time |
| `tracking_number` | indexed, nullable (a blind purchase can return one late) |
| `postage_source` | same enum as `packages.postage_source` |
| `carrier_account_id`, `postage_data_source_id` | nullable FKs, `nullOnDelete()` — **exactly as on `packages`** — and the same consistency rule as ADR-0002. `EditCarrierAccount` and `EditDataSource` carry unguarded `DeleteAction`s that work today only because the package pointer nulls; an engine-default RESTRICT here would make deleting any account or source that ever bought a label throw, and a pointer nulled on one side only would read as projection drift |
| `carrier` | free text, carrier of record |
| `normalized_carrier_id` | nullable FK, `restrictOnDelete()`, as on `packages` |
| `service`, `requested_service`, `service_evidence`, `service_inference_method`, `service_ruleset_version` | as on `packages` |
| `cost` | nullable decimal |
| `label_format`, `label_dpi`, `label_orientation` | as on `packages` |
| `ship_date` | as on `packages` — the date this label was bought for |
| `purchased_at`, `purchased_by_user_id` | from `shipped_at` / `shipped_by_user_id`; the user FK is `->nullable()->constrained('users')->nullOnDelete()`, as `packages.shipped_by_user_id` and `audit_logs.user_id` are — a void nulls `shipped_by_user_id`, after which the `EditUser` delete guard lets the user go, and a label row that still named them would make the delete fail at the database |
| `source_label_reference` | nullable string; the postage source's own identifier for the label (Shopify label ID, Amazon shipment ID). Written at purchase, here: both sources strip it from `packages.metadata` on void so a dead purchase cannot be resumed, so a label voided before this column exists could never be backfilled |
| `voided_at`, `voided_by_user_id`, `void_reason` | nullable, the user FK `nullOnDelete()` as above; all three written here — a label voided between this release and a later one would otherwise lose its user and reason for good |
| `last_printed_at` | nullable; the projection of `packages.label_printed_at`, backfilled from it |
| `active_package_id` | generated, `->nullable()`: `CASE WHEN voided_at IS NULL THEN package_id END`; **unique index** |

Projected columns mirror the nullability and defaults of their `packages` counterparts:
`label_format` and `service_evidence` keep their non-null defaults, while facts such as
carrier, service and tracking number remain nullable. The factory hook below builds a
label from any fixture whose status is `Shipped`, and
`tests/Unit/Models/ShipmentTest` and others create those with a bare status and nothing
else; those rows receive the same database defaults as a Package. `ShopifyAdapter` also
derives the carrier of record through a nullable `carrierNameFor()`, so a Shopify
purchase can legitimately have no `carrier` — a NOT NULL there would roll
`markShipped()` back after Shopify has sold the label.

No `label_data` or `customs_form_data` (ADR-0004 decision 6). No `shipment_id` — a label
reaches its Shipment through its Package.

Use `->virtualAs()` for the generated column; both MySQL 8.4 (InnoDB secondary index on a
virtual column) and SQLite support an index on it. The unique index is the production
guard and the suite runs only on SQLite, so this slice adds a CI job — see below.

### Backfill, in the same migration

One row per Package with `status = shipped`, from its columns. Nothing for unshipped
packages. Nothing for historical voids (ADR-0004 decision 5). `source_label_reference`
comes from `packages.metadata` — `shopify_shipping_label_id` or `amazon_shipment_id`,
the same keys the adapters strip on void. `last_printed_at` comes from
`label_printed_at`.

The migration carries its own frozen column mapping and does not import
`PackageLabel::PROJECTED_COLUMNS`: a column added to that constant by a later slice would
make this migration select a column that does not yet exist on a fresh install.

### Model

`PackageLabel` with factory. `PackageLabel::PROJECTED_COLUMNS` — the single list of
scalar columns that exist on both tables and must agree: `tracking_number`,
`postage_source`, `carrier_account_id`, `postage_data_source_id`, `carrier`,
`normalized_carrier_id`, `service`, `requested_service`, `service_evidence`,
`service_inference_method`, `service_ruleset_version`, `cost`, `label_format`,
`label_dpi`, `label_orientation`, `ship_date`, plus the three renamed pairs
`shipped_at`↔`purchased_at`, `shipped_by_user_id`↔`purchased_by_user_id` and
`label_printed_at`↔`last_printed_at`. Every writer of both rows builds both writes from
one array keyed by this list; no writer hand-lists the columns twice.

`markShipped()` writes `source_label_reference` from a typed field on `ShipResponse`.
Today the identifier only reaches `metadata` (`ShopifyAdapter` and
`AmazonBuyShippingAdapter` put it there), so the DTO grows the field and those two
populate it — the label row does not read `metadata` back.

`Package::labels()` HasMany; `Package::activeLabel()` HasOne with `whereNull('voided_at')`.

`Package::assertLabelStateIsConsistent()`, named and throwing like
`assertProvenanceIsConsistent()` (which is a precondition run before the transaction;
this one is a post-condition inside it):
`Shipped` ⇔ exactly one unvoided label; a package in any other status — including the
legacy `PackageStatus::Void` value nothing writes — has none. It does **not** compare
the projected columns: that is the integrity command's job, and comparing them here
would fail every test that hand-edits a shipped fixture's `cost` or `service` before
calling a writer. It runs as a post-condition inside each of the four writing
transactions, before commit, so a violation rolls the write back.

### Write paths

Four. Three on `Package` and one on the label workflow. Two already have a transaction
and two gain one. Where a writer sets projected columns on both rows — `markShipped()`,
`recordInferredService()` — it builds both writes from one `PROJECTED_COLUMNS`-keyed
array. Every writer ends with the assertion. **Lock order is the `packages` row first, then
`package_labels`**, in all four, so a print acknowledgement racing a void cannot
deadlock.

- `markShipped()`: after the `UPDATE` succeeds, insert the label row from the same
  `$response`. The `updated === 0` guard runs first, so a lost race inserts nothing.
- `clearShipping()`: takes a `VoidReason` (`operator` | `voided_upstream`) and a nullable
  user ID — `operator` from `EloquentPackageLabelWorkflow::voidLabel()` with
  `auth()->id()` resolved inside the workflow (the contract's `voidLabel(Package)` takes
  no user and its callers are Filament pages this slice does not touch; the nearer
  precedent, `markLabelPrinted(Package, ?User)`, takes the user from the caller, but
  changing `voidLabel()`'s signature would mean touching those pages),
  `voided_upstream` from `ShopifyFulfillmentSynchronizer` with none. After `01`'s
  `lockForUpdate()` snapshot and the existing conditional `UPDATE`:
  `UPDATE package_labels SET voided_at = now(), voided_by_user_id = ?, void_reason = ?
  WHERE package_id = ? AND voided_at IS NULL`, and assert it touched one row. It also
  starts nulling `ship_date`, which it never did. Every aggregating reader of
  `ship_date` (stats, End of Day, the user shipments report) filters on
  `status = shipped`; the two that do not — `ViewPackage`'s entry and the archive CSV —
  will show a voided package with no ship date instead of a stale one, which is the
  correct reading. The eight direct
  `clearShipping()` calls in tests gain the argument.
- `recordInferredService()`: has no transaction today — it is one standalone conditional
  `UPDATE`. It gains one, around the package update and the same conditional update on
  the active label, each keeping its race-safe `WHERE` (confirmed wins, same ruleset is a
  no-op, no downgrade). It also gains `->where('status', Shipped)` on the package update:
  `InferPackageServices` does not filter by status, and an unshipped package has no
  label to keep in step.
- `EloquentPackageLabelWorkflow::markLabelPrinted()`: `label_printed_at` is in
  `PROJECTED_COLUMNS`, so the print writer keeps the label in step from this slice. One
  `DB::transaction` that locks the package row, stamps `packages.label_printed_at` and
  the active label's `last_printed_at` with the same timestamp, asserts the label
  `UPDATE` touched exactly one row (the structural assertion alone would pass an
  unshipped package with no label, and the only caller today 422s before reaching
  here, so a zero-row stamp must be a thrown error, not a silent no-op), and asserts.
  Its return value (was this a reprint) is unchanged.

### Integrity command

`app:verify-label-integrity`: counts and names every package where `Shipped` ⇔ one
unvoided label fails, or where a shipped package's projection disagrees with its active
label on any column in `PROJECTED_COLUMNS`. Exit non-zero when any is found. The report
runs nightly on the scheduler. `--repair` handles one case and one only — a shipped
package with no label row gets one inserted from its projection, the same way the
backfill does — and reports everything else for a person. `--repair` is only ever run
by a person, never by the scheduler, the entrypoint or the writers.

The case `--repair` exists for is a deploy: a previous image's worker can finish a
purchase after this migration's backfill has run. The permanent new-worker half of the
ordering rule is already in place, committed with this plan: `queue`, `import-queue`
and `scheduler` depend on `app` being healthy, so their new containers cannot start
until the app entrypoint has migrated.
That does not stop a previous worker already running, so `docs/self-hosting.md` also
gains a short release section — stop the workers, bring `app` up alone and wait for
healthy, then start the rest — and the PR flags that the hosted deploy script (private
repo) needs the same step. The integrity command reports anything that slips through;
`--repair` repairs that one case manually.

### The two forms that write the projection

`PackageResource`'s form and the Shipment's `PackagesRelationManager` form both carry
`TextInput('tracking_number')`, `TextInput('cost')` and `Select('status')`. A manager
can flip a shipped package to unshipped leaving its label active, set `shipped` on a
package with no label, or hand-edit a shipped package's tracking number. Remove the three
fields from both forms; the columns are shown, not edited. The relation manager's
`DeleteBulkAction` also lacks the shipped guard its row `DeleteAction` has (and
`PackagePolicy::delete()` is role-only), so today it deletes a shipped package with a
live label at the carrier and after this slice would cascade an active label row with
it; give it the same `before` check. This is the one deliberate exception to the
no-`app/Filament/` rule below — same two files.

### Fixtures

- `PackageFactory` creates the matching `PackageLabel` in a global `afterCreating` hook
  keyed on the created row's **status**, not on the `->shipped()` state: `tests/Pest.php`
  and at least four test files create a shipped package with a bare `'status' =>
  Shipped` override, and a fixture is a fixture however it was built. The label is built
  from the row's own projected columns. This is the largest single piece of this slice:
  forty-plus test files use the state and the invariant must hold in all of them.
- `GenerateTestData`, `DemoReset`, `FedexTestCaseRunner` and `FedexRunEtdTestCase` all
  insert shipped packages directly, bypassing `markShipped()`. Each produces the label
  row from the same `PROJECTED_COLUMNS` array it uses for the package.
- `DemoReset` truncates `TRANSACTIONAL_TABLES` with foreign-key checks **off**, which
  neither cascades nor stops `AUTO_INCREMENT` resetting. `package_labels` goes in that
  list, before `packages`; otherwise orphaned unvoided rows survive, refabricated
  packages reuse their IDs, and the next reset dies on the unique index.
- `GenerateTestData::cleanup()` deletes its own fixtures by ID with `DB::table()`;
  `package_labels` goes in its delete list before `packages`, so it does not depend on
  the cascade being on.

### CI

A `mysql` job in `.github/workflows/ci.yml` with a `mysql:8.4` service container that
runs `php artisan migrate` from empty and then the tests in `->group('mysql')` — the
constraint-violation test, the backfill test and the cascade-delete test. The Paratest
SQLite job is unchanged. Without this the generated-column unique index is never once
exercised on the engine that relies on it before it reaches a tenant.

### Language

Add **Label** to `CONTEXT.md` with the definition in ADR-0004's Terminology section, and
the relationship lines: a Package has at most one active Label; a voided Label stays.

## Acceptance criteria

- [ ] Migration creates the table with the generated column and unique index; a second
      unvoided row for the same package raises; voided rows do not collide
- [ ] Backfill creates one row per shipped package, none for unshipped, and is idempotent;
      each row carries `source_label_reference` derived from `metadata` and
      `last_printed_at` from `label_printed_at`
- [ ] `PROJECTED_COLUMNS` exists; `markShipped()` and `recordInferredService()` build
      their matching projected writes from it, while `clearShipping()` voids the label
      and `markLabelPrinted()` writes its renamed timestamp pair. A grep under `app/`
      finds no second hand-written projected-column list. The migration has its own
      frozen mapping and does not import the constant
- [ ] `source_label_reference` is written at purchase for Shopify and Amazon labels and
      survives the void, while the resume keys in `packages.metadata` are still removed
      exactly as today; `ShopifyFulfillmentSynchronizerTest`'s "drops the label
      identifiers so a re-ship buys a new label" still passes
- [ ] `markShipped()` inserts the label row atomically with the projection; a lost
      optimistic-lock race inserts nothing
- [ ] `clearShipping()` requires a `VoidReason`; both callers pass one; it voids exactly
      one row atomically with clearing the projection, recording reason and user, nulls
      `ship_date` with the rest, and the row survives with its tracking number and cost.
      The Shopify-upstream void records `voided_upstream` with no user
- [ ] `recordInferredService()` runs in a transaction, upgrades the active label with the
      package, leaves both alone under the conditions it already refuses (confirmed
      service, same ruleset, downgrade), and refuses an unshipped package
- [ ] `markLabelPrinted()` runs in a transaction and stamps the package and the active
      label with one timestamp, package first
- [ ] Ship → void → ship again on one package leaves two rows, one voided, one active, and
      the projection equals the active one on every projected column
- [ ] `assertLabelStateIsConsistent()` runs as a post-condition in all four writers, and
      a test makes one of them violate it and sees the transaction roll back; an
      unshipped package with projected values set (the `withLabel()` state, which sets
      `label_orientation` among others) passes
- [ ] `app:verify-label-integrity` exists and is scheduled; it reports a shipped package
      with no label, an unshipped package with an active label, and a shipped package
      whose `cost` disagrees with its label; `--repair` inserts a label for the first
      case only and leaves the other two reported
- [ ] Neither Filament form offers `tracking_number`, `cost` or `status`; a Livewire test
      submits each and asserts the column is unchanged. The relation manager's bulk
      delete refuses a shipped package
- [ ] Deleting a carrier account and a data source that each bought a label succeeds and
      nulls the label's pointer along with the package's
- [ ] Deleting a package with a voided and an active label removes both rows (cascade,
      in the `mysql` group); deleting a user who bought and one who voided a label
      succeeds and nulls the two columns
- [ ] `->shipped()`, a bare status override, `GenerateTestData`, `DemoReset` and both
      FedEx runners create label rows; `demo:reset` runs clean twice in a row
- [ ] The `mysql` CI job runs the migrations from empty and the `mysql`-group tests
      against MySQL 8.4, and is green
- [ ] `queue`, `import-queue` and `scheduler` still depend on the app healthcheck in
      Compose (already true; do not regress it);
      `docs/self-hosting.md` gains a release section for the old-worker race: stop the
      workers, bring `app` up alone and wait for healthy, then start the rest; the PR
      flags the hosted deploy script
- [ ] **No file under `app/Filament/`, `app/Http/`, or `app/Services/ShipmentImport/`
      changes, except the two form schemas named above.** If another has to, the slice
      is wrong
- [ ] `CONTEXT.md` gains the **Label** term
- [ ] The full suite passes

## Blocked by

ADR-0004 acceptance, and `01` — the `lockForUpdate()` snapshot this slice's void path
builds on lands there.

## Comments

- **2026-09-14** — Cut from 420 lines and thirty-plus criteria after review. Dropped
  from this slice, with the ADR: copying the label documents onto the row (and so the
  PII purge changes, `07`–`09`), the archive CSV and the four delete guards (labels now
  cascade, ADR-0004 decision 7), lazy self-repair in the writers and the entrypoint (a
  deploy-ordering rule instead), the global `afterEach` integrity hook with its
  `integrity-exempt` group and `forceProjection()` helper (the writers assert the
  structural rule; the command compares the projection), and `package_label_id` in the
  `LabelPrinted` audit row (`03`'s concern, if `03` happens).
- **2026-09-14** — Second review pass, against the code: pinned the three pointer FKs'
  delete rules to match `packages` (RESTRICT by default would break carrier-account and
  data-source deletion), made every projected column nullable (bare-status fixtures and
  Shopify's nullable carrier of record), added the relation manager's unguarded bulk
  delete, the new-worker half of the deploy race, and a one-row assertion in
  `markLabelPrinted()`.
- **2026-09-14** — Done, on `feat/package-labels-table`. Two departures from the text
  above, both deliberate. (1) The backfill is its own migration
  (`2026_09_14_000100_backfill_package_labels`) rather than part of the create-table
  one: the two run back to back in the same `migrate`, it matches how `postage_source`
  and `service_evidence` landed, and it is what lets the backfill be tested on MySQL —
  a DDL statement inside a MySQL test transaction commits implicitly and would leak
  rows into the rest of the `mysql` group. (2) `recordInferredService()` asserts that
  the label's conditional update touched exactly the row the package's did, rather than
  only the structural rule: the two are equal by construction, so a difference is drift
  and the write rolls back rather than widening it. Verified: the migration and the
  four `mysql`-group tests against MySQL 8.4 on a scratch database, and the full suite
  (2390) on SQLite. The hosted deploy script (private repo) still needs the
  stop-workers-then-migrate step from `docs/self-hosting.md`'s new Upgrading section.
