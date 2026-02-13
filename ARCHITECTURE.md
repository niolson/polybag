# Architecture Guide

This document explains how the application is structured, the design patterns used, and where it deviates from a typical Laravel + Filament app.

## High-Level Overview

This is a shipping workstation app: import orders from sales channels, pack items into boxes, buy postage from carriers, print labels, and report fulfillment back to the sales channel. It's built on three layers:

1. **UI Layer** (Filament) - Admin panel with CRUD resources and custom workflow pages
2. **Service Layer** - Business logic, carrier integrations, import/export orchestration
3. **Data Layer** - Eloquent models, DTOs, config-driven source definitions

```
User scans barcode
    |
    v
[Filament Page] -- Alpine/Livewire --> [Service Layer] -- Saloon --> [External API]
    |                                       |
    v                                       v
[QZ Tray / WebHID]                    [Eloquent Models]
(local hardware)                      (MySQL database)
```

---

## Directory Map

```
app/
├── Console/Commands/          Artisan commands (import, export, validate)
├── Contracts/                 Interfaces for carriers, import sources, export destinations
├── DataTransferObjects/       Immutable DTOs for carrier communication
│   └── Shipping/              AddressData, RateRequest/Response, ShipRequest/Response, etc.
├── Enums/                     Deliverability, BoxSizeType, Role, carrier-specific enums
├── Exceptions/                Domain-specific exceptions
├── Filament/
│   ├── Concerns/              Shared traits (NotifiesUser)
│   ├── Pages/                 Custom workflow pages (Pack, Ship, DeviceSettings, etc.)
│   ├── Resources/             Standard Filament CRUD (Shipment, Package, Product, etc.)
│   └── Widgets/               Dashboard stats and charts
├── Http/Integrations/         Saloon API clients
│   ├── Amazon/                SP-API connector + requests
│   ├── Fedex/                 FedEx REST connector + requests
│   ├── Shopify/               GraphQL connector + requests
│   ├── Ups/                   UPS REST connector + requests
│   ├── USPS/                  USPS Web Tools connector + requests + responses
│   └── Concerns/              HasCachedAuthentication trait
├── Models/                    Eloquent models with Scout search
├── Policies/                  Authorization policies for resources
├── Providers/                 AppServiceProvider + Filament panel provider
└── Services/
    ├── Carriers/              CarrierRegistry + adapters (UspsAdapter, FedexAdapter, etc.)
    └── ShipmentImport/        Import orchestration, sources, field mapping, export
```

---

## Design Patterns

### 1. Adapter Pattern (Carriers)

Each shipping carrier (USPS, FedEx, UPS) has an adapter class that implements `CarrierAdapterInterface`. This gives every carrier the same API regardless of how different their underlying APIs are.

```
CarrierAdapterInterface
├── UspsAdapter      (USPS Web Tools REST API)
├── FedexAdapter     (FedEx REST API)
└── UpsAdapter       (UPS REST API)
```

**Interface methods:** `getRates()`, `createShipment()`, `cancelShipment()`, `isConfigured()`, `supportsMultiPackage()`

**Why this pattern:** Carrier APIs are wildly different (USPS has separate domestic/international endpoints, FedEx uses different package type codes, UPS has its own service code numbering). The adapter normalizes all of this behind a consistent interface, so the rest of the app doesn't care which carrier is being used.

Adapters are accessed through `CarrierRegistry`, a static singleton registry:
```php
$adapter = CarrierRegistry::get('USPS');
$rates = $adapter->getRates($rateRequest, $serviceCodes);
```

**Deviation from stock Laravel:** The registry pattern with static access is more common in enterprise Java than Laravel. A more "Laravel" approach would be binding adapters in the service container. The registry was chosen for simplicity since adapters are stateless singletons.

### 2. Strategy Pattern (Import Sources)

Order import uses the same strategy approach as carriers. Each sales channel (Amazon, Shopify, generic database) implements `ImportSourceInterface`, and optionally `ExportDestinationInterface` for bidirectional sync.

```
ImportSourceInterface + ExportDestinationInterface
├── AmazonSource     (SP-API: fetch orders, confirm shipments)
├── ShopifySource    (GraphQL: fetch orders, create fulfillments)
└── DatabaseSource   (ODBC/MySQL: query tables, update exported flag)
```

