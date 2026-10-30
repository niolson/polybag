# PolyBag

PolyBag is a barcode-driven shipping workstation for picking, packing, buying
postage, printing labels, and tracking fulfillment work. Operators work from a
browser connected to a local scale and printers.

Built with Laravel 13, Filament 5, Livewire 4, Tailwind CSS 4, MySQL, and Redis.

> PolyBag is source-available under the Business Source License 1.1. Production
> commercial use requires a commercial license until the applicable change date.
> See [License](#license).

## Features

- Optional pick batches, printable summaries, and a pack-slip queue with printed and
  out-of-date indicators
- Barcode-guided packing with item, quantity, and transparency-code validation
- USB scale support through WebHID or QZ Tray
- Direct USPS, FedEx, and UPS rate shopping, postage purchase, tracking, and voids
- International shipping with customs declarations, including separate customs forms when
  carriers return them
- Shipment and client duties terms, seller tax registrations, customs readiness checks,
  and prepaid-duties reporting
- Amazon Buy Shipping offers for Amazon-originating orders, with label purchase, tracking,
  and cancellation through SP-API
- Shopify Shipping blind label purchase for Shopify fulfillment orders
- Direct Amazon Shipping for orders from other channels through a scoped Amazon Connection
  (verified in sandbox only)
- Durable label history: voided labels remain attached to the package, which can then be
  shipped again
- Recovery of unresolved direct-carrier purchases before PolyBag attempts another charge
- PDF/image labels and raw ZPL at 203 or 300 DPI, with separate printer assignments
- Delivery-date-aware rate comparison and configurable shipping rules
- Ship-by dates and due-to-ship filters, separate from delivery deadlines
- Manual shipping and background batch shipping
- USPS SCAN forms and location-scoped end-of-day processing
- Carrier-supplied packaging support for USPS flat-rate, FedEx, and UPS packaging
- Dynamic Amazon service discovery with explicit catalog mapping and shipping-method
  allowances for automated purchases
- Database, Shopify, and Amazon SP-API shipment imports
- Source-closed order reconciliation during imports, preserving manager reopen decisions
- Package export to the originating Connection, plus optional global destinations in
  multi-client mode
- Multi-location carrier-account routing
- Optional multi-client / 3PL scoping and pack-slip branding
- Product weights, special services, and hazmat metadata
- Shipping, billing, rate-comparison, and packing-validation reports
- Tracking exception monitoring, audit logs, and configurable data retention
- Packer, Manager, and Admin roles with optional MFA and Google or Entra SSO

## Hardware

### Label printing

[QZ Tray](https://qz.io/download/) runs on each workstation and sends jobs to
local label and document printers. Labels have two printer slots — one for PDF, PNG,
or GIF labels through the driver, and one for raw ZPL — which can be the same printer;
a raw-only workstation can be a plain "Generic / Text Only" queue on Windows. Pack slips
use 4×6 stock on the image label printer; the document printer handles pick lists,
manifests, and separate customs forms. Printer, preferred purchase format, DPI, and
scale preferences are stored in that browser and managed from **Workstation Settings**.

Printed status records that QZ accepted the job, or that an operator marked a viewed
pack slip printed. It does not confirm that paper physically came out of the printer.

Generate a self-signed QZ certificate for development or a private deployment:

```bash
php artisan app:generate-qz-cert shipping.example.com
```

The private key must stay secret. Each workstation must trust the matching public
certificate before silent printing will work. See
[QZ Tray provisioning](docs/qz-tray-provisioning.md) for the complete setup.

### USB scales

Chrome and Edge use WebHID when available. QZ Tray provides the fallback backend.
WebHID requires HTTPS or localhost; a paired scale reconnects on later visits.

## Supported deployment

The supported self-hosted deployment is a standalone Docker stack with its own
MySQL 8.4, Redis, and Gotenberg containers.

Requirements: Docker Engine, the Docker Compose plugin, and a Linux host.

```bash
git clone https://github.com/niolson/polybag.git
cd polybag
./scripts/install-onprem.sh
```

After installation, create the first administrator:

```bash
docker compose --profile standalone \
  -f docker-compose.yml -f docker-compose.onprem.yml \
  exec -it app php artisan app:create-user
```

Open the URL reported by the installer. The first administrator is sent through
the Setup Wizard to configure the warehouse, carriers, box sizes, shipping methods,
and an optional import source.

The Compose override publishes HTTP on `APP_PORT` (80 by default). For an HTTPS URL,
configure TLS at a reverse proxy; the installer does not provision it.

PolyBag can run without any service operated by POLYBAG.APP LLC. Self-hosters bring
their own carrier, marketplace, mail, address-validation, and SSO credentials. See
[Self-hosting](docs/self-hosting.md) for the credential and callback-URL map.

After pulling updates, rebuild and restart the stack so image changes take effect:

```bash
docker compose --profile standalone \
  -f docker-compose.yml -f docker-compose.onprem.yml \
  up -d --build
```

The app exposes `/up` for liveness and `/api/health` for MySQL and Redis readiness.
Restrict the readiness endpoint to your monitoring system at the reverse proxy.

Startup runs migrations, synchronizes reference data, attempts MySQL table encryption,
and caches the application configuration. The installer also verifies encryption.
Keep database backups together with the MySQL keyring needed to decrypt them; see
[MySQL encryption setup](infra/README.md).

## Local development

Requirements: PHP 8.4, Composer, Node.js 22.12+, MySQL 8.4, and Redis.

Create a database, copy `.env.example` to `.env`, and set the local database and
Redis connection values. Then run:

```bash
composer run setup
php artisan app:sync-reference-data
php artisan app:create-user
composer run dev
```

`composer run dev` starts the Laravel server, default/low queue worker, dedicated import
worker, scheduler, and Vite development server.

PDF generation also needs a reachable Gotenberg service configured with `GOTENBERG_URL`.
The standalone Docker stack includes it; `composer run dev` does not start it.
Use `composer run setup` for initial setup: it generates a new application key.

For local end-to-end work without carrier credentials or billable API requests, set:

```env
FAKE_CARRIERS=true
```

The fake adapters cover rating, labels, and address validation.

## Configuration

Infrastructure and base URLs live in `.env`; operational configuration lives in
the application database.

| Configuration | Location |
|---|---|
| Company, warehouse, feature flags, authentication, retention | App Settings |
| USPS, FedEx, and UPS credentials and Location/Client routing scopes | Carrier Accounts |
| Database, Shopify, and Amazon credentials, schedules, and marketplace postage | Connections |
| Carrier services, service classes, packaging, and shipping rules | Carriers / Shipping Setup |
| Amazon observed-service mapping | Integrations → Map Amazon Services |
| Allowed postage sources, services, and Amazon offer requirements for automation | Shipping Methods |
| Per-client return address, branding, label reference, and customs policy | Clients (App Settings for the default client in single-client mode) |
| Image-label, raw-label, and document printers; format, DPI, and scale | Workstation Settings in each browser |
| Database, Redis, mail, SSO, Google validation, Gotenberg | `.env` |

Connections have separate switches for being active and importing orders. A Connection
can sell postage or receive tracking without importing. Its **Sells Postage** switch decides
whether Shopify or Amazon postage is bought on its account at all.

Amazon-specific screens appear when an Amazon Connection is active or **Amazon features**
is enabled in App Settings. Enable that setting manually for Amazon orders imported from
a database. Pack slips and picking have their own feature switches.

Secrets on Carrier Account and Connection records are encrypted. Never commit
`.env`, carrier credentials, OAuth tokens, database connection strings, or QZ private
keys.

## Operations

Run `php artisan list` to discover all commands. Self-hosters should know these:

| Command | Purpose |
|---|---|
| `app:reencrypt-secrets` | Complete an `APP_KEY` rotation; see note below |
| `data:purge` | Apply audit-log, rate-quote, shipping-offer, and notification retention |
| `shipments:purge-pii` | Apply recipient PII retention, with a dry-run option |
| `db:encrypt-tables` | Enable or verify MySQL table encryption |
| `app:generate-ssh-key` | Create keys for database import tunnels |
| `app:verify-label-integrity` | Report any mismatch between Packages and their active Label records |
| `app:sync-reference-data` | Sync carrier/service catalogs, mappings, and carrier packaging |
| `exchange-rates:fetch` | Fetch reference rates used for seller tax registration thresholds |
| `packages:sync-shopify-label-costs` | Recover identifiable USD label costs from Shopify order events |
| `address-validation:report` | Report validator results against later parcel outcomes |

When rotating `APP_KEY`, keep the old key in `APP_PREVIOUS_KEYS`. Remove it only
after `app:reencrypt-secrets` succeeds without undecryptable values.

The scheduler runs configured imports, exports and address validation, tracking refreshes,
Shopify fulfillment and label-cost synchronization, exchange-rate fetching, shipping
statistics, retention and archival tasks, abandoned Manual Ship draft cleanup, and a
daily label-integrity check. Keep the `scheduler` and both queue workers running in
production. Schedules live in `routes/console.php` and `bootstrap/app.php`.

## Development commands

```bash
composer run dev                  # App, queues, scheduler, and Vite
composer run test                 # Unit and feature tests
composer run test:serial          # Same suite in one process
composer run test:external        # Explicit live carrier/reference tests
composer run test:fedex-reference # FedEx reference cases only
npm run build                     # Production frontend assets
composer run format               # Rector, PHPStan, and Pint
```

The default suite runs Unit and Feature tests in parallel, capped at 16 processes,
with a separate in-memory SQLite database per worker. Browser tests use Pest 4 and
Playwright and must be run explicitly:

```bash
npx playwright install chromium
php artisan test tests/Browser/
```

CI also runs browser tests, the `mysql` test group against MySQL 8.4, Pint, Rector,
and PHPStan. Coverage is collected on pushes to `main`.

## Domain language

A **Shipment** is an order to fulfil. A **Package** is the physical parcel produced
from it. A **Package Draft** is an unshipped Package that has been prepared but has
not completed label purchase. A **Label** is one purchased instance of outbound
postage for a Package. A Package has at most one active Label; voiding it preserves
the Label as history and returns the Package to an unshipped state. These terms are
intentionally distinct.

Manual Ship creates a **Draft Shipment** to show rates before purchase. It stays out
of operational lists until a Label is bought; abandoned drafts with no Label history
are deleted after 24 hours without changes. This is separate from a resumable Package
Draft saved while packing an existing Shipment.

The **carrier of record** is the company physically moving the parcel. The **postage
source** is where the Label was bought: one of PolyBag's direct Carrier Accounts or a
Shopify/Amazon Connection. Tracking, voiding, and manifest eligibility follow the
postage source, so marketplace-bought postage is never treated as if it came from one
of the merchant's direct carrier accounts.
Direct-label tracking and voids use the account recorded at purchase, even if routing
scopes later change.

An **Offer** is a package-specific quoted rate backed by short-lived purchase authority
held on the server. Shopify Shipping is a **blind purchase**
whose final carrier is learned after purchase and whose purchase response reports no
price or service. PolyBag can later recover an identifiable USD cost from Shopify's
order timeline and infer service from label evidence; either may remain unknown.
A packer buys one only after confirming it. Automation buys one only when a
shipping rule names it, or when Shopify is the shipping method's sole eligible source and
offers exactly one purchase. Shopify Shipping labels must be voided in the Shopify admin;
the scheduled fulfillment sync detects the void and returns the Package to an unshipped
state.

A packer may buy any Offer shown on the Ship page. Auto-ship, batch shipping, and shipping
rules buy only within the shipping method's **allowance**: the postage sources the method
permits, and the services it lists. For Amazon Buy Shipping, an administrator can widen
this to any service Amazon offers. A Connection that does not **sell postage** is
never asked, by a packer or by automation. A
Shipment needs a shipping method before any Label is bought.

Amazon's Shipping v2 API distinguishes Amazon orders (`channelType: AMAZON`) from orders
from other channels (`channelType: EXTERNAL`). An Amazon order is quoted through Amazon Buy
Shipping and can receive Amazon Shipping, USPS, UPS, FedEx, OnTrac, or another carrier that
Amazon returns. Amazon may offer services PolyBag has never seen. An administrator maps
these to the carrier-service catalog on *Map Amazon Services*; until then, only a packer,
or a method allowing any service, can buy them. A Shipment from Shopify, a database
source, or Manual Ship can be sold Amazon Shipping directly, like any other carrier. The
seller's Amazon Connection is the account, and a Carrier Account Scope chooses which
Connection sells for each Location and Client.

For international postage that accepts PolyBag's customs terms, the Shipment's duties
choice overrides its Client's destination policy. An EU destination needs an explicit
choice; incompatible rates are excluded and customs readiness is checked before purchase.
Labels retain a snapshot of the declared terms. Amazon and Shopify purchases use
**source-decided terms**; automation currently holds these purchases for customs
crossings into the EU, UK, Norway, and Australia, while attended purchase remains available.

PolyBag-printed barcodes use a `%` prefix, such as `%S216` for a Shipment or `%CSHIP`
for the Ship command. Order references, SKUs, UPCs, and tracking numbers remain external
identifiers.

The main scopes are **Location** for a warehouse and **Client** for a 3PL brand or
retailer. **Carrier Account Scopes** select credentials from the Location and Client.

See [CONTEXT.md](CONTEXT.md) for the glossary and
[architecture decisions](docs/adr/) for structural context.

## License

PolyBag is source-available, not open source. It is licensed under the
[Business Source License 1.1](LICENSE).

The license permits reading, modifying, redistributing, and running the code for
personal, educational, non-commercial, internal evaluation, and development use. It
does not permit production use intended to generate revenue or commercial advantage.

On March 11, 2030—or four years after a particular version was first published,
whichever comes first—that version converts to Apache License 2.0. The PolyBag name,
logo, and `polybag.app` branding remain unlicensed trademarks after conversion.

For commercial terms, contact `license@polybag.app`.

## Contributing and security

See [CONTRIBUTING.md](CONTRIBUTING.md) for the development loop and contributor
agreement. Report vulnerabilities privately as described in [SECURITY.md](SECURITY.md).
