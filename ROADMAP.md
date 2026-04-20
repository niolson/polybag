# Feature Roadmap

Planned features and improvements for PolyBag, organized by commercial launch priority.

## Background

This app was originally built as an internal tool for a company with 20,000+ unique SKUs. The scan-and-validate packing workflow was designed for that environment, where package contents, dimensions, and weight aren't known until each order is physically packed. Most businesses in the target market (100–5,000 packages/day) will have fewer SKUs and more predictable shipments, so the product needs to support both manual pack-and-ship and automated batch workflows.

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

### FedEx Ground Economy (SmartPost) — needs live API testing

Implementation is complete (see git history). Needs testing against a live FedEx API account — sandbox account `740561073` supports Ground Economy but new developer portal accounts may have issues (see FedEx support notes from 2026-03-23).

### Special Services — Adapter Wiring

The special services catalog, `serviceCapability()` classification, and UI are in place. Saturday delivery is fully wired for FedEx and UPS. The remaining work is wiring each service into the rate/ship request builders in each adapter.

**USPS** — `saturday_delivery` should be `Supported` no-op (USPS delivers Saturday natively, nothing to add to the request). Wire up remaining:

- [ ] `saturday_delivery` — mark `Supported`, no-op (USPS delivers Saturday natively)
- [ ] `signature_required` — extra service 119 (Signature Confirmation)
- [ ] `adult_signature_required` — extra service 922 (Adult Signature Required)
- [ ] `carrier_release` — extra service 415 (Carrier Release)
- [ ] `declared_value` — extra service 900 (Declared Value) + amount
- [ ] `email_notification` — extra service 476 or Informed Delivery notification
- [ ] `dry_ice` — extra service 819 + weight
- [ ] `lithium_battery_in_equipment` — extra service 818 (Hazmat - Lithium Battery)
- [ ] `lithium_battery_standalone` — extra service 820 (Hazmat - Lithium Battery Standalone)
- [ ] `cremated_remains` — currently `Supported` but not wired; extra service 813
- [ ] `hold_at_location` — mark `Prohibited` (USPS doesn't offer hold-at-location)
- [ ] `evening_delivery` — mark `Prohibited` (not a USPS concept)
- [x] `alcohol` — `Prohibited` (USPS domestic prohibition, correct)
- [x] `lithium_battery_ground_only` — `Prohibited` (USPS uses air transport, correct)

**FedEx** — saturday_delivery is fully wired (rate + ship + retry logic). Wire up remaining:

- [x] `saturday_delivery` — fully wired
- [x] `cremated_remains` — `Prohibited` (FedEx policy, correct)
- [ ] `signature_required` — `INDIRECT` or `DIRECT` in `signatureOptionType`
- [ ] `adult_signature_required` — `ADULT` in `signatureOptionType`
- [ ] `carrier_release` — `NO_SIGNATURE_REQUIRED` in `signatureOptionType`
- [ ] `declared_value` — `declaredValue` on `requestedPackageLineItems`
- [ ] `email_notification` — `EVENT_NOTIFICATION` special service + email list
- [ ] `dry_ice` — `DRY_ICE` special service + `dryIceWeight`
- [ ] `alcohol` — `ALCOHOL` special service (requires FedEx alcohol shipping agreement)
- [ ] `lithium_battery_in_equipment` — `BATTERY` special service
- [ ] `lithium_battery_standalone` — `STANDALONE_BATTERY` special service
- [ ] `lithium_battery_ground_only` — mark `Prohibited` (air services restriction)
- [ ] `hold_at_location` — `HOLD_AT_LOCATION` special service + facility address
- [ ] `evening_delivery` — `HOME_DELIVERY_PREMIUM` with `EVENING` type (Home Delivery only)

**UPS** — saturday_delivery is fully wired (rate + ship + retry logic). Wire up remaining:

- [x] `saturday_delivery` — fully wired
- [ ] `signature_required` — `DeliveryConfirmation` code 1
- [ ] `adult_signature_required` — `DeliveryConfirmation` code 2
- [ ] `carrier_release` — leave-if-no-response option
- [ ] `declared_value` — `DeclaredValue` on package
- [ ] `email_notification` — accessorial code 012 (Ship Notification)
- [ ] `dry_ice` — accessorial code 200 + dry ice weight
- [ ] `alcohol` — accessorial code 205 (international only; mark `Prohibited` for domestic)
- [ ] `lithium_battery_in_equipment` — accessorial code 199 + UN3481
- [ ] `lithium_battery_standalone` — accessorial code 199 + UN3090
- [ ] `lithium_battery_ground_only` — mark `Prohibited` (air services restriction)
- [ ] `hold_at_location` — `UAPAddress` on `ShipTo` + accessorial code 054
- [ ] `cremated_remains` — mark `Prohibited` (UPS does not accept cremated remains)
- [ ] `evening_delivery` — mark `Prohibited` (UPS doesn't offer this)

### Phase D: USPS Rate Indicator Optimization

Currently, rate shopping always works (queries USPS Shipping Options API for valid mailClass + rateIndicator pairs). The optimization is skipping rate shopping for known-cheap combinations.

**Problem:** The cheapest rateIndicator (SP, cubic, etc.) varies by weight, volume, and shipping zone. No static lookup table works universally.

**Approach:** Build a lookup table from historical rate data (already captured by `RateQuoteLogger`). As volume increases, the system accumulates data on which rateIndicator won for each weight/zone bucket. Use this for "quick ship" scenarios; fall back to rate shopping when uncertain.

**Not recommended:** Binary search job against USPS API — zone-dependent pricing makes the thresholds non-uniform. Better to learn from real shipping data.

### Phase E: Special Services Model

Cross-carrier special services: signature options, alcohol, lithium batteries, dry ice, hold at location, email notifications, declared value, Saturday delivery, and more. Currently, Saturday delivery lives as a dedicated column on `ShippingMethod` and alcohol/hazmat exist as a stub boolean concept. This replaces both with a proper extensible model.

**Design principles:**
- `special_services` is a code-owned catalog (seeded via migrations, not user-editable) — an enum registry with DB rows
- Product compliance facts (alcohol, hazmat, battery type) are product attributes, not service attachments — they exist before any label is created and affect carrier eligibility
- Carrier adapters translate normalized internal service codes to carrier-specific API fields; the DB never stores carrier-specific codes
- Package-level snapshot captures what was actually applied and why, independent of later config changes
- Carrier-specific request payload stored separately for debugging

#### V1 — Core Infrastructure

**Schema changes:**

`special_services` table (seeded, code-owned):
- `id`, `code` (stable string key e.g. `signature_required`), `name`, `description`
- `scope` enum: `shipment`, `package`
- `category` string: `delivery`, `compliance`, `pickup`, `returns`, etc.
- `requires_value` boolean, `config_schema` JSON nullable (describes what additional data the service needs, e.g. dry ice weight, email addresses)
- `active` boolean

`shipping_method_special_service` pivot (replaces `shipping_methods.saturday_delivery` and similar flags):
- `shipping_method_id`, `special_service_id`
- `mode` enum: `available`, `default`, `required`
- `config` JSON nullable (service-specific defaults for this method, e.g. default signature level)
- `sort_order`

Product compliance columns (facts about what the product is, not service attachments):
- `products.hazmat_class` — nullable string enum: `lithium_battery_in_equipment`, `lithium_battery_standalone`, `lithium_battery_ground_only`, `dry_ice`, `cremated_remains`, etc.
- `products.contains_alcohol` boolean
- Full regulatory hazmat (UN numbers, packing groups, emergency contacts) is deferred to V2

`package_special_services` snapshot table:
- `id`, `package_id`, `special_service_id`
- `source` enum: `shipping_method`, `product`, `manual`, `system` (rule reserved for V2)
- `source_reference` nullable (e.g. `product_id:42` for product-triggered services)
- `config` JSON nullable (service-specific values: dry ice weight, email addresses, declared amount, etc.)
- `applied_at`

Package-level payload columns:
- `packages.carrier_request_payload` JSON nullable — the exact serialized payload sent to the carrier API (for debugging label issues)

**Resolution logic (runs at ship time, before label purchase):**

1. Load shipping method defaults from pivot → add services with `mode: default` or `mode: required`
2. Check product compliance flags on each package item → auto-add `hazmat_class`-triggered services (operator cannot clear these)
3. Operator adjusts optional selections in Ship UI (constrained to `mode: available` services for the selected carrier service)
4. Dry ice weight, notify emails, declared value, hold-at-location address → collected as `config` on the relevant `package_special_services` row
5. Write `package_special_services` rows with `source` + `source_reference`
6. Adapter reads rows → translates to carrier-specific API fields → writes `carrier_request_payload`

**Carrier translation examples:**

| Internal code | USPS | FedEx | UPS |
|---|---|---|---|
| `lithium_battery_in_equipment` | extra service 818 | `BATTERY` | 199 + UN3481 |
| `lithium_battery_standalone` | extra service 820 | `STANDALONE_BATTERY` | 199 + UN3090 |
| `dry_ice` | extra service 819 | `DRY_ICE` + weight | acc. 200 + weight |
| `alcohol` | ❌ domestic | `ALCOHOL` | 205 (intl only) |
| `adult_signature_required` | extra service 922 | `SIGNATURE_OPTION: ADULT` | DeliveryConfirmation subtype |
| `saturday_delivery` | native (no-op) | `SATURDAY_DELIVERY` | acc. 300 |

#### V2 — Rule Engine Integration

Extend `ShippingRule` actions to add, remove, or require special services. The `package_special_services.source = 'rule'` column is already designed for this; the rule engine extension is deferred until a real merchant need surfaces.

Also deferred to V2: full UN-number regulatory hazmat (flammable liquids, corrosives, radioactives) — these require per-substance dangerous goods declarations (UN number, proper shipping name, packing group, regulation set, emergency contacts) that are too complex for V1 and require DG-trained operators.

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

- **CSV/Excel export on report tables** — Report pages lack export functionality. Delivery confidence scoring also needs tracking data from Phase 2.3.
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
- **Data archiving (Phase 3):** Configurable retention policies per customer, automated archival to S3/external storage, archive viewer for historical lookups.