Sources are config-driven (`config/shipment-import.php`). Each source has a driver class, enabled flag, connection settings, field mappings, and export configuration. Adding a new source (e.g., WooCommerce) means creating a new class and adding a config entry - no changes to existing code.

**`ShipmentImportService`** orchestrates the import: resolves the source from config, calls `fetchShipments()`, validates/normalizes data, bulk-upserts shipments, creates items, and calls `markExported()`. It warms caches (channels, shipping methods, products) before processing to avoid N+1 queries.

**`PackageExportService`** handles the reverse: after a package is shipped, it looks up which export destinations to notify based on the shipment's channel (via `export_channel_map` in config), builds the export payload, and calls `exportPackage()` on the appropriate source.

### 3. DTO Pattern (Data Transfer Objects)

All data flowing between the service layer and carrier APIs uses readonly DTOs in `app/DataTransferObjects/Shipping/`:

| DTO | Purpose |
|-----|---------|
| `AddressData` | Normalized address with factory methods (`fromShipment()`, `fromConfig()`) |
| `RateRequest` / `RateResponse` | Rate shopping input/output |
| `ShipRequest` / `ShipResponse` | Label purchase input/output |
| `PackageData` | Box dimensions and weight |
| `CustomsItem` | International customs line items |
| `CancelResponse` | Label void result |
| `ManifestResponse` | SCAN form result |

**Why DTOs:** Carrier APIs return different JSON structures. DTOs normalize the data so the UI layer doesn't need to know whether a tracking number came from USPS's `internationalTrackingNumber` field or FedEx's `trackingIdList`. The `ShipResponse::success()` and `ShipResponse::failure()` factory methods make the success/error path explicit.

**Deviation from stock Laravel:** Most Laravel apps pass arrays or Eloquent models between layers. The readonly DTO approach is borrowed from DDD (Domain-Driven Design) and provides better type safety and IDE support.

### 4. Saloon for API Clients

