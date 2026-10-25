# Repository Guidelines

Guidance for coding agents working in this repository. `CLAUDE.md` imports this file;
keep repository guidance here rather than maintaining a second copy.

Also worth reading: `CONTEXT.md` for domain language, `docs/adr/` for architecture
decisions and why they were made, `CONTRIBUTING.md` for the development loop and the
contributor agreement, and `SECURITY.md` for reporting a vulnerability.

## Project Overview

PolyBag — barcode-driven shipping workstation for picking, packing, buying postage,
printing labels, and tracking fulfillment work. Built with Laravel 13 + Filament.

**Tech Stack:** PHP 8.4, Laravel 13.x, Filament 5.x, Livewire 4.x, Vite 8.x, Tailwind CSS 4.x, Pest 4.x

**Runtime:** MySQL 8.4, Redis, and Gotenberg for PDF rendering. Node.js 22.12+ for assets.
Exact dependency versions live in `composer.lock` and `package-lock.json`.

**Local Hardware Integration:**
- **Printing:** QZ Tray (local WebSocket print agent) — separate image-label,
  raw-ZPL-label, and document printer settings
- **Scale:** Dual-backend (WebHID for Chrome/Edge, QZ Tray fallback) — see `<x-scale-script>`

## Repository Layout

- `app/` — application code: `Contracts/`, `DataTransferObjects/`, `Models/`,
  `Services/`, `Filament/`, `Http/`, `Console/Commands/`, `Jobs/`, `Enums/`, `Policies/`
- `resources/views`, `resources/js`, `resources/css` — Blade templates and frontend
  entrypoints. Hardware orchestration lives in Blade components; npm-backed QZ and barcode
  libraries are bundled from `resources/js/qz.js` and `resources/js/barcodes.js`
- `resources/data/` — customs support tables, service-inference rules, and FedEx test
  cases. The carrier/service catalog itself is seeded by `ReferenceDataSeeder`
  (`app:sync-reference-data`)
- `database/` — migrations, factories, seeders. The schema was consolidated into table
  creation migrations; do not assume removed historical backfills still run
- `tests/Feature`, `tests/Unit`, `tests/External`, `tests/Browser` — Pest suites
- `docker/`, `infra/` — container entrypoint and MySQL encryption-at-rest config
- `scripts/` — the on-prem installer and QZ workstation provisioning
- `docs/` — self-hosting guide, QZ provisioning, ADRs, agent guidance, and the issue tracker
- `.github/workflows/`, `.githooks/` — CI, dependency/image security checks, and pre-push checks

## Deployment

PolyBag ships as a Docker stack. The supported install is **standalone / on-prem**: a single tenant with its own MySQL and Redis containers, brought up by `scripts/install-onprem.sh`.

```bash
docker compose --profile standalone -f docker-compose.yml -f docker-compose.onprem.yml up -d
```

`DB_HOST=mysql` and `REDIS_HOST=redis` point at the containers the `standalone` profile starts. Pointing them at an external host instead (managed MySQL, managed Redis) works and skips the profile, but that path is not what the installer sets up.

### Services

| Service | Does |
|---|---|
| `app` | PHP 8.4-FPM. Entrypoint: wait for DB → migrate → sync reference data → attempt table encryption → optimize → php-fpm |
| `nginx` | Serves static assets, proxies PHP to `app:9000` |
| `queue` | `queue:work --queue=default,low` with its own config cache |
| `import-queue` | `queue:work imports` — shipment imports run on their own queue |
| `scheduler` | `schedule:work` |
| `mysql`, `redis` | Started only by `--profile standalone` |
| `mysql-keyring-init` | One-shot keyring volume initialization before standalone MySQL starts |
| `gotenberg` | PDF rendering for pack slips and pick batches. Defined in `docker-compose.onprem.yml` |

Key files: `Dockerfile` (4-stage build), `docker/entrypoint.sh`, `docker-compose.onprem.yml`.
The installer creates the external `proxy` and `shared` Docker networks, prepares secrets
and QZ/SSH keys, and verifies MySQL encryption. The on-prem override exposes HTTP on
`APP_PORT` (default 80); HTTPS needs a separately configured TLS reverse proxy.

### Docker and caching notes

- Config and routes are cached at **runtime** by the entrypoint (`php artisan optimize`), not at build time. This keeps the Livewire endpoint hash — derived from `APP_KEY` — consistent. Only views are pre-cached at build time, since they don't depend on env values.
- The app healthcheck probes php-fpm rather than running `php artisan --version`, because the entrypoint only `exec`s php-fpm after migrations finish. Anything that waits for `(healthy)` and then recreates the container must not do so while `migrate` is still running.
- Never add `env_file: .env` to the app or queue services — it injects OS-level environment variables that take precedence over the mounted `.env` file and are then invisible to `config:clear`. The `env_file: /opt/shared/shared-secrets.env` entry with `required: false` is a hosted-mode injection point and a no-op everywhere else; the comment on the `app` service explains it.
- QZ cert paths are configurable via `QZ_PRIVATE_KEY_PATH` and `QZ_CERTIFICATE_PATH`.
- Passwords generated by scripts use `openssl rand -hex 16` — no special characters that break `.env` parsing.
- Always rebuild with `--build` after changing files copied into the image. `scp` or an edit on the host does not update a running container.
- After changing the mounted `.env`, clear the app's config cache and restart `app`,
  `queue`, `import-queue`, and `scheduler`; each caches configuration at startup. Changes
  to Compose-injected environment values or `env_file` require container recreation.

