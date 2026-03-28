# Feature Roadmap

Planned features and improvements for PolyBag, organized by commercial launch priority.

## Background

This app was originally built as an internal tool for VitaminLife, a supplement company with 20,000+ unique SKUs. The scan-and-validate packing workflow was designed for that environment, where package contents, dimensions, and weight aren't known until each order is physically packed. Most businesses in the target market (100–5,000 packages/day) will have fewer SKUs and more predictable shipments, so the product needs to support both manual pack-and-ship and automated batch workflows.

## Completed

- [x] **Delivery date estimates** — Get estimated delivery dates from carrier APIs and filter out shipping methods that won't arrive by the shipment's deliver-by date. Show all options with a warning if nothing meets the deadline.
- [x] **Filter rates by carrier services** — On the ship page, only show rates for carrier services that are configured for the shipment's shipping method.
- [x] **Pack page loading state** — Show a spinner and disable form inputs while waiting for auto-ship response or ship page navigation, preventing duplicate submissions.
- [x] **Shopify GraphQL** — Import orders and export fulfillments via Shopify Admin API.
- [x] **Amazon SP-API** — Import orders and export fulfillments via Amazon Selling Partner API.
- [x] **UPS** — Rate quotes, label generation, tracking.

---

## Phase 1: Commercial Viability (Build Before Launch)

### 1.1 Laravel Event System — COMPLETED (2026-02-24)

8 domain events dispatched at model/service boundaries, queued `ExportShippedPackage` listener replaces inline export calls, `EventServiceProvider` registered. All events are infrastructure for webhooks (2.1), audit logging (1.4), and future listeners.

---

### 1.2 Batch Label Generation — COMPLETED (2026-02-25)

"Batch Ship" bulk action on the Shipments table. User selects shipments, picks a box size, and the system generates all labels in the background using `Bus::batch()` with `allowFailures()`. Weight is calculated as `BoxSize.empty_weight + SUM(item.quantity * product.weight)`. Dimensions come from the selected box size. Label format/DPI read from browser localStorage.

Jobs run via `GenerateLabelJob` (one per package), which calls `LabelGenerationService::generateLabel()`. Failures are domain-level — the job catches exceptions and marks items as failed rather than re-throwing, so one carrier error doesn't cancel the whole batch. Failed packages are cleaned up (deleted).

Results page (`/batch-ship/{id}`) polls every 2 seconds via `wire:poll`, showing a progress bar, success/failed/pending counts, total cost, and per-shipment results. "Print All Labels" button sends all successful labels to QZ Tray sequentially.

Also added `resolvePreSelectedRate()` to `CarrierAdapterInterface` — USPS fetches rates for the pre-selected service code to find the cheapest variant (e.g. cubic vs non-cubic pricing), while FedEx and UPS pass through unchanged.

**Files added:** `LabelBatchStatus` + `LabelBatchItemStatus` enums, `LabelBatch` + `LabelBatchItem` models + migrations + factories, `GenerateLabelJob`, `BatchLabelService`, `BatchValidationResult` DTO, `BatchShipResults` page + Blade view, batch localStorage Blade component, 3 test files.

**Files modified:** `CarrierAdapterInterface` (added `resolvePreSelectedRate`), `UspsAdapter` / `FedexAdapter` / `UpsAdapter` (implemented it), `LabelGenerationService` (calls `resolvePreSelectedRate` for rule-based rates), `ShipmentResource` (bulk action), `qz-tray.blade.php` (batch print event), 4 test files (updated mock adapters).

---

### 1.3 Shipping Rules Engine + LabelGenerationService — COMPLETED (2026-02-25)

Lightweight shipping rules engine with two action types: `UseService` (pre-assign a carrier service, skip rate shopping) and `ExcludeService` (remove a service from rate results). Rules are scoped to a shipping method or global (null = all methods), evaluated in priority order.

Also extracted shared label generation logic from Pack.php and Ship.php into `LabelGenerationService::generateLabel()`, and moved `getDeliverByDate()` from Ship.php to the Shipment model. The service handles rule evaluation, rate shopping (with deadline-aware selection), and carrier API calls, but does NOT call `markShipped()` — the caller handles that since cleanup behavior differs.