External API integrations use [Saloon](https://docs.saloon.dev/) instead of raw Guzzle/HTTP facade. Each API has:

- A **Connector** class (authentication, base URL, retry logic)
- **Request** classes (one per endpoint)
- Optional **Response** classes (for complex response parsing)

```
Http/Integrations/USPS/
├── USPSConnector.php              OAuth2, retry 3x, sandbox support
├── Requests/
│   ├── Label.php                  POST domestic label
│   ├── InternationalLabel.php     POST international label
│   ├── ShippingOptions.php        POST rate quote
│   └── ...
└── Responses/
    ├── LabelResponse.php          Parses label PDF + tracking metadata
    └── ScanFormResponse.php       Parses SCAN form PDF
```

All connectors share a `HasCachedAuthentication` trait (`app/Http/Integrations/Concerns/`) that caches OAuth2 tokens in Laravel's cache with a safety margin before expiration. This avoids re-authenticating on every API call.

**Sandbox mode:** Every connector checks `SettingsService::get('sandbox_mode')` and switches between production and sandbox base URLs. The Amazon source also adjusts query parameters and uses hardcoded test data for sandbox exports.

**Deviation from stock Laravel:** Most Laravel apps use the `Http` facade for API calls. Saloon adds structure (typed requests, automatic retries, OAuth2 traits) that's valuable when integrating with multiple complex APIs.

### 5. Optimistic Locking (Package Shipping)

`Package::markShipped()` uses a conditional update to prevent race conditions:

```php
$updated = Package::where('id', $this->id)
    ->where('shipped', false)  // Only update if not already shipped
    ->update([...]);

if ($updated === 0) {
    throw new RuntimeException('Package was already shipped');
}
```

This prevents double-shipping if two users or processes try to ship the same package simultaneously. `Package::clearShipping()` uses the same pattern in reverse (checks `shipped = true` before voiding).

### 6. Config-Driven Architecture

The app uses two layers of configuration:

- **File config** (`config/shipment-import.php`, `config/services.php`, `config/shipping.php`) for settings that change per deployment
- **Database config** (`settings` table via `SettingsService`) for settings that change at runtime (sandbox mode, from address, feature flags)

`SettingsService` is a simple key-value store backed by the `Setting` model with 1-hour cache. It supports typed values (string, integer, boolean, array) and encryption for sensitive values.

---

## Filament UI Structure

### Standard CRUD Resources

These follow stock Filament patterns (auto-discovered from `app/Filament/Resources/`):

| Resource | Model | Notes |
|----------|-------|-------|
| ShipmentResource | Shipment | Scout search, relation managers for items + packages |
| PackageResource | Package | Scout search, custom view page with label display |
| ProductResource | Product | Scout search on SKU/barcode |
| BoxSizeResource | BoxSize | Code field for barcode scanning |
| CarrierResource | Carrier | Nested under Resources/Carriers/ subdirectory |
| CarrierServiceResource | CarrierService | Pivot to ShippingMethod and BoxSize |
| ShippingMethodResource | ShippingMethod | Relation managers for services + aliases |
| ChannelResource | Channel | Relation managers for aliases |
| UserResource | User | Role enum for authorization |

### Custom Workflow Pages

These are where the app deviates most from stock Filament. Instead of CRUD operations, these pages implement multi-step workflows with hardware integration:

**Pack** (`/pack/{shipment_id?}`) - The core packing workflow
- Uses Alpine.js for client-side state (packing items, packed counts, transparency codes)
- Reads weight from USB scale via WebHID
- Scans barcodes for box codes, product SKUs, and command codes
- Two ship modes: manual (redirect to Ship page) or auto-ship (rate shop + cheapest + print)
- Auto-ship is admin-only

**Ship** (`/ship/{package_id}`) - Rate selection and label purchase
- Not in the sidebar navigation (accessed via Pack page redirect)
- Fetches rates from all configured carriers via `ShippingRateService`
- Shows delivery date warnings if shipment has a `deliver_by` deadline
- Calls carrier adapter to create shipment, then triggers label print via QZ Tray

**DeviceSettings** (`/app/device-settings`) - Printer and scale configuration
- Stores device IDs in browser `localStorage`, not the database
- Each workstation can have different printers and scales

**EndOfDay** - USPS SCAN form (manifest) generation and printing

**UpdateWeight** - Scan product barcode, read scale, update product weight

**Settings** - Runtime app settings (from address, feature flags, sandbox mode)

**Why custom pages instead of Filament actions/modals:** The Pack and Ship workflows involve real-time hardware interaction (scale reads, barcode scans, label printing) that doesn't fit into Filament's modal-based action system. These workflows need to be full pages with persistent Alpine state and WebSocket connections to QZ Tray.

---

## Hardware Integration

This is the most unusual part of the app compared to typical web applications.

### QZ Tray (Label Printing)

[QZ Tray](https://qz.io/) is a local desktop agent that bridges web browsers to USB/network printers. The app connects to it via WebSocket (`wss://localhost:8181`).

**Security:** QZ Tray requires a signed certificate to prevent untrusted websites from printing. The app serves a public certificate at `/qz-certificate.pem` and signs print requests via a `/qz/sign` endpoint using a private key stored in `storage/app/private/`.

**Flow:** Label created → base64 PDF dispatched as Livewire event → Alpine catches event → calls `window.printLabel()` → QZ Tray sends PDF to configured printer

**Deviation:** Most shipping apps use cloud print services (PrintNode, Google Cloud Print). QZ Tray was chosen for zero-latency local printing without cloud dependencies.

### WebHID (USB Scale)

The [WebHID API](https://developer.mozilla.org/en-US/docs/Web/API/WebHID_API) (Chrome/Edge only) provides direct USB access from the browser. The app reads weight data from USB scales.

**Protocol:** USB HID report parsing (status byte, unit byte, scale factor, 16-bit weight). Handled by `ScaleUtils.parseScaleData()` in the scale script component.

**Flow:** Scale sends HID input report → browser fires `inputreport` event → Alpine handler parses weight → updates weight field in real time

**Device config:** Scale vendor/product IDs are stored in `localStorage` per browser, configured via the DeviceSettings page.

**Deviation:** Most apps use serial port adapters or cloud-connected scales. WebHID eliminates all middleware.

---

## Data Flow: End-to-End

### Import → Pack → Ship → Export

```
1. IMPORT
   artisan shipments:import --source=amazon
   └── AmazonSource.fetchShipments() ──► SP-API
   └── ShipmentImportService.import()
       ├── Validate & normalize addresses
       ├── Resolve channels & shipping methods (via aliases)
       ├── Upsert Shipments (batch of 100)
       ├── Create ShipmentItems
       └── Auto-create Products if enabled

2. PACK (/pack/{reference})
   └── Scan box code → set dimensions (Alpine, from CacheService)
   └── Scan products → increment packed count (Alpine)
   └── Read scale → set weight (WebHID, Alpine)
   └── Click Ship → Pack::ship() Livewire call
       └── Creates Package + PackageItems in transaction
       └── If auto-ship: skip to step 3 inline

3. SHIP (/ship/{package_id})
   └── ShippingRateService::getShippingRates()
       └── For each carrier with matching services:
           └── CarrierRegistry::get(carrier) → adapter.getRates()
   └── User selects rate → Ship::ship()
       └── CarrierRegistry::get(carrier) → adapter.createShipment()
       └── Package::markShipped() (optimistic lock)
       └── Dispatch print-label event → QZ Tray

4. EXPORT (automatic after ship, or artisan packages:export)
   └── PackageExportService::exportPackage()
       └── Lookup channel → export_channel_map → source names
       └── AmazonSource::exportPackage() → SP-API confirmShipment
       └── Package.exported = true
```

### Address Validation (Parallel Flow)

```
artisan shipments:validate
└── Finds unvalidated US shipments
└── AddressValidationService::validate()
    └── USPSConnector → /addresses/v3/address
    └── Updates shipment with validated_* fields
    └── Sets deliverability enum (Yes/Maybe/No)
```

---

## Model Relationships

```
Channel ──┐
           ├──► Shipment ──► ShipmentItem ──► Product
ShippingMethod ─┘    │                            │
                     │                            │
                     ▼                            ▼
                  Package ──► PackageItem ─────────┘
                     │
                     ├──► BoxSize
                     ├──► Manifest
                     └──► User (shipped_by)

Carrier ──► CarrierService ◄──► ShippingMethod
                            ◄──► BoxSize

Channel ──► ChannelAlias
ShippingMethod ──► ShippingMethodAlias
```

**Key relationship:** `Shipment` has a composite unique index on `(channel_id, shipment_reference)`. This allows upserts during import - if the same order is imported twice, it updates rather than duplicates. The `channel_id` can be null for orders whose channel couldn't be resolved (shown on the Unmapped Channel References page).

---

## Authentication & Authorization

- **Authentication:** Standard Laravel auth with Filament's login page
- **Authorization:** Role-based via the `Role` enum on User
  - `Admin` - Full access, auto-ship enabled
  - `Manager` - Can access other users' packages (reprint/cancel)
  - `User` - Standard packing/shipping, own packages only
  - `Viewer` - Read-only access
- **Role checking:** `$user->role->isAtLeast(Role::User)` - enum method that compares role hierarchy
- **Policies:** Each resource has a policy checking the user's role

---

## Testing Approach

- **Framework:** Pest 4
- **Structure:** `tests/Feature/` for integration tests, `tests/Unit/` for service tests
- **API mocking:** Saloon's `Saloon::fake()` for HTTP mocking with `MockResponse`
- **Database:** Uses factories and database transactions for isolation
- **Key test files:** AmazonImportExportTest, ShipmentImportServiceTest, page tests, model tests

---

## Summary of Deviations from Stock Filament/Laravel

| Area | Stock Approach | This App's Approach | Reason |
|------|---------------|---------------------|--------|
| API clients | `Http` facade / Guzzle | Saloon connectors | OAuth2, retries, typed requests for 5+ APIs |
| Data transfer | Arrays or Eloquent models | Readonly DTOs | Type safety across carrier boundaries |
| Carrier integration | Single carrier or simple wrapper | Adapter pattern + registry | 3 carriers with very different APIs |
| Import sources | Direct database queries | Strategy pattern + config-driven sources | Pluggable sources (Amazon, Shopify, ODBC) |
| Workflow pages | Filament CRUD / actions | Custom pages with Alpine state | Hardware integration, multi-step workflows |
| Printing | Cloud print API | QZ Tray (local WebSocket) | Zero-latency, no cloud dependency |
| Scale input | Manual entry or serial adapter | WebHID (browser-native USB) | No middleware, direct hardware access |
| Device config | Server-side per-user settings | Browser localStorage | Per-workstation, not per-user |
| Services | Instance methods via DI | Mix of static and instance | Static for stateless utilities (Cache, Settings) |
| Race conditions | Database locks or queues | Optimistic locking on Package | Simple, no deadlocks, clear error messages |
| Settings | `.env` / config files only | Two-tier: file config + database SettingsService | Runtime-changeable settings (sandbox mode, addresses) |
