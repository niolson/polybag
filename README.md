# PolyBag

PolyBag is a barcode-driven shipping workstation for picking, packing, buying
postage, and printing labels. Operators work from a browser connected to a local
scale and label printer.

Built with Laravel 13, Filament 5, Livewire 4, Tailwind CSS 4, MySQL, and Redis.

> PolyBag is source-available under the Business Source License 1.1. Production
> commercial use requires a commercial licence until the applicable change date.
> See [Licence](#licence).

## Features

- Optional pick batches with printable summaries and pack slips
- Barcode-guided packing with item, quantity, and transparency-code validation
- USB scale support through WebHID or QZ Tray
- USPS, FedEx, and UPS rates, postage purchase, labels, tracking, and voids
- PDF labels, plus ZPL at 203 or 300 DPI
- Delivery-date-aware rate comparison and configurable shipping rules
- Manual shipping and background batch shipping
- USPS SCAN forms and location-scoped end-of-day processing
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
local label and document printers. Labels have two printer slots — one for PDF/image
labels through the driver, one for raw ZPL — which can be the same printer; a raw-only
workstation can be a plain "Generic / Text Only" queue on Windows. Printer, label
format, DPI, and scale preferences are stored in that browser and managed from
**Device Settings**.

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
| USPS, FedEx, and UPS credentials and routing scopes | Carrier Accounts |
| Database, Shopify, and Amazon credentials and schedules | Data Sources |
| Per-client return address, branding, and export override | Clients |
| Printer, label format, DPI, and scale | Device Settings in each browser |
| Database, Redis, mail, SSO, Google validation, Gotenberg | `.env` |

Secrets on Carrier Account and Data Source records are encrypted. Never commit
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

When rotating `APP_KEY`, keep the old key in `APP_PREVIOUS_KEYS`. Remove it only
after `app:reencrypt-secrets` succeeds without undecryptable values.

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
from it. A **Package Draft** is the mutable preparation state before label purchase.
These terms are intentionally distinct.

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