**Conditional rules (2026-02-25):** Activated the `conditions` JSON column with 7 condition types: weight (lbs), order value ($), item count, destination zone (Continental US / Non-Continental / Territories / International), destination state (in/not_in), sales channel, and residential/commercial. All conditions within a rule use AND logic; use multiple rules for OR. `DestinationZone` enum defines zone membership. `RuleEvaluator` accepts an optional `Package` param for weight checks. Replaced standalone `ShippingRuleResource` with a `ShippingRulesRelationManager` on `ShippingMethodResource` — drag-and-drop reordering, Builder-based condition editor in slideOver.

**Files added:** `ShippingRuleAction` enum, `LabelResult` + `RuleEvaluationResult` DTOs, `ShippingRule` model + migration + factory, `RuleEvaluator` service, `LabelGenerationService`, `DestinationZone` enum, `ShippingRulesRelationManager`, 33 tests.

**Files modified:** `Shipment.php` (added `getDeliverByDate()`), `Pack.php` (autoShip uses LabelGenerationService), `Ship.php` (removed getDeliverByDate, added rule filtering/pre-selection in mount), `CarrierRegistry.php` (added `registerInstance()` and `reset()` for testing), `ShippingMethod.php` (added `shippingRules()` relationship), `ShippingMethodResource.php` (added relation manager), `LabelGenerationService.php` (passes Package to RuleEvaluator).

**Files removed:** `ShippingRuleResource` + pages (replaced by RelationManager).

---

### 1.4 Reporting, Analytics & Rate Logging — COMPLETED (2026-02-25)

Rate logging infrastructure persists all rate quotes per package with a `selected` flag. `ShippingRateService` logs rates after every rate shop (try-catch wrapped so logging failures never break shipping). Ship page and `LabelGenerationService` mark the chosen rate.

Dashboard widgets: StatsOverview (pending, shipped today/week/month, shipping cost with trend), ShippedShipmentsChart (bar, half width), CarrierBreakdownChart (doughnut, half width), CostPerPackageTrend (30-day line, half width), ExceptionsWidget (undeliverable shipments, failed batches, unmapped references with links).

Four reporting pages in `app/Filament/Pages/Reports/` under a "Reports" nav group (Manager role minimum): Shipping Cost Analysis (filterable table with summary stats), Rate Comparison (selected vs cheapest rate, potential savings), Volume Report (grouped by channel/shipping method/month), Packing Validation (weight mismatches >10%, batch failures, shipped-despite-validation-issues).

**Still TODO:** CSV/Excel export on report tables, delivery confidence scoring (needs tracking data from Phase 2.3)

---

### 1.5 Advanced Filtering — COMPLETED (2026-02-25)

Shipment filters: created date range (existed), deliver-by date range, shipping method (existed), channel (existed), shipped status (existed), deliverability (existed), destination state (searchable select from existing data), order value range. Package filters: shipped status (existed), carrier (existed), exported (existed), service, manifested, label format (PDF/ZPL), shipped date range, cost range. Both resources use collapsible above-content filter layout.

### 1.6 Audit Trail — COMPLETED (2026-03-18)

`AuditLog` model with polymorphic `auditable` relation, `AuditAction` enum (12 cases), and `AuditLog::record()` static helper that resolves user/IP from context. Event-driven logging via `AuditLogListener` (auto-discovered `handle*` methods) for all 8 domain events. `AuditableObserver` for CRUD on config models (User, Carrier, CarrierService, Location, BoxSize, ShippingMethod, ShippingRule, Product). `SettingObserver` for encrypted settings with value masking. `BatchStarted` action logged directly in `BatchLabelService`.

Shipping source tracking (Pack, Ship, Manual Ship, Batch Ship) via Referer header and LabelBatchItem lookup. Batch-shipped packages attribute the user from `shipped_by_user_id` instead of `auth()->id()` (null in queue context).