`infra/shared/` holds three MySQL encryption-at-rest config files that `docker-compose.yml` mounts together — see `infra/README.md` before touching them; two of the ways they can break leave a healthy-looking server with encryption off.

Server-operations tooling for our own hosted deployment lives in a separate private repository and is deliberately not here. See `docs/self-hosting.md` for running PolyBag without any service we operate.

## Domain Model

- **Shipment** — Order to be shipped (address, items, validation status); scoped to a `Client`
- **Draft Shipment** — Manual Ship's rate-preview working copy (`status=draft`), excluded
  from operational lists. A purchase makes it shipped; unused copies with no Label history
  are deleted after 24 hours without changes. Distinct from a Package Draft
- **ShipmentItem** — Line items in a shipment
- **Package** — Physical parcel prepared from a Shipment and measured before label purchase;
  scoped to a `Location`. Its shipping columns project the active Label for efficient reads
- **Package Draft** — An unshipped Package prepared but not yet through label purchase;
  resumable and editable, not temporary or orphaned
- **PackageItem** — Items packed in a package (with transparency codes)
- **PackageLabel** / **Label** — One purchased outbound-postage instance. A Package has at
  most one active Label; voided Labels remain as history and the Package becomes unshipped
- **ShippingMethod** — A service class, such as Ground, which one or more concrete
  `CarrierService` records can satisfy
- **ShippingOffer** / **Offer** — Ephemeral, package-bound purchase authority for a quoted
  rate. Its opaque public ID may cross browser state; purchase tokens and source identity do not
- **Carrier** / **CarrierService** — Authored carrier and service catalog used for policy and
  normalization; external discovery never silently creates either. A seeded carrier is
  `is_system`: its `name` is the key the registry, seeders and alias matching use, so it
  can never be renamed or deleted. The UI shows `Carrier::label()` (`display_name`, an
  operator-owned relabel, else `name`); use the `Carrier::USPS`/`UPS`/`FEDEX` constants
- **ObservedService** — Amazon-reported service identity; a `SourceServiceMapping` names the
  `CarrierService` it is
- **ShippingMethodPostageSource** — One postage-source kind a `ShippingMethod` allows (direct,
  Shopify, Amazon Buy Shipping), and whether it may sell unlisted services. With the method's
  services, it is the **allowance**: what automation may buy
- **CarrierAccount** — Per-carrier API credentials; supports multiple accounts per carrier with OAuth
- **CarrierAccountScope** — Routes a `CarrierAccount`, or an Amazon Shipping connection
  account, to a `Location` and/or `Client` combination with priority-based resolution
- **BoxSize** — Scannable dimensions plus physical form and optional carrier-supplied
  packaging identity; compatible packaging is enforced while rating and buying. An
  inactive one stays in the catalog, off the barcode sheet, Pack lookup and pickers;
  carrier packaging is seeded inactive by the reference-data sync
- **Channel** — Sales channel source (Shopify, Amazon, database import)
- **Product** — Product catalog with barcodes and weights; scoped to a `Client`
- **DataSource** — A connected account (Database/Shopify/Amazon), shown in the UI as a
  **Connection**, with per-client assignment, encrypted secrets, and per-source scheduling.
  `active` means it may be used at all; `import_enabled` separately decides whether it
  imports orders, so a connection can exist only to sell postage or receive tracking
- **Location** — Warehouse / fulfillment center with address, timezone, and carrier associations
- **Client** — 3PL brand/retailer; scopes shipments, products, data sources, and shipping rules; carries return address, pack slip branding fields, and the reference printed on carrier labels
- **Duties terms** — Who pays import charges: carrier account (DDP) or recipient (DDU).
  The Shipment overrides the Client's destination policy; `ClientTaxRegistration` holds
  the client's seller registrations. Purchased Labels snapshot what was declared
- **PolyBag code** — A printed barcode with `%`, type token, and body, such as `%S216`,
  `%B17`, or `%CSHIP`. Parse through `Services/Scanning/ScanCode`; external identifiers
  (order references, SKUs, UPCs, tracking numbers) have separate lookup rules (ADR-0007)

Preserve these domain terms in code and prose — see `CONTEXT.md`. In particular:

- **Carrier of record** is who physically moves the parcel; **postage source** is where its
  Label was bought (`CarrierAccount` or sales-channel `DataSource`). They are independent.
- A Shopify purchase is a **blind purchase**, not a rate or Offer: price and service are
  unknown at purchase. Automation may buy only under the explicit-choice policy in
  `UnattendedRateSelector`, with the connection's postage setting permitting automation;
  it never ranks a blind purchase against quoted rates.
