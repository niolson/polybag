# PolyBag

Streamlined, barcode-driven shipping workstation for packing and labeling shipments. Operators scan barcodes to select boxes, verify item contents, and validate packed quantities before purchasing postage and printing labels — all from a single browser tab connected to a local scale and label printer.

Built with **Laravel 12**, **Filament 5**, and **Tailwind CSS 4**.

## Features

- **Packing workflow** — Scan box codes, scan items into packages, read weight from a USB scale, with command barcodes for ship/reprint/cancel
- **Shipping workflow** — Compare carrier rates with delivery date awareness, purchase postage, and print labels (PDF or ZPL)
- **Batch shipping** — Select multiple shipments and generate labels in bulk via background jobs
- **Manual shipping** — Create ad-hoc shipments without a prior import
- **Address validation** — USPS address verification with DPV confirmation and correction suggestions
- **Multi-carrier support** — USPS, FedEx, and UPS rate quotes and label generation via Saloon
- **Shipping rules** — Pre-select or exclude carrier services per shipping method based on configurable conditions
- **End of Day** — Generate carrier manifests (USPS scan forms, FedEx/UPS close-out), mark packages as manifested
- **Product weight management** — Scan a product barcode, place it on the scale, and update its stored weight
- **Shipment import** — Import from external databases, Shopify, or Amazon SP-API with automatic channel/method mapping
- **Package export** — Export shipped package data back to external systems
- **Dashboard** — Shipping volume trends, carrier breakdown, cost-per-package analysis, and exception tracking
- **Role-based access control** — User, Manager, and Admin roles with policy-based authorization

## Hardware Integration

This app connects directly to local hardware through the browser — no cloud print services required.

### Label Printing (QZ Tray)