Admin-only Filament resource with filters by action, user, model type, and date range. Searchable by record ID. View page links to the corresponding model's resource page (view or edit). Configurable retention via App Settings ("Data Retention" section, default 90 days, 0 = keep forever). Scheduled `audit:purge` command runs daily at 01:00.

---

### 1.7 Database Notifications for Batch & Import Operations — COMPLETED (2026-03-19)

Filament `databaseNotifications()` enabled in AppPanelProvider (bell icon in topbar). Two Laravel notification classes for future email/Slack extensibility:

- **`BatchLabelCompleted`** — sent to initiating user (`LabelBatch.user_id`) from `BatchLabelService::finally()`. Status-aware title, icon, color, and "View Results" action linking to batch results page.
- **`ImportCompleted`** — sent to all active admin users from `ShipmentImportService`. Covers both successful imports (stats summary) and configuration validation failures (error details).

Old notifications cleaned up by `audit:purge` command (read notifications >30 days, all notifications >90 days).

---

### 1.8 Data Retention & Archiving — COMPLETED (2026-03-19)

Renamed `audit:purge` to `data:purge` — now also purges rate quotes older than configurable days (default 60). Rate quote retention is configurable via App Settings alongside audit log retention.

`shipments:archive` command exports old fully-shipped shipments/packages to CSV (`storage/app/archives/`) and deletes from active tables. Respects FK order (package_items → rate_quotes → packages → shipment_items → shipments). Default OFF — enabled via "Shipment Archiving" toggle in App Settings. Scheduled weekly on Sundays at 02:00 (exits early if disabled). `--dry-run` flag previews without deleting. `daily_shipping_stats` preserves all historical reporting after archival.

Database Health widget on dashboard (Admin-only) shows row counts for shipments, packages, rate quotes, and audit logs with color-coded thresholds (green <100k, yellow 100k–500k, red >500k). Cached 1 hour.

No soft deletes — a future "void" feature will handle hiding shipments/packages from stats while keeping them searchable.

**Later (Phase 3):**

- Configurable retention policies per customer
- Automated archival to S3/external storage
- Archive viewer for historical lookups

### 1.9 Setup Wizard

Guided first-run experience for new installations. Currently, a new customer has to discover the correct configuration order themselves (Settings → Carriers → Box Sizes → Shipping Methods → Import Sources). A setup wizard reduces support burden and time-to-value.

**Steps:**

1. Company information (name, ship-from address → creates default Location)
2. Carrier setup (enter API credentials, enable services — test connection inline)
3. Box sizes (create at least one)
4. Shipping methods & channels (create defaults or import from source)
5. Import source configuration (Database, Shopify, or Amazon — skip if manual entry)
6. Device settings (printer, scale — can be deferred to workstation level)

**Implementation:**

- Multi-step Filament page (not a resource) with validation per step
- `SetupComplete` flag in Settings — wizard auto-redirects until complete
- Skippable for advanced users (link to "skip wizard, configure manually")
- Admin-only; other roles see a "setup in progress" message until complete

---

## Phase 2: Competitive Differentiation (Build After Early Adoption)

### 2.1 REST API, Outbound Webhooks & Integration Templates

The primary integration strategy: expose PolyBag's functionality via a well-documented REST API and push shipping events to external systems via outbound webhooks. This replaces the need to build per-ERP polling sources — the customer or their integrator builds the connector on their side (NetSuite RESTlet, Zapier workflow, custom script, etc.).

**Current state:** Inbound integrations exist for direct database connections (with SSH tunnel support), Shopify GraphQL, and Amazon SP-API. The `api.php` routes file is empty. There is no outbound webhook system — export currently works via database UPDATE queries or platform-specific API calls (Shopify fulfillments, Amazon feed submissions).

#### REST API

Expose core operations so external systems can push data in and pull results out. Auth via Laravel Sanctum (API token per user).

**Endpoints:**