- **Observed service**, `CarrierService` normalization, and the shipping method's
  **allowance** are three separate concepts.
- Every purchase requires a ShippingMethod. A packer may select an Offer outside its
  allowance; automation must stay within it and the Connection's postage setting.
- **Shipment**, **Draft Shipment**, **Package**, **Package Draft**, and **Label** are distinct things.
- `ship_by` is the date an order must leave the warehouse; `deliver_by` is its delivery
  deadline. Both are calendar dates. Due-to-ship filters use the Location's timezone.

## Key Workflows

1. **Picking** (`/generate-pick-batch`) — Optional; generate pick batches and print picking summaries before packing. Gated by `picking_enabled`; `require_picking_before_shipping` enforces it before packing/batch shipping
2. **Packing** (`/pack/{shipment_id}`) — Scan box code, scan items, read weight from scale;
   save progress as a resumable Package Draft. Opening the page alone creates no draft.
   Purchase requires positive weight/dimensions and complete items when validation is on
3. **Shipping** (`/ship/{package_id}`) — Resolve eligible direct and channel postage
   sources, issue server-side Offers for rates, optionally present an attended Shopify blind
   purchase, buy a Label, and print it. Offers are bound to the Package version, quote inputs,
   source instance, environment, and billing identity
4. **Manual Ship** (`/manual-ship`) — Prepare a Draft Shipment, view rates, then buy a Label
5. **Batch Ship** — Buy Labels for multiple Packages within each shipping method's allowance,
   narrowed by the connection's postage setting; blind Shopify purchases require an explicit
   rule or sole eligible ShippingMethod selection
6. **Label Reprint / Void** — Reprint the active Label or void it through the postage source;
   a void retains Label history and returns the Package to unshipped
7. **End of Day** (`/end-of-day`) — Create USPS SCAN forms / manifests only for eligible
   direct-account Labels; location-scoped when multi-location is enabled
8. **Pack slips** (`/print-pack-slips`) — Print from the queue, a Shipment, or a pick batch;
   Shipment-level receipts track the rendered item version and print acknowledgment.
   Changed items make a printed slip out of date. Turning off `pack_slips_enabled` hides these flows;
   when picking is required, slips print from pick batches

## Hardware Integration

### QZ Tray (Printing)
- Must be installed on each workstation: https://qz.io/download/
- Connects via WebSocket to `wss://localhost:8181`
- Image-label printer, raw-label printer, document printer, preferred purchase format, and
  ZPL DPI are stored in browser `localStorage`
- Configured via Workstation Settings page
- PDF/PNG/GIF labels use the image printer driver; ZPL bytes use the raw printer at 203 or
  300 DPI. The two settings may name the same physical printer
- Pack slips use 4×6 stock on the image label printer. Pick lists, manifests, and customs
  forms use the document printer (`reportPrinter` / `printReport()` in browser code)
- Print acknowledgments mean the bridge accepted the job, not proof of physical printing.
  Label acknowledgments identify the active Label; pack-slip receipts bind Shipment item versions
- Signing certificate generated via `app:generate-qz-cert`
- Integration code in `<x-qz-tray>` and `<x-qz-tray-script>` Blade components
- Workstation trust provisioning: `scripts/qz-provision/` and `docs/qz-tray-provisioning.md`

### Scale (WebHID / QZ Tray)
- Dual-backend: WebHID if `navigator.hid` exists (Chrome/Edge over HTTPS), else QZ Tray
- Backend selection and scale IDs stored in browser `localStorage`
- Integration code in `<x-scale-script>` Blade component
- WebHID requires secure context (HTTPS or localhost)

## API and Postage Integrations

- **USPS** — Direct-account address validation, domestic/international offers and Label
  purchase, tracking, voids, reprints, and SCAN forms (via Saloon)
- **FedEx** — Direct-account offers, Label purchase, tracking, cancellation, and ETD/customs
  documents (via Saloon)
- **UPS** — Direct-account offers, Label purchase, tracking, voids/recovery, and customs
  documents (via Saloon)
- **Amazon Buy Shipping** — SP-API offers and Label purchase for Amazon-originating
  Shipments (`channelType: AMAZON`), with tracking and cancellation dispatched back through
  Amazon. Those offers may name Amazon Shipping, USPS, UPS, FedEx, or another carrier. Amazon
  dynamically discovers services; human selection may use any of them, while automation buys
  one only when the method's `amazon` row allows it: mapped to a listed service, or any
  service
- **Amazon Shipping** — A carrier sold directly to orders from other channels, on a
  connection's account. A method lists Amazon Shipping Ground; `AmazonShippingAdapter` quotes
  `channelType: EXTERNAL` on the Amazon connection a scope chooses, and its rates are direct
  rates that automation buys under the method's `direct` row. The Offer and Label record the connection as
  the postage source, so purchase, tracking and voids go through it. Verified against the
  sandbox only: no production Amazon Shipping account has run it
