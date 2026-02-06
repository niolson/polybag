# Filament Shipping

Streamlined, barcode-driven shipping workstation for packing and labeling shipments. Operators scan barcodes to select boxes, verify item contents, and validate packed quantities before purchasing postage and printing labels — all from a single browser tab connected to a local scale and label printer.

Built with **Laravel 12**, **Filament 5**, and **Tailwind CSS 4**.

## Features

- **Packing workflow** - Scan box codes, scan items into packages, read weight from a USB scale
- **Shipping workflow** - Get carrier rate quotes, purchase postage, and print labels
- **Address validation** - USPS address verification with correction suggestions
- **Multi-carrier support** - USPS and FedEx rate quotes and label generation via SaloonPHP
- **Product weight management** - Scan a product barcode, place it on the scale, and update its stored weight
- **Shipment import** - Pluggable system for importing shipment data from external databases or APIs
- **Role-based access control** - User, Manager, and Admin roles with policy-based authorization

## Hardware Integration

This app connects directly to local hardware through the browser -- no cloud print services required.

### Label Printing (QZ Tray)

[QZ Tray](https://qz.io/download/) must be installed on each workstation. It runs as a local WebSocket service (`wss://localhost:8181`) that the browser communicates with to send print jobs. Printer selection is stored per-browser in `localStorage` and configured through the Device Settings page.

#### Signing Certificate

QZ Tray requires a signing certificate to allow silent printing (no confirmation popup per print job). There are two options:

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

### USB Scale (WebHID)

Scales connect via the browser-native [WebHID API](https://developer.mozilla.org/en-US/docs/Web/API/WebHID_API) (Chrome/Edge only). Once a scale is paired, it auto-reconnects on subsequent visits. Scale vendor/product IDs are stored in `localStorage`.

### Device Settings

Each workstation configures its own hardware through the **Device Settings** page (`/app/device-settings`). All settings are stored in the browser's `localStorage` — no server-side configuration required.

- **Label Printer** — Select from printers detected by QZ Tray (4x6 shipping labels)
- **Report Printer** — Separate printer for packing slips and customs forms
- **USB Scale** — Pair via the browser's HID device picker; auto-connects on subsequent visits

## Requirements

- PHP 8.2+
- MySQL
- Node.js & npm
- [Composer](https://getcomposer.org/)
- [QZ Tray](https://qz.io/download/) (for label printing)
- Chrome or Edge (for WebHID scale support)

## Setup

```bash
# Clone the repo
git clone <repo-url> shipping-native
cd shipping-native

# Install dependencies, generate key, migrate, and build assets
composer run setup

# Copy and configure environment variables
cp .env.example .env
# Edit .env with your database credentials, API keys, etc.
php artisan key:generate
php artisan migrate
```

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

## Environment Configuration

Copy `.env.example` to `.env` and configure. See below for what each group does and when it's needed.

### Core (Required)

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=shipping
DB_USERNAME=root
DB_PASSWORD=
```

### USPS API (Address Validation + Domestic Rates + Labels)

Register at the [USPS Developer Portal](https://developer.usps.com) to get OAuth credentials.

```env
USPS_API_CLIENT_ID=
USPS_API_CLIENT_SECRET=
USPS_CRID=                # Customer Registration ID
USPS_MID=                 # Mailer ID
```

### FedEx API (Rate Quotes + Shipments)

Register at the [FedEx Developer Portal](https://developer.fedex.com).

```env
FEDEX_API_KEY=
FEDEX_API_SECRET=
FEDEX_ACCOUNT_NUMBER=
```

### Ship-From Address (Labels)

The return address printed on shipping labels:

```env
SHIPPING_ORIGIN_ZIP=98072
SHIPPING_FROM_COMPANY="My Company"
SHIPPING_FROM_STREET="123 Main St"
SHIPPING_FROM_CITY=Redmond
SHIPPING_FROM_STATE=WA
SHIPPING_FROM_ZIP_PLUS4=98072-1234
SHIPPING_FROM_PHONE=5551234567
```

### Shipment Import (External Database)

Only needed if importing shipments from an external data source. See [Shipment Import](#shipment-import) below.

```env
SHIPMENT_IMPORT_DB_DRIVER=mysql
SHIPMENT_IMPORT_DB_HOST=127.0.0.1
SHIPMENT_IMPORT_DB_PORT=3306
SHIPMENT_IMPORT_DB_DATABASE=erp_database
SHIPMENT_IMPORT_DB_USERNAME=readonly_user
SHIPMENT_IMPORT_DB_PASSWORD=
```

## Shipment Import

Shipments are imported from external data sources using a pluggable source system defined by `ImportSourceInterface`. Currently supports database connections (MySQL, SQL Server, PostgreSQL).

### How It Works

The importer connects to an external database, maps fields from the external schema to the internal schema, and upserts shipments by composite key (channel + reference) to avoid duplicates.

```bash
php artisan shipments:import                # Import from configured source
php artisan shipments:import --dry-run      # Preview without changes
php artisan shipments:import --validate-only # Test connection and config
```

During import:
- Fields are mapped from external to internal names via `config/shipment-import.php`
- Channels and shipping methods are resolved through alias tables (configure in the UI)
- Phone numbers are parsed and extensions extracted automatically
- Products are auto-created from imported SKU data
- Records can optionally be marked as exported in the source database

### Custom Queries

By default, the importer reads from `shipments` and `shipment_items` tables. To use custom queries:

```env
SHIPMENT_IMPORT_SHIPMENTS_QUERY="SELECT * FROM orders WHERE exported = 0"
SHIPMENT_IMPORT_ITEMS_QUERY="SELECT * FROM order_lines WHERE order_id = :shipment_reference"
```

## Address Validation

After import, validate US shipping addresses against the USPS Address API:

```bash
php artisan shipments:validate              # Validate all unchecked US shipments
php artisan shipments:validate --limit=100  # Validate in batches
php artisan shipments:validate --dry-run    # Preview without updating
```

Each shipment receives a deliverability status based on USPS DPV (Delivery Point Validation) confirmation:

| Status | Meaning |
|---|---|
| **Yes** | Address confirmed deliverable |
| **Maybe** | Partial match — e.g., missing apartment number, or secondary address not confirmed |
| **No** | Address not found, not deliverable, or DPV confirmation unavailable |

The USPS-corrected address is stored alongside the original so operators can compare and accept corrections in the UI. Non-US addresses skip validation.

## Domain Model

| Model | Description |
|---|---|
| **Shipment** | Order to be shipped (address, items, validation status) |
| **ShipmentItem** | Line items in a shipment |
| **Package** | Physical package with tracking, label, and dimensions |
| **PackageItem** | Items packed in a package (with transparency codes) |
| **ShippingMethod** | Available shipping options |
| **Carrier** / **CarrierService** | Carriers (USPS, FedEx) and their service tiers |
| **BoxSize** | Predefined box dimensions (scanned by code during packing) |
| **Channel** | Sales channel source |
| **Product** | Product catalog with SKU, barcode, and weight |

## Authorization

Three roles with hierarchical permissions:

| Area | User | Manager | Admin |
|---|---|---|---|
| Pack / Ship / Device Settings / Update Weight | Y | Y | Y |
| Shipments (view) | Y | Y | Y |
| Shipments (create/edit) | - | Y | Y |
| Shipments (delete) | - | - | Y |
| Box Sizes, Products, Shipping Methods | - | CRUD | CRUD |
| Unmapped References | - | Y | Y |
| Users, Carriers, Carrier Services, Channels | - | - | CRUD |
| App Settings | - | - | Y |

## License

[MIT](LICENSE)