- `POST /api/shipments` — create a shipment (replaces inbound webhook/polling need)
- `GET /api/shipments/{id}` — get shipment details and status
- `GET /api/shipments/{id}/rates` — get shipping rates
- `POST /api/shipments/{id}/ship` — purchase label
- `GET /api/packages/{id}` — get package with tracking info
- `DELETE /api/packages/{id}` — cancel/void label
- `POST /api/webhooks` — register a webhook subscription
- `GET /api/webhooks` — list registered webhooks
- `DELETE /api/webhooks/{id}` — remove a webhook subscription

The API accepts PolyBag's own data format — no need to parse arbitrary external schemas. The complexity of mapping fields from an ERP stays on the customer's side, where they know their own data model.

#### Outbound Webhooks

Push shipping events to customer-configured endpoints. This is how tracking data, label confirmations, and costs get sent back to external systems without building per-ERP export adapters.

**Model:** `Webhook` — url, secret (HMAC-SHA256 signing), subscribed_events (JSON array), active flag, headers (JSON, for custom auth headers the receiving system requires)

**Events:**

- `package.shipped` — label purchased, includes tracking number, carrier, service, cost, dimensions, weight
- `package.voided` — label voided/cancelled
- `manifest.created` — end-of-day manifest generated
- `shipment.created` — new shipment imported/created
- `tracking.updated` — delivery status changed (depends on Phase 2.2)

**Payload mapping:** Each webhook subscription can optionally define a payload template that maps PolyBag field names to the field names the receiving system expects. Default payload uses PolyBag's standard field names.

**Delivery:**

- `DispatchWebhook` queued job: POST with JSON payload, HMAC-SHA256 signature in `X-Signature-256` header
- Retry with exponential backoff on failure (3 attempts, 30s/120s/600s)
- Webhook delivery log (`WebhookDelivery` model): event, url, status code, response body, duration — for debugging failed deliveries
- Auto-disable after N consecutive failures with notification to admin

#### Integration Templates

Saved configurations for known external systems. Once an integration is working for one customer, save it as a template so the next customer using the same system selects it and fills in their endpoint URL and credentials.

A template defines:

- Webhook URL pattern (with placeholder for customer-specific hostname)
- Auth method and header configuration
- Payload field mapping
- Which events to subscribe to
- Setup instructions specific to that system

**Built-in templates (build as customers need them):**

- NetSuite (RESTlet endpoint for receiving shipment confirmations)
- Odoo (webhook receiver or XML-RPC)
- WooCommerce (REST API order update)
- Generic (HMAC-signed JSON POST — works with Zapier, Make, n8n, custom endpoints)

Templates are stored as config/JSON — not a database model. New templates can be added without code changes.

#### Dedicated Import Sources (only when justified)

The REST API eliminates the need for most inbound polling sources. Build dedicated sources only for platforms large enough to warrant it or that require non-standard protocols:

- **WooCommerce** — large market share, straightforward REST API, good candidate for a dedicated source
- **Shopify GraphQL** — already built (required by Shopify for new apps)
- **Amazon SP-API** — already built (complex auth, XML feeds)

**Depends on:** Event system (Phase 1.1)

---

### 2.2 Tracking & Delivery Status

Monitor post-shipment delivery status. Focus on exception detection rather than full delivery lifecycle tracking — the customer's ERP or storefront typically handles customer-facing tracking. Promoted from "nice to have" — exception detection (lost/stuck packages) is a daily operational need for any shipping operation.

**Implementation:**

- Add `tracking_status` enum to packages: `pre_transit`, `in_transit`, `out_for_delivery`, `delivered`, `exception`, `returned`
- Add `tracking_updated_at`, `delivered_at` timestamps
- Scheduled job (every 4–6 hours): check status on shipped-but-not-delivered packages
- Dashboard widget: packages with exceptions, packages stuck in pre-transit > 48 hours
- Fire `TrackingStatusUpdated` event on status changes
- Database notification when exceptions are detected (leverages Phase 1.8 infrastructure)
- Later: inbound carrier webhooks for real-time updates (USPS Informed Visibility, FedEx Track API, UPS Quantum View Notify)