- **Shopify Shipping** — Blind Label purchase tied to the Shipment's originating
  Shopify `DataSource`; the purchase response confirms neither price nor service.
  `ShopifyLabelCostRecorder` recovers identifiable USD costs from order events;
  `ServiceInference/` derives service from label evidence with explicit confidence.
  Tracking comes through Shopify; Labels must be voided in the Shopify admin and are then
  reconciled by the fulfillment synchronizer

Carrier and source adapters live in `app/Services/Carriers/`. Direct accounts resolve through
`CarrierAccount::resolveForShipment()` using the Package's `location_id` and Shipment's
`client_id`; Shopify and Amazon marketplace postage bind to the Shipment's originating
`DataSource`. `CarrierRegistry` remains carrier-policy/direct-adapter lookup, while
`PostageSourceResolver` and `PostageSourceDispatcher` own source selection and post-purchase
dispatch. Amazon Shipping for other channels is a direct sale whose account is a connection:
the scope resolves a connected Amazon `DataSource`
(`PostageSourceResolver::offAmazonShippingSourceFor()`), not a `CarrierAccount`
(`UsesConnectionAccount`). Rules and automation treat its rates as direct; the postage
source is the connection. Do not confuse it with the origin-bound Buy Shipping postage of
an Amazon order.

Direct-label tracking and voids use the recorded `carrier_account_id`. They must not fall
back to whichever account current routing scopes choose when that recorded account is
missing. Keep service inference separate from a source-confirmed service and catalog mapping.

### Customs and address validation

- `Services/Customs/CustomsTermsResolver` resolves Shipment terms over Client policy and
  seller registrations. An EU customs crossing with no duties choice is unresolved;
  rates cannot silently substitute a different term.
- `Services/Shipping/DutiesTermsFilter` filters carrier support using
  `resources/data/customs/duties-support.json`; `CustomsReadiness` enforces purchase blocks
  across attended, batch, and auto-ship paths. Labels retain a `customs_terms` snapshot.
- Amazon and Shopify postage uses source-decided terms. Automation holds customs crossings
  into the EU, GB, NO, and AU until those declarations are verified; attended purchase is
  still available. Keep this hold in the shared unattended selector.
- `Services/ExchangeRates/` stores ECB reference rates for seller registration thresholds;
  the selected rate date is pinned on the Shipment.
- `AddressValidationService` uses `AddressValidationPlan` / `ShipmentValidationPlan` to
  route by destination and eligible accounts: FedEx where appropriate, USPS, and optional
  Google. An implemented UPS validator is not part of the default plan. Validator answers
  and attempts are recorded separately; unavailable validators are not proof of an
  undeliverable address.

## Data Import / Export

Shipment import sources are configured as `DataSource` records in the database (Integrations → Connections), not in `.env`. Each source can be assigned to a `Client` and has its own encrypted credentials and schedule. The scheduler, `shipments:import` and `RunDataSourceImportJob` select `DataSource::importing()` — active *and* `import_enabled`; export and origin-bound postage need only `active`.

Imports pass through `ShipmentRowPreparer`, `ShipmentBatchWriter`, and
`ShipmentItemImporter`; `DataSourceLocationResolver` maps source locations. A source
implementing `ReadsInPages` (Shopify) is read identities first, re-pointed once, then
written a page at a time, so its memory does not grow with the store; Amazon and
Database sources still return their whole read from `fetchShipments()`. Shopify
imports fulfillment orders incrementally; Amazon imports merchant-fulfilled orders.
Both populate `ship_by` from their source's dispatch deadline; database imports can map it.

A source whose feed holds only open orders implements `ClosesRecordsClosedAtSource`: after the writes, the import looks up the open Shipments that left the feed and closes those the source closed, through `SourceClosureApplier`. Shopify does (`order-reconciliation/06`), and so does Amazon, whose import asks for every order updated since it last read through (`import_updated_through`), closed ones included, so the same search imports and closes (`07`). A Database source with a status query asks it about each of its open Shipments after the run (`08`). A Shipment a manager reopened is never closed this way.

Supported drivers:
- **Database** — Custom SQL queries against MySQL, SQL Server, PostgreSQL, or SQLite. Full reference: `docs/data-sources/database.md` — query contracts, field mapping, `RawSqlGuard`, `max_affected_rows`, and least-privilege `GRANT` examples
- **Shopify** — Via Shopify Admin API with per-source OAuth
- **Amazon** — Via SP-API with per-source client credentials + refresh token

Import sources: `app/Services/ShipmentImport/Sources/` — `DatabaseSource`, `ShopifySource`, `AmazonSource`
Export: `app/Services/ShipmentImport/PackageExportService.php` — exports to the Shipment's originating connection when its export is enabled, plus, in multi-client mode, every active connection with global export on. There is no per-client export override; it was removed on purpose

Do not conflate a Shipment's import source with a Package's postage source. They can differ,
although marketplace postage is deliberately bound to the originating Shopify or Amazon
account because its order/fulfillment identity belongs to that account.

## Commands