[QZ Tray](https://qz.io/download/) must be installed on each workstation. It runs as a local WebSocket service (`wss://localhost:8181`) that the browser communicates with to send print jobs. Printer selection is stored per-browser in `localStorage` and configured through the Device Settings page.

**Supported label formats:**

| Format | Use Case |
|---|---|
| **PDF** | Works out of the box with any printer |
| **ZPL** | Direct thermal printing at 203 or 300 DPI |

ZPL is opt-in via Device Settings. Each carrier has its own ZPL integration (USPS `ZPL203DPI`/`ZPL300DPI`, FedEx `ZPLII`, UPS with 300 DPI scaling).

#### Signing Certificate

QZ Tray requires a signing certificate to allow silent printing (no confirmation popup per print job):

1. **Commercial certificate** — Purchase from [QZ Industries](https://qz.io/pricing/) for automatic trust on any workstation.
2. **Self-signed certificate** — Generate your own keypair and whitelist it in QZ Tray on each workstation.

To generate a self-signed keypair:

```bash
# Generate private key
openssl genrsa -out storage/app/private/qz-private-key.pem 2048

# Generate public certificate (adjust CN to match your domain)
openssl req -x509 -new -key storage/app/private/qz-private-key.pem \
  -out public/qz-certificate.pem -days 3650 \
  -subj "/CN=your-app.test"
```

The private key (`storage/app/private/qz-private-key.pem`) is gitignored and must be generated on each deployment. The public certificate (`public/qz-certificate.pem`) is served to the browser and can be committed.

The app signs print requests server-side via `POST /qz/sign` — the browser sends unsigned data, the backend signs it with the private key, and QZ Tray validates the signature against the public certificate.

### USB Scale (Dual Backend)

Scales are supported through two backends, auto-detected based on browser capabilities:

| Backend | Browser Support | How It Works |
|---|---|---|
| **WebHID** | Chrome, Edge | Browser-native USB HID API, event-driven, no external dependencies |
| **QZ Tray** | Any (with QZ Tray installed) | Polling via WebSocket, fallback when WebHID is unavailable |

Once a scale is paired, it auto-reconnects on subsequent visits. The backend can be overridden in Device Settings (Auto / WebHID / QZ Tray).

### Device Settings

Each workstation configures its own hardware through the **Device Settings** page (`/app/device-settings`). All settings are stored in the browser's `localStorage` — no server-side configuration required.

- **Label Printer** — Select from printers detected by QZ Tray (4x6 shipping labels)
- **Report Printer** — Separate printer for packing slips and customs forms
- **Label Format** — PDF or ZPL thermal
- **Label DPI** — 203 or 300
- **Scale Backend** — Auto / WebHID / QZ Tray with context-appropriate pairing UI

## Requirements

- PHP 8.2+
- MySQL
- Node.js & npm
- [Composer](https://getcomposer.org/)
- [QZ Tray](https://qz.io/download/) (for label printing)
- Chrome or Edge (recommended, required for WebHID scale support)

## Setup

```bash
# Clone the repo
git clone <repo-url>
cd polybag

# Install dependencies, generate key, migrate, and build assets
composer run setup
```

After setup, log in and configure **App Settings** (`/app/settings`) with your company address and carrier API credentials.

## Development

```bash
# Run server, queue worker, and Vite dev server concurrently
composer run dev

# Or run individual services
php artisan serve          # Laravel dev server
php artisan queue:listen   # Queue worker
npm run dev                # Vite HMR
```

## Testing

Tests use the [Pest](https://pestphp.com/) framework.

```bash
# Run full test suite
composer run test

# Run a specific test file
php artisan test tests/Feature/AuthorizationTest.php

# Filter by test name
php artisan test --filter="manager role access"
```

## Code Style

```bash
# Auto-fix formatting with Laravel Pint
vendor/bin/pint

# Run Rector, PHPStan, and Pint together
composer run format
```

## Configuration

### Environment Variables (`.env`)

Copy `.env.example` to `.env` and configure. Most settings only need the database connection — carrier API credentials, company address, and feature flags are managed through the App Settings UI.

```env
# Core (required)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=shipping
DB_USERNAME=root
DB_PASSWORD=
```

The `.env` file also supports shipment import source configuration (database connection, Shopify, Amazon) and carrier API base URLs. See `.env.example` for the full list.

### App Settings (Database)

Most operational configuration is managed through the **App Settings** page (`/app/settings`), accessible to admins:

- **Company info** — Company name and ship-from address (used on labels)
- **Carrier API credentials** — USPS, FedEx, UPS (stored encrypted in the database)
- **Marketplace credentials** — Shopify and Amazon SP-API (stored encrypted)
- **Feature flags** — Packing validation, transparency codes, batch shipping, manual shipping
- **Sandbox mode** — Use carrier test endpoints with optional print suppression
- **Carrier API timeout** — Configurable request timeout (5–60 seconds)

## Artisan Commands

```bash
# Shipment import from external sources
php artisan shipments:import                # Import from configured source
php artisan shipments:import --dry-run      # Preview without changes
php artisan shipments:import --validate-only # Test connection and config

# Address validation
php artisan shipments:validate              # Validate all unchecked US shipments
php artisan shipments:validate --limit=100  # Validate in batches
php artisan shipments:validate --dry-run    # Preview without updating

# Package export
php artisan packages:export                 # Export shipped packages to external destinations
php artisan packages:export --dry-run       # Preview without exporting

# Dashboard stats
php artisan stats:aggregate                 # Rebuild daily shipping stats cache
php artisan stats:aggregate --today         # Rebuild today only

# Test data
php artisan app:generate-test-data              # Generate 75k test shipments
php artisan app:generate-test-data --count=500  # Custom count
php artisan app:generate-test-data --cleanup    # Remove all test data (TD- prefix)
```

## Shipment Import

Shipments are imported from external data sources using a pluggable source system defined by `ImportSourceInterface`.

**Supported sources:**

| Source | Description |
|---|---|
| **Database** | Custom SQL queries against MySQL, SQL Server, or PostgreSQL |
| **Shopify** | Native integration via Shopify Admin API |
| **Amazon** | Native integration via Amazon SP-API |

During import:
- Fields are mapped from external to internal names via `config/shipment-import.php`
- Channels and shipping methods are resolved through alias tables (configure in the UI under Map Shipping References / Map Channel References)
- Phone numbers are parsed and extensions extracted automatically
- Products are auto-created from imported SKU data (if enabled)
- Records can optionally be marked as exported in the source database

## Address Validation

US shipping addresses are validated against the USPS Address API, either in bulk via the artisan command, or per-shipment through the Shipments table bulk action.

Each shipment receives a deliverability status based on USPS DPV (Delivery Point Validation) confirmation:

| Status | Meaning |
|---|---|
| **Yes** | Address confirmed deliverable |
| **Maybe** | Partial match — e.g., missing apartment number, or secondary address not confirmed |
| **No** | Address not found, not deliverable, or DPV confirmation unavailable |
| **Not Checked** | No validator available for this country, or not yet validated |

The USPS-corrected address is stored alongside the original so operators can compare and accept corrections in the UI. Non-US addresses are marked as Not Checked.

## Domain Model

| Model | Description |
|---|---|
| **Shipment** | Order to be shipped (address, items, validation status) |
| **ShipmentItem** | Line items in a shipment |
| **Package** | Physical package with tracking, label, and dimensions |
| **PackageItem** | Items packed in a package (with transparency codes) |
| **ShippingMethod** | Available shipping options |
| **ShippingRule** | Conditions that pre-select or exclude carrier services per method |
| **Carrier** / **CarrierService** | Carriers (USPS, FedEx, UPS) and their service tiers |
| **BoxSize** | Predefined box dimensions (scanned by code during packing) |
| **Channel** | Sales channel source (Shopify, Amazon, manual, etc.) |
| **Product** | Product catalog with SKU, barcode, and weight |
| **LabelBatch** / **LabelBatchItem** | Batch label generation jobs and their per-shipment results |
| **Manifest** | End-of-day carrier manifests with stored images |
| **Location** | Warehouse / fulfillment center addresses |
| **Setting** | Encrypted key-value store for app configuration |

## Authorization

Three roles with hierarchical permissions:

| Area | User | Manager | Admin |
|---|---|---|---|
| Pack / Ship / Device Settings / Update Weight | Y | Y | Y |
| Shipments (view) | Y | Y | Y |
| Shipments (create/edit) | — | Y | Y |
| Shipments (delete) | — | — | Y |
| Validate Addresses (bulk action) | Y | Y | Y |
| End of Day (manifests) | — | Y | Y |
| Unmapped References | — | Y | Y |
| Box Sizes, Products, Shipping Methods | — | CRUD | CRUD |
| Batch Shipping | — | — | Y |
| Users, Carriers, Carrier Services, Channels | — | — | CRUD |
| App Settings | — | — | Y |

## License

[MIT](LICENSE)