**Scope note:** The core value here is exception detection (lost/stuck packages), not full delivery visibility. This also provides data for the delivery confidence scoring feature in Phase 3.

---

### 2.3 Returns Management

Generate return labels, track return status, link returns to original shipments. This is one of the biggest gaps in the competitive landscape — most shipping platforms either ignore returns or bolt them on as an afterthought.

**Models:**

- `Return` model: package_id (original), return_tracking_number, carrier, status, reason, notes
- Status enum: `label_created` → `in_transit` → `received` → `processed`

**Features:**

- Generate return label from original package (swap origin/destination, use carrier-specific return products)
- Carrier-specific return products: USPS Return Service, FedEx Ground Return / Express Return, UPS Return Service (RS1, RS3)
- Return reason tracking (wrong item, damaged, customer changed mind, other)
- Filament resource with status filters
- Dashboard widget: returns pending, returns received this week
- Return analytics: return rate by product, by channel, by reason

---

### 2.4 Customizable Dashboard

Let users personalize their dashboard — choose which widgets to display, reorder them, and resize them. Lower priority — the current fixed dashboard is functional for most users.

- Toggle widget visibility per user
- Drag-and-drop widget positioning and resizing
- Per-user layout persistence (database, keyed by user ID)
- Consider Filament's built-in widget configuration or a custom dashboard page that replaces the default

---

## Phase 3: Platform Scale (Build When Product Has Traction)

### 3.1 Multi-Origin & International Shipping

**Foundations (completed 2026-03-04):** `Location` model + migration, `location_id` on packages and daily_shipping_stats, `AddressData::fromLocation()`, `originCountry` on `RateRequest`, `AddressValidationInterface` dispatcher pattern with `UspsAddressValidator`. Origin address now resolves through the Location model instead of raw settings.

**Remaining work:**

- Assign shipments to locations (manually or by rule)
- Location picker on Pack/Ship pages for multi-origin workflow
- Per-location carrier availability (carrier-location many-to-many)
- `CarrierAccount` model with per-location credentials (e.g. different USPS CRIDs per warehouse)
- Unit conversion layer in carrier adapters (kg/cm for non-US origins — currently hardcoded LB/IN/USD)
- Multi-currency support (add currency to Location model)
- `DestinationZone` refactoring for international zone logic (currently US state lists only)
- Additional address validators (Canada Post, Royal Mail, etc. — just register a new `AddressValidationInterface` implementation)
- Automated routing based on destination proximity or inventory availability

### 3.2 Delivery Confidence Scoring

- Requires: rate logging (Phase 1.4) + tracking data (Phase 2.3) + months of shipping history
- Compare quoted delivery dates against actual delivery dates
- Calculate reliability scores per carrier/service/destination zone
- Enable rules like "pick cheapest with 95% confidence of delivery within X days" (confidence threshold adjustable)
- Needs thousands of data points per lane for statistical significance

### 3.3 Plugin Architecture

- Formalize existing interface patterns (`CarrierAdapterInterface`, `ImportSourceInterface`, `ExportDestinationInterface`) into a plugin registration system
- Leverage Filament's plugin architecture for UI extensions
- Plugins can register: carrier adapters, import/export sources, Filament pages/resources/widgets, event listeners, shipping rules
- Distributed as Composer packages with Laravel auto-discovery
- Enables community/third-party carrier integrations (DHL, OnTrac, regional carriers) without forking core

### 3.4 White-Label / Theming

- Custom branding (logo, colors, app name) via config or settings
- Targeted at 3PLs who present the tool as their own to clients
- Filament supports theme customization via CSS/Tailwind

---

## Carrier Enhancements

Carrier APIs support many more options than currently implemented. This section tracks specific enhancements, prioritized by real-world shipping impact.

### Phase A: Saturday Delivery (FedEx + UPS) — COMPLETED (2026-03-16)