```bash
# Local Development (configure .env/database before initial setup)
composer run setup       # Install deps, create .env, generate key, migrate, build assets
php artisan app:sync-reference-data # Sync catalogs after local setup
composer run dev         # server + queue + import-queue + vite + scheduler, concurrently
npm run dev              # Vite dev server only
npm run build            # Production build

# Testing
composer run test                    # Pest suite in parallel (clears config first)
composer run test:serial             # Same suite, one process — for debugging order-dependent failures
composer run test:ci                 # Parallel suite with coverage (requires a coverage driver)
php artisan test --compact --filter=TestName
composer run test:external           # External carrier/reference tests (tests/External)
composer run test:fedex-reference    # Narrow that to the FedEx reference cases
php artisan test tests/Browser/      # Pest browser tests — not in the default suite;
                                     # needs `npx playwright install chromium`

# Database
php artisan migrate      # Run migrations
php artisan db:seed      # Seed test data (idempotent for users)
php artisan tinker       # Interactive PHP shell

# Code Style
vendor/bin/pint          # Format PHP
composer run format      # Rector, then PHPStan, then Pint

# Docker (standalone / on-prem)
docker compose --profile standalone -f docker-compose.yml -f docker-compose.onprem.yml up -d
docker compose --profile standalone -f docker-compose.yml -f docker-compose.onprem.yml exec app php artisan <command>

# Utilities
php artisan app:generate-qz-cert              # Generate QZ Tray certificate (interactive)
php artisan app:generate-qz-cert example.com  # Non-interactive with domain
php artisan app:create-user                   # Create admin user
php artisan app:generate-ssh-key              # Keypair for import tunneling
php artisan demo:reset                        # Reset demo data (APP_ENV demo/local/testing only)
```

`composer run setup` is for initial setup and generates a new `APP_KEY`; do not use it
as an update command. Local PDF rendering needs `GOTENBERG_URL` pointing at a running
Gotenberg instance; the development script starts no PDF renderer.

## Architecture

### Service Layer
- `app/Services/CacheService.php` — Centralized caching for box sizes and carrier services (1-hour TTL)
- `app/Services/SettingsService.php` — Key-value settings stored in DB
- `app/Services/ClientContext.php` — Resolves the default `Client` for the current request
- `app/Services/ShippingRateService.php` — Resolves sources, quotes concurrently, filters
  packaging, logs quotes, and issues server-side Offers; keeps blind purchases separate
- `app/Services/PostageSources/` — Source resolution/dispatch, Offer persistence and
  redemption, and observed-service recording/mapping. The allowance is enforced in
  `RateSelector::selectForAutomation()`
- `app/Services/PackageDrafts/` — Package preparation workflow behind `PackageDraftWorkflow`
- `app/Services/PackageShipping/` — Offer redemption, purchase/recovery, and the atomic
  Package-to-shipped transition behind `PackageShippingWorkflow`; `UnattendedRateSelector`
  owns rules and automation selection before the shared purchase path
- `app/Services/PackageLabels/` — Active Label reprint/print tracking and void workflow behind
  `PackageLabelWorkflow`
- `app/Services/BatchLabelService.php` — Batch label generation
- `app/Services/ManifestService.php` — USPS SCAN form / end-of-day manifests; location-scoped
- `app/Services/PackSlips/` — Queue, rendering, views, and sealed print receipts
- `app/Services/Customs/` — Duties resolution, readiness, and Label snapshots
- `app/Services/ServiceInference/` — Evidence-based service inference for unreported services

Keep package preparation, label lifecycle, and purchase orchestration behind their contracts
in `app/Contracts/`. Keep structured boundary values in `app/DataTransferObjects/`; browser
state must never become authority for offer price, service, source, or purchase tokens.

Prefer small service classes over controller-heavy logic. When adding a model, add its
factory and seeder too — the test suite leans on factories heavily.

### UI (Filament)
- Auto-discovers resources from `app/Filament/Resources/`
- Auto-discovers pages from `app/Filament/Pages/`
- Auto-discovers widgets from `app/Filament/Widgets/`
- Panel config: `app/Providers/Filament/AppPanelProvider.php` — path `/`, auth required, teal theme
- Navigation groups: Ship, Fulfillment, Picking, Reports, Shipping Setup, Carriers,
  Integrations, Admin. Amazon service mapping is **Map Amazon Services**
- Use `SettingsService::amazonFeaturesEnabled()` for Amazon-specific presentation: it is
  on when explicitly enabled or any Amazon connection is active. This controls visibility,
  not imports, shipping rules, or packing behavior

### Frontend
- Tailwind CSS 4 config in `resources/css/app.css`
- Vite handles HMR and builds
- QZ Tray and scale orchestration use Blade components: `<x-qz-tray>`,
  `<x-qz-tray-script>`, `<x-printer-settings-script>`, and `<x-scale-script>`
- npm-backed QZ and barcode libraries are Vite entrypoints in `resources/js/`; keep
  page-specific browser/hardware behavior with the existing Blade components

## Key Files

