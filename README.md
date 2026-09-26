# PolyBag

PolyBag is a barcode-driven shipping workstation for picking, packing, buying
postage, printing labels, and tracking fulfillment work. Operators work from a
browser connected to a local scale and printers.

Built with Laravel 13, Filament 5, Livewire 4, Tailwind CSS 4, MySQL, and Redis.

> PolyBag is source-available under the Business Source License 1.1. Production
> commercial use requires a commercial licence until the applicable change date.
> See [Licence](#licence).

## Features

- Optional pick batches with printable summaries and pack slips
- Barcode-guided packing with item, quantity, and transparency-code validation
- USB scale support through WebHID or QZ Tray
- Direct USPS, FedEx, and UPS rate shopping, postage purchase, tracking, and voids
- International shipping with customs declarations, including separate customs forms when
  carriers return them
- Amazon Buy Shipping offers for Amazon-originating orders, with label purchase, tracking,
  and cancellation through SP-API
- Attended Shopify Shipping label purchase for Shopify fulfillment orders
- Durable label history: voided labels remain attached to the package, which can then be
  shipped again
- Recovery of unresolved direct-carrier purchases before PolyBag attempts another charge
- PDF/image labels and raw ZPL at 203 or 300 DPI, with separate printer assignments
- Delivery-date-aware rate comparison and configurable shipping rules
- Manual shipping and background batch shipping
- USPS SCAN forms and location-scoped end-of-day processing
- Carrier-supplied packaging support for USPS flat-rate, FedEx, and UPS packaging
- Dynamic Amazon service discovery with explicit mapping and approval for automated shipping
- Database, Shopify, and Amazon SP-API shipment imports
- Package export with per-client destination overrides
- Multi-location carrier-account routing
- Optional multi-client / 3PL scoping and pack-slip branding
- Product weights, special services, and hazmat metadata
- Shipping, billing, rate-comparison, and packing-validation reports
- Tracking exception monitoring, audit logs, and configurable data retention
- User, Manager, and Admin roles with optional MFA and Google or Entra SSO

## Hardware

### Label printing

[QZ Tray](https://qz.io/download/) runs on each workstation and sends jobs to
local label and document printers. Labels have two printer slots — one for PDF, PNG,
or GIF labels through the driver, and one for raw ZPL — which can be the same printer;
a raw-only workstation can be a plain "Generic / Text Only" queue on Windows. The
document printer handles pack slips, pick lists, and separate customs forms. Printer,
preferred purchase format, DPI, and scale preferences are stored in that browser and
managed from **Device Settings**.

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

`composer run dev` starts the Laravel server, default queue worker, dedicated import
worker, scheduler, and Vite development server.

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
| Carrier services, service classes, packaging, and shipping rules | Shipping Config |
| Amazon observed-service mapping and unattended-purchase approval | Map Carrier Services |
| Per-client return address, branding, and export override | Clients |
| Image-label, raw-label, and document printers; format, DPI, and scale | Device Settings in each browser |
| Database, Redis, mail, SSO, Google validation, Gotenberg | `.env` |

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

When rotating `APP_KEY`, keep the old key in `APP_PREVIOUS_KEYS`. Remove it only
after `app:reencrypt-secrets` succeeds without undecryptable values.

The scheduler runs configured imports, package exports, shipment validation, Shopify
fulfillment synchronization, and a daily label-integrity check. Keep the `scheduler`
and both queue workers running in production.

## Development commands

```bash
composer run dev              # App, queues, scheduler, and Vite
composer run test             # Unit and feature tests
composer run test:external    # Explicit live carrier/reference tests
npm run build                 # Production frontend assets
composer run format           # Rector, PHPStan, and Pint
```

Browser tests use Pest 4 and Playwright. They are not part of the default suite:

```bash
npx playwright install chromium
php artisan test tests/Browser/
```

## Domain language

A **Shipment** is an order to fulfil. A **Package** is the physical parcel produced
from it. A **Package Draft** is an unshipped Package that has been prepared but has
not completed label purchase. A **Label** is one purchased instance of outbound
postage for a Package. A Package has at most one active Label; voiding it preserves
the Label as history and returns the Package to an unshipped state. These terms are
intentionally distinct.

The **carrier of record** is the company physically moving the parcel. The **postage
source** is where the Label was bought: one of PolyBag's direct Carrier Accounts or a
Shopify/Amazon Connection. Tracking, voiding, and manifest eligibility follow the
postage source, so marketplace-bought postage is never treated as if it came from one
of the merchant's direct carrier accounts.

An **Offer** is a package-specific quoted rate backed by short-lived purchase authority
held on the server. Shopify Shipping is different: it is an attended **blind purchase**
whose final carrier is learned after purchase and whose price and service Shopify does
not report. A packer buys one only after confirming it. Automation buys one only when a
shipping rule names it, or when Shopify is the shipping method's sole eligible source and
offers exactly one purchase. Shopify Shipping labels must be voided in the Shopify admin;
the scheduled fulfillment sync detects the void and returns the Package to an unshipped
state.

A packer may buy any Offer shown on the Ship page. Auto-ship, batch shipping, and shipping
rules buy only within the shipping method's **allowance**: the postage sources the method
permits, and the services it lists. For Amazon Buy Shipping, an administrator can widen
this to any service Amazon offers. A Connection's **postage setting** (*does not sell
postage*, *packer only*, or *packer and automation*) can narrow the allowance further. A
Shipment needs a shipping method before any Label is bought.

Amazon's Shipping v2 API distinguishes Amazon orders (`channelType: AMAZON`) from orders
from other channels (`channelType: EXTERNAL`). An Amazon order is quoted through Amazon Buy
Shipping and can receive Amazon Shipping, USPS, UPS, FedEx, OnTrac, or another carrier that
Amazon returns. Amazon may offer services PolyBag has never seen. An administrator maps
these to the carrier-service catalog on *Map Carrier Services*; until then, only a packer,
or a method allowing any service, can buy them. A Shipment from Shopify, a database
source, or Manual Ship can be sold Amazon Shipping directly, like any other carrier. The
seller's Amazon Connection is the account, and a Carrier Account Scope chooses which
Connection sells for each Location and Client.

The main scopes are **Location** for a warehouse and **Client** for a 3PL brand or
retailer. **Carrier Account Scopes** select credentials from the Location and Client.

See [CONTEXT.md](CONTEXT.md) for the glossary and
[architecture decisions](docs/adr/) for structural context.

## Licence

PolyBag is source-available, not open source. It is licensed under the
[Business Source License 1.1](LICENSE).

The licence permits reading, modifying, redistributing, and running the code for
personal, educational, non-commercial, internal evaluation, and development use. It
does not permit production use intended to generate revenue or commercial advantage.

On March 11, 2030—or four years after a particular version was first published,
whichever comes first—that version converts to Apache License 2.0. The PolyBag name,
logo, and `polybag.app` branding remain unlicensed trademarks after conversion.

For commercial terms, contact `license@polybag.app`.

## Contributing and security

See [CONTRIBUTING.md](CONTRIBUTING.md) for the development loop and contributor
agreement. Report vulnerabilities privately as described in [SECURITY.md](SECURITY.md).