Saturday delivery flag on rate and ship requests is gated to Thursdays only (`RateRequest::isSaturdayDeliveryApplicable()`). On Thursdays, both FedEx and UPS include the flag; if FedEx rejects it for the destination (e.g. `SERVICE.PACKAGECOMBINATION.INVALID` or `ORGORDEST.SPECIALSERVICES.NOTALLOWED`), the adapter retries without it. UPS has the same retry pattern on the ship side. Saturday delivery removed from UPS rate requests (not needed there).

**Future:** If we add more conditional FedEx services, consider querying the [FedEx Service Availability API](https://developer.fedex.com/api/en-at/catalog/service-availability/docs.html) instead of the try/retry pattern. One Rate (Phase B) uses a separate rate request rather than retry, so the current Saturday retry pattern is still fine.

### Phase B: FedEx One Rate — COMPLETED (2026-03-17)

Flat-rate Express pricing for FedEx-branded packaging (envelope, pak, small/medium/large/extra-large box). Up to 50 lbs, domestic US only. Eligible services: First Overnight, Priority Overnight, Standard Overnight, 2Day AM, 2Day, Express Saver.

When a package uses a BoxSize with a non-`YOUR_PACKAGING` `fedex_package_type`, the adapter sends a second rate request with `FEDEX_ONE_RATE` special service and the FedEx packaging type. One Rate results are merged with standard rates, suffixed with "(One Rate)". Failures are non-fatal (logged as warning, standard rates still display). On the ship side, `isOneRate` and `fedexPackageType` are carried in rate metadata to set `packagingType` and add `FEDEX_ONE_RATE` to special services. Saturday retry preserves `FEDEX_ONE_RATE` when removing `SATURDAY_DELIVERY`.

**Own packaging:** Tested against live FedEx API — `YOUR_PACKAGING` with `FEDEX_ONE_RATE` returns `SERVICE.PACKAGECOMBINATION.INVALID`. FedEx One Rate requires FedEx-branded packaging. The adapter excludes `YOUR_PACKAGING` from eligibility.

**Sandbox note:** FedEx sandbox does not support One Rate requests (returns errors). One Rate only works against the production API.

### Phase C: FedEx Ground Economy (SmartPost)

FedEx Ground Economy (service type `SMART_POST`) — low-cost residential delivery via FedEx Ground + USPS last-mile. Requires `smartPostInfoDetail` in both rate and ship requests.

**Key fields:**

- **`hubId`** — 4-digit FedEx hub ID. List available in FedEx docs. Add a hub selector to Location settings (per-origin).
- **`indicia`** — weight-based:
  - Under 1 lb: `PRESORTED_STANDARD` (requires `ancillaryEndorsement` — investigate valid values: `ADDRESS_CORRECTION`, `CARRIER_LEAVE_IF_NO_RESPONSE`, `CHANGE_SERVICE`, `FORWARDING_SERVICE`, `RETURN_SERVICE`)
  - 1 lb and over: `PARCEL_SELECT` (no endorsement needed)

**Implementation:**

- Add `fedex_hub_id` to Location model/settings
- In `FedexAdapter`, detect `SMART_POST` service type and build `smartPostInfoDetail` with weight-based indicia
- Rate request: include `smartPostInfoDetail` when Ground Economy services are in the requested service codes
- Ship request: include `smartPostInfoDetail` when selected rate is Ground Economy
- Carrier service seeder: add `SMART_POST` to FedEx service codes

**Note:** FedEx sandbox account `740561073` supports Ground Economy. New developer portal accounts may have issues — see FedEx support notes from 2026-03-23.

---

### Phase D (was C): USPS Rate Indicator Optimization

Currently, rate shopping always works (queries USPS Shipping Options API for valid mailClass + rateIndicator pairs). The optimization is skipping rate shopping for known-cheap combinations.

**Problem:** The cheapest rateIndicator (SP, cubic, etc.) varies by weight, volume, and shipping zone. No static lookup table works universally.

**Approach:** Build a lookup table from historical rate data (already captured by `RateQuoteLogger`). As volume increases, the system accumulates data on which rateIndicator won for each weight/zone bucket. Use this for "quick ship" scenarios; fall back to rate shopping when uncertain.

**Not recommended:** Binary search job against USPS API — zone-dependent pricing makes the thresholds non-uniform. Better to learn from real shipping data.

### Phase E (was D): Extra Services Model

USPS supports many optional services: insurance, signature required, certified mail, hazmat, Sunday delivery, etc. Other carriers have similar add-ons (FedEx delivery confirmation, UPS declared value, etc.).

**Current state:** No data model for per-package extra services. Saturday delivery lives on ShippingMethod, which is fine for a single flag.

**When to build:** Once 3+ extra services need support. Until then, individual flags on ShippingMethod or shipping rule actions are sufficient.

**Proposed model (when needed):**
- `ExtraService` — carrier, service_code, name, description, requires_value (bool)
- `package_extra_services` pivot — package_id, extra_service_id, value (nullable, e.g. insurance amount)
- `carrier_service_extra_services` pivot — which extra services are available for which carrier services
- UI: multi-select on Ship page and in shipping rules

---

## Carriers

- [x] **USPS** — Domestic and international rates, labels, address validation, SCAN forms
- [x] **FedEx** — Domestic and international rates, labels
- [x] **UPS** — Rates, labels
- [ ] **DHL** — International shipping focus (Phase 3 or plugin)

## WMS (Warehouse Management) — Future Direction

May or may not be in scope depending on product direction and customer demand.

- [ ] **Inventory tracking** — Track stock levels by product/SKU, receive inventory, adjust quantities
- [ ] **Pick list generation** — Generate pick lists from pending shipments, optimized by warehouse location
- [ ] **Pack/ship workflow improvements** — Tie inventory into the existing pack flow, decrement stock on ship

---

## Authentication & Security

- [x] **Password policy** — configurable min length, mixed case, numbers, symbols via Settings
- [x] **Password expiration** — configurable days, forced password change on login
- [x] **Google SSO** — "Sign in with Google" via Laravel Socialite, admin pre-creates users with matching email
- [x] **SSO-only users** — nullable password, users can only sign in via SSO
- [x] **Email + username login** — single field auto-detects, both supported
- **Login rate limiting** — Filament has basic throttling, but needs tuning for public-facing deployments
- **MFA** — optional TOTP (Google Authenticator) for local auth accounts
- **Additional SSO providers** — Microsoft, Okta, generic OIDC/SAML. Architecture supports adding providers via Socialite drivers + Settings toggle. Google implementation serves as template.
- **Automated Google OAuth client provisioning** — Script to create per-customer OAuth client IDs via Google Cloud API (`gcloud`). Currently manual (30 seconds per customer in Google Console). Automate when onboarding volume justifies it.

## OAuth 2.0 Authorization Code Flow for Integrations

All external API integrations currently use client credentials flow, which requires customers to create developer accounts and manually paste API keys. The authorization code flow replaces this with a "Connect" button that redirects to the provider's auth page and returns with a token — dramatically simpler onboarding.

**Implementation:**

- Redirect-based OAuth flow: Settings page "Connect" button → provider auth page → callback URL → store tokens
- Token refresh handled automatically (all providers support refresh tokens)
- "Disconnect" button revokes tokens and clears stored credentials
- Callback route per provider (e.g. `/oauth/usps/callback`, `/oauth/shopify/callback`)
- Stored tokens encrypted in Settings (existing encryption infrastructure)

**Integrations:**

| Provider | Current Auth | OAuth Support | Priority | Notes |
|----------|-------------|---------------|----------|-------|
| **Shopify** | Client credentials (custom app) | OAuth 2.0 (standard for Shopify apps) | High | Biggest onboarding friction — merchants must create a custom app in Shopify admin |
| **Amazon SP-API** | Client credentials + refresh token | OAuth 2.0 (LWA) | High | Current setup requires IAM role, developer registration, manual refresh token |
| **USPS** | Client credentials | OAuth 2.0 (authorization code + refresh token) | Medium | Client credentials works fine but auth code flow is available per USPS OAuth v3 spec |
| **FedEx** | Client credentials | OAuth 2.0 | Medium | Auth code flow mainly benefits hosted SaaS (single developer account, customers authorize through it) |
| **UPS** | Client credentials | OAuth 2.0 | Medium | Same as FedEx — most useful for SaaS model |

**Deployment considerations:**

- On-prem/standalone: client credentials may still be simpler (no callback URL needed). Keep both flows available.
- SaaS/hosted: authorization code flow preferred. Single developer account per carrier, customers connect via OAuth.
- Per-tenant callback URLs needed for multi-tenant deployments

## Data Privacy & Compliance

Shipping data contains recipient PII (names, addresses, phone, email). Different data sources have different retention requirements.

**PII Purge:**

- `shipments:purge-pii` command — strips PII from shipped shipments older than a configurable period (null out name, address, email, phone). Preserves the shipment/package structure, costs, tracking numbers, and carrier data for reporting.
- Configurable retention period per channel or globally (e.g. Amazon data purged after 90 days, other channels after 12 months)
- Archive CSVs (from `shipments:archive`) contain PII — need their own retention policy or should be encrypted at rest on disk

**Amazon SP-API Compliance:**

- Amazon's data protection policy requires deletion of SP-API data within 30 days of it no longer being needed
- PII purge should run automatically for Amazon-sourced shipments after a configurable window (default 90 days post-ship)
- On SP-API disconnection (credentials removed), purge all Amazon-sourced PII

**Data Export (GDPR right of access):**

- Ability to export all shipment data associated with a recipient email or name
- Admin-accessible, returns CSV

**Data Deletion Requests:**

- Ability to purge a specific recipient's PII across all shipments on request
- Logs the deletion in audit trail

---

## Technical Debt & Polish

Address alongside the roadmap:

- **Test coverage:** Pest is configured but coverage needs expansion. Carrier adapters, import service, and rate shopping should have thorough test suites before adding more complexity.
- **Error handling:** Carrier API failures should be graceful with user-friendly messages. Consider a circuit breaker pattern for carrier APIs that are down.
- **Documentation:** User-facing (how to configure carriers, set up import sources) and developer-facing (how to add a carrier adapter, how events work).
- **Performance:** Profile rate shopping flow — concurrent API calls are good, but consider caching common rate lookups for batch operations.
- **Internationalization:** Foundational seams in place (Location model with country, AddressValidationInterface, originCountry on RateRequest). Remaining: unit conversion in carrier adapters, multi-currency, international address validators, DestinationZone refactoring. See Phase 3.1.
- **Health monitoring:** Status widget on dashboard showing carrier API availability, queue depth, and system health. The `/api/health` endpoint checks DB and Redis but nothing monitors carrier APIs or queue health in-app.
- **Backup tooling:** For on-prem deployments, a built-in backup command (wrapper around `mysqldump` + config export) would reduce support burden. Currently backup is entirely the customer's responsibility.
- **API rate limiting:** Only `/qz/sign` is rate-limited. All API endpoints (Phase 2.1) and Filament panel routes should have appropriate rate limiting.
- **Workstation-based device settings:** Device settings (printer, label format, DPI, scale backend, scale IDs) are currently stored in browser localStorage, which breaks in incognito mode, after cache clears, or on browser reinstall. Move to a server-side `Workstation` model keyed by hostname (from `qz.websocket.getNetworkInfo()`). Fallback chain: localStorage (instant) → database lookup by hostname (transparent recovery) → Device Settings prompt (new workstation). Settings are per-machine, not per-user, which matches the physical hardware. Device Settings page saves to both localStorage and database. QZ Tray not running falls back to localStorage-only (current behavior).
- **Void shipments/packages:** Remove shipments and packages from stats and prevent re-import without deleting the record. Keeps data searchable and auditable. Replaces the need for soft deletes.
- **Shared credential rotation script:** `scripts/rotate-shared-creds.sh` — reads updated values from `/opt/shared/.env` and `/opt/shared/oauth.env`, sed-replaces into all tenant `.env` files, restarts containers. Currently manual. Build when needed (credential rotation is infrequent).