- `app/Providers/Filament/AppPanelProvider.php` — Filament panel config
- `app/Filament/Pages/Pack.php` — Packing workflow with scale integration
- `app/Filament/Pages/Ship.php` — Label generation and print dispatch
- `app/Filament/Pages/DeviceSettings.php` — Printer/scale/label format configuration
- `app/Services/Carriers/CarrierRegistry.php` — Carrier adapter registration
- `app/Services/PostageSources/PostageSourceResolver.php` — Eligible direct/channel sources
- `app/Services/PostageSources/PostageSourceDispatcher.php` — Void, tracking, and manifest
  dispatch by recorded postage source
- `app/Services/PostageSources/OfferStore.php` — Opaque Offer issuance, redemption, and recovery state
- `app/Services/PackageShipping/EloquentPackageShippingWorkflow.php` — Shared Label purchase workflow
- `app/Services/PackageLabels/EloquentPackageLabelWorkflow.php` — Active Label reprint and void workflow
- `app/Models/PackageLabel.php` — Durable Label history and active-label projection data
- `app/Models/ShippingOffer.php` — Server-side quoted-purchase authority
- `app/Models/ObservedService.php` / `SourceServiceMapping.php` — Amazon discovery and mapping
- `app/Models/ShippingMethodPostageSource.php` — The sources a method allows; with the method's services, what automation may buy
- `app/Services/CacheService.php` — Box size and carrier service caching
- `app/Models/CarrierAccount.php` — Per-carrier credentials with `resolveForShipment()` priority logic
- `app/Filament/Resources/Clients/ClientResource.php` — 3PL client management (Admin nav group)
- `app/Filament/Resources/LocationResource.php` — Warehouse location management (Admin nav group)
- `app/Filament/Resources/DataSources/DataSourceResource.php` — Connections management (Integrations nav group)
- `docker-compose.yml` — Container orchestration
- `docker/entrypoint.sh` — Container startup (migrate + optimize)
- `scripts/install-onprem.sh` — On-prem installer
- `infra/README.md` — MySQL encryption-at-rest config (three files, required together)

## Testing

Pest 4 on PHPUnit 12. Test files use a `*Test.php` suffix.

- `tests/Unit` — isolated services, enums, factories, integrations, DTOs
- `tests/Feature` — Filament pages, jobs, events, middleware, HTTP flows, import/export, package draft/label/shipping workflows, carrier account routing, client scoping
- `tests/External` — explicit external carrier and reference coverage only; run via `composer run test:external`
- `tests/Browser` — Pest 4 / Playwright shipping, workstation-printer, and print-flow tests

`phpunit.xml` defines only the `Unit` and `Feature` suites, so a bare `php artisan test`
runs neither `tests/External` nor `tests/Browser`. Both must be named explicitly.

`composer run test` runs the suite through Paratest, capped at 16 processes, with opcache
enabled in the workers. Each worker gets
its own in-memory SQLite database and runs its own migrations, so tests must not depend on
running in the same process as another test file. `composer run test:serial` is the
one-process fallback when a failure looks order-dependent.

Set `FAKE_CARRIERS=true` in `.env` to run the app itself against fake carrier adapters and
a fake address validator — for local work without carrier credentials, not for the test
suite. Neither the Pest suites nor `tests/Browser` need it: `ShippingFlowTest` registers
its own fakes into `CarrierRegistry` per test, and CI runs everything from `.env.example`,
where the flag is `false`.

`tests/TestCase.php` prevents stray HTTP/Saloon requests outside `tests/External`.
CI runs the browser suite separately and the `mysql` test group against MySQL 8.4,
including the generated-column constraint for one active Label per Package. Coverage
(`composer run test:ci`) is report-only on pushes to `main`. Pint, Rector, and PHPStan
run as separate CI checks; Docker builds/scans run for relevant PR changes and on `main`.

## Scheduled Operations

Schedules are defined in **both** `routes/console.php` and `bootstrap/app.php`:

- Per-Connection imports; five-minute validation and exports
- Shopify fulfillment sync every 15 minutes and label-cost recovery hourly
- Tracking refresh every four hours; shipping statistics every 15 minutes and nightly
- ECB rate fetches twice daily; daily Label-integrity checks
- Hourly abandoned Draft Shipment cleanup; daily log/data/PII retention and weekly archival

Keep the scheduler and both queue workers running. Purge and archival commands honor
their settings. Reference data sync runs at container startup, not on the scheduler.

## Contributing

Keep commits focused, with short imperative subjects (`Skip partly shipped orders in batch ship`). PRs should explain user-visible impact, note schema or config changes, link related issues, and include screenshots for Filament/UI changes. Flag follow-up steps such as migrations, seeders, or workstation hardware setup. See `CONTRIBUTING.md`.

## Security & Configuration

Never commit secrets: `.env` values, carrier credentials, data source credentials, OAuth tokens, database connection strings, or private QZ signing keys. `.env` holds infrastructure and base URLs; operational credentials live encrypted in the App Settings, Carrier Accounts, and Connections UIs. Carrier credentials live on `CarrierAccount` records and may be scoped by location and client through `CarrierAccountScope`; import/export credentials live on `DataSource` records, each optionally assigned to a client.

If you touch printing, scale, OAuth, pack slips, or carrier account routing, document the workstation, callback URL, certificate, or client-scope implications in the PR.

To report a vulnerability, see `SECURITY.md`.
===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

The package versions below reflect the lockfiles. Check `composer.lock` and
`package-lock.json` when implementing version-specific behavior and refresh this list
when dependencies change.

- php - 8.4
- filament/filament (FILAMENT) - v5.9.0
- laravel/framework (LARAVEL) - v13.34.0
- laravel/prompts (PROMPTS) - v0.3.21
- laravel/scout (SCOUT) - v11.4.0
- laravel/socialite (SOCIALITE) - v5.29.0
- livewire/livewire (LIVEWIRE) - v4.4.8
- larastan/larastan (LARASTAN) - v3.10.0
- laravel/boost (BOOST) - v2.4.13
- laravel/mcp (MCP) - v0.9.1
- laravel/pail (PAIL) - v1.2.7
- laravel/pint (PINT) - v1.29.3
- laravel/sail (SAIL) - v1.64.0
- pestphp/pest (PEST) - v4.7.5
- phpunit/phpunit (PHPUNIT) - v12.5.30
- rector/rector (RECTOR) - v2.5.7
- saloonphp/laravel-plugin (SALOON_LARAVEL) - v4.3.0
- saloonphp/saloon (SALOON) - v4.0.0
- tailwindcss (TAILWINDCSS) - v4.3.3

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.
- Carrier and marketplace API SDK code belongs in `app/Http/Integrations/` using Saloon v4 connectors, requests, responses, OAuth providers, and shared concerns.
- Business workflows belong in small service classes under `app/Services/`; keep Filament resources focused on UI configuration and orchestration.
- Package preparation, label reprint/void, and shipment purchase flows should use the workflow contracts in `app/Contracts/` with implementations under `app/Services/PackageDrafts/`, `app/Services/PackageLabels/`, and `app/Services/PackageShipping/`.
- Import/export flows should use `DataSourceInterface`, `ExportDestinationInterface`, `DataSourceFactory`, and source classes under `app/Services/ShipmentImport/Sources/`.
- Multi-client/3PL code must preserve `client_id` scoping for shipments, products, aliases, import sources, shipping rules, pick batches, and carrier account resolution. Use `ClientContext` and existing `HasDefaultClient` patterns instead of ad hoc defaults.
- Use typed DTOs from `app/DataTransferObjects/` for structured data crossing service and integration boundaries.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Artisan & Debugging

- Use Artisan directly for framework introspection: `php artisan route:list`, `php artisan list`, `php artisan config:show app.name`, and `php artisan [command] --help`.
- Prefer tests, Boost tools, and existing Artisan commands over ad hoc tinker snippets. If tinker is needed, wrap code in single quotes to avoid shell expansion.

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands for Laravel files, including `php artisan make:class` for generic PHP classes, and pass `--no-interaction`.
- When creating models, create useful factories and seeders too.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- Use model factories and their existing states in tests. Follow nearby Faker style (`fake()` or `$this->faker`).
- Create tests with `php artisan make:test [options] {name}`; most tests should be feature tests unless the behavior is isolated.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== laravel/v13 rules ===

# Laravel 13

- CRITICAL: ALWAYS use `search-docs` for version-specific Laravel documentation and updated code examples before changing Laravel or Filament code.
- Laravel 13 uses the streamlined application structure introduced in Laravel 11.
- Middleware, exception handling, and routing configuration live in `bootstrap/app.php` via `Application::configure()`.
- Application service providers are listed in `bootstrap/providers.php`.
- Console routes live in `routes/console.php`; console commands in `app/Console/Commands/` are auto-discovered.
- When modifying a column, the migration must include all previously defined attributes for that column so they are not dropped.
- Prefer model `casts()` methods over `$casts` properties when that matches nearby models.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

=== filament/filament rules ===

## Filament

- Filament is a Laravel UI framework built on Livewire, Alpine.js, and Tailwind CSS. UIs are defined in PHP via fluent, chainable components. Follow existing conventions in this app.
- Use the `search-docs` tool for official documentation on Artisan commands, code examples, testing, relationships, and idiomatic practices. If `search-docs` is unavailable, refer to https://filamentphp.com/docs.

### Artisan

- Always use Filament-specific Artisan commands to create files. Find available commands with the `list-artisan-commands` tool, or run `php artisan --help`.
- Inspect required options before running, and always pass `--no-interaction`.

### Patterns

- Use static `make()` methods for components. Most configuration methods accept closures.
- Use `Get $get` for conditional form state and `Set $set` inside `->afterStateUpdated()` for reactive updates. Prefer `->live(onBlur: true)` on text inputs.
- Compose forms with `Section` and `Grid`; give children explicit `->columnSpan()` or `->columnSpanFull()` when layout matters.
- Use `Repeater::make(...)->relationship()->schema([...])` for inline `HasMany` management.
- Use `TextColumn::make(...)->state(fn (Model $record): mixed => ...)` for derived table values.
- Use `SelectFilter` for enum or relationship filters and `Filter::make(...)->query(...)` for custom table filters.
- Use `Filament\Actions\Action` for buttons with optional modal forms and behavior.

### Testing

Testing setup:

- Authenticate with `$this->actingAs(User::factory()->create())` before panel tests.
- Use `livewire()` helpers for tables/forms, then assert table records, form errors, notifications, redirects, and database state as appropriate.
- For create pages, call `create`; for edit pages, pass `['record' => $model->id]`, call `save`, and do not assert redirect.
- Use `->callAction(DeleteAction::class)` for page actions and `TestAction::make('name')->table($record)` for table actions.

### Correct Namespaces

- Form fields (`TextInput`, `Select`, `Repeater`, etc.): `Filament\Forms\Components\`
- Infolist entries (`TextEntry`, `IconEntry`, etc.): `Filament\Infolists\Components\`
- Layout components (`Grid`, `Section`, `Fieldset`, `Tabs`, `Wizard`, etc.): `Filament\Schemas\Components\`
- Schema utilities (`Get`, `Set`, etc.): `Filament\Schemas\Components\Utilities\`
- Table columns (`TextColumn`, `IconColumn`, etc.): `Filament\Tables\Columns\`
- Table filters (`SelectFilter`, `Filter`, etc.): `Filament\Tables\Filters\`
- Actions (`DeleteAction`, `CreateAction`, etc.): `Filament\Actions\`. Never use `Filament\Tables\Actions\`, `Filament\Forms\Actions\`, or any other sub-namespace for actions.
- Icons: `Filament\Support\Icons\Heroicon` enum (e.g., `Heroicon::PencilSquare`)

### Common Mistakes

- **Never assume public file visibility.** File visibility is `private` by default. Always use `->visibility('public')` when public access is needed.
- **Never assume full-width layout.** `Grid`, `Section`, `Fieldset`, and `Repeater` do not span all columns by default.
- **Use `Select::make('author_id')->relationship('author', 'name')` for BelongsTo fields.** `BelongsToSelect` is not a Filament 5 component.
- **`Repeater` uses `->schema()`, not `->fields()`.**
- **Never add `->dehydrated(false)` to fields that need to be saved.** It strips the value from form state before `->action()` or the save handler runs. Only use it for helper/UI-only fields.
- **Use correct property types when overriding `Page`, `Resource`, and `Widget` properties.** These properties have union types or changed modifiers that must be preserved:
  - `$navigationIcon`: `protected static string | BackedEnum | null` (not `?string`)
  - `$navigationGroup`: `protected static string | UnitEnum | null` (not `?string`)
  - `$view`: `protected string` (not `protected static string`) on `Page` and `Widget` classes

=== saloonphp/laravel-plugin rules ===

## SaloonPHP

- This project uses Saloon v4 for carrier and marketplace API integrations under `app/Http/Integrations/`.
- Documentation: https://docs.saloon.dev
- Check `composer.json` for the installed major version before implementing. Current dependencies are `saloonphp/saloon` v4 and `saloonphp/laravel-plugin` v4.
- Use official Saloon v4 documentation before implementing new integration patterns.
- Prefer existing connectors, request classes, response classes, OAuth providers, and concerns in `app/Http/Integrations/` before adding new abstractions.
- Always use Artisan commands to generate SaloonPHP classes when available: `php artisan saloon:connector`, `php artisan saloon:request`, `php artisan saloon:response`, `php artisan saloon:plugin`, `php artisan saloon:auth`.

</laravel-boost-guidelines>

## Agent skills

### Issue tracker

Two things, and they are not the same thing. **GitHub Issues** — templates under
`.github/ISSUE_TEMPLATE/` — is the *front door*, where reports arrive from outside.
**`docs/issues/`** is the *tracker of record*: planned work as markdown files, committed
so triage state changes in the same PR as the code. Triage promotes a GitHub issue into a
markdown file and closes the GitHub issue with a link. The two are never synced.

So when a skill says "publish to the issue tracker" or "fetch the relevant ticket", it
means a file under `docs/issues/<feature-slug>/`, not `gh issue create`. Start at
`docs/issues/README.md` for what is open; full conventions, including what must not be
written into a public repo, are in `docs/agents/issue-tracker.md`. `.scratch/` is
gitignored scratch space and is not a tracker either.

### Triage labels

`Status:` lines in the markdown issues, using the vocabulary in
`docs/agents/triage-labels.md`. Three of them — `needs-triage`, `needs-info`, `wontfix` —
also exist as real GitHub labels on the intake side. `ready-for-agent` /
`ready-for-human` deliberately do not: they describe how maintainers schedule work rather
than anything a reporter needs to see.

### Domain docs

Single-context layout — `CONTEXT.md` and `docs/adr/` at the repo root. See `docs/agents/domain.md`.
