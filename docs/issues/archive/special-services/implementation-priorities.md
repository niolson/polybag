# Special Services: Implementation Priorities & Process

> Companion to `polybag-special-services-report.md` (catalog) and
> `carrier-restrictions-and-availability.md` (restrictions/precheck research).
> This doc decides **what order to build in, what to cut, and the repeatable
> process** for each service. Written 2026-07-08.

## Current state

- Catalog: 14 services seeded (`SpecialServiceSeeder`), code-owned, and gated by
  `$wiredCodes`. Saturday delivery, both signature variants, declared value, alcohol,
  and the three lithium-battery classifications are active. The remaining catalog rows
  stay inactive until their adapter work is complete.
- Plumbing already in place: `SpecialServiceResolver` (method modes + product compliance), `carrier_service_special_service` scoping with `restricted_countries`, `PackageSpecialService` audit pivot, per-adapter capability maps, `appliedServices` reporting.
- `Product.contains_alcohol` / `Product.hazmat_class` already feed the resolver; those codes start flowing the moment their services flip active.
- No operator-selection UI on the Ship page yet — `available` mode exists in the enum but nothing consumes it.
- Waves 1–3 below are complete. The wave descriptions remain as the rationale and
  implementation record; Wave 4 and the deferred list are still prospective.

## Prioritization criteria

A service moves up the list when it scores well on:

1. **Demand** — does a 3PL e-commerce client plausibly need it? (This app packs parcels for retail brands; it is not a legal-mail or freight tool.)
2. **Carrier breadth** — supported by all three carriers > one carrier.
3. **API reachability** — exposed by the modern API surface we actually call (e.g. USPS Hold for Pickup code 985 is confirmed *unreachable* via Labels v3 — automatic disqualifier).
4. **Model fit** — a boolean/simple-config flag on the existing rate→ship flow, vs. needing new integrations or UI (FedEx Hold at Location requires the separate Location Search API).
5. **Testability** — per the sandbox findings: USPS and UPS sandboxes enforce real rules; FedEx sandbox is virtualized almost everywhere, so FedEx work must be designed defensively (precheck-first, error-retry unrehearsable).
6. **Selectable vs. passive** — surcharges the carrier applies on its own (residential, fuel, peak) are not special services; they belong to rating accuracy, not this catalog.

## The waves

### Wave 1 — Signature family: `signature_required`, `adult_signature_required`

The strongest first pick:

- **All three carriers**, no detail payloads, package scope — establishes the package-level adapter pattern (Saturday delivery only exercised shipment level).
- Most common real 3PL policy ask (age-restricted and high-value goods).
- Adult signature is the compliance backstop `alcohol` will need in Wave 3 — build the dependency first.

Carrier mapping (from the catalog report):

| | USPS | UPS | FedEx |
|---|---|---|---|
| signature | code 921 + `physicalSignatureRequired` | `DeliveryConfirmation` subtype | `signatureOptionType: DIRECT` |
| adult | code 922 + `physicalSignatureRequired` | `Adult Signature Required` subtype | `signatureOptionType: ADULT` |

Known gotchas to encode in scope rows / adapter logic:
- UPS uses **different numeric code sets** for package-level vs shipment-level DeliveryConfirmation (1/2/3 vs 1/2).
- FedEx `DIRECT` = US + Canada-via-Ground only; `ADULT` = US only → seed `restricted_countries` on the FedEx scope rows.
- USPS: 921/922 broadly valid per the STC CSV (Priority, Ground Advantage, etc.) — validate against `usps-stc-list.csv`.

### Wave 2 — `declared_value`

- All three carriers; the first `requires_value` service, so it exercises the config-schema path end to end (pivot `config` → DTO → adapter).
- FedEx: plain `declaredValue` field (not even a special service enum). UPS: `DeclaredValue` with $5k/$50k caps. USPS: codes 930/931 + required `packageValue`, with the 930→931 auto-upgrade above $500, and 931 requiring `physicalSignatureRequired` (interaction with Wave 1 — build in this order).
- **Amount source decided 2026-07-08:** derived only — `shipments.value`, falling back to the package's `shipment_items.value × quantity` sum when null/zero. No method config or operator entry; missing value at both levels is an operator-facing "edit the shipment" error. (`products` has no value column, so the chain stops at two levels.) Details in `issues/03-declared-value-source-decision.md`.

### Wave 3 — Product compliance: `alcohol` + lithium battery family

The plumbing is already live (`resolveProductRequiredCodes`); the deliverable here is mostly **rate gating** — excluding carrier services that can't legally carry the contents — plus label flags where they exist. This is the wave where `carrier_service_special_service` scoping earns its keep.

- `alcohol`: FedEx only for domestic (`ALCOHOL` + required `alcoholRecipientType` detail); UPS domestic small-package unsupported (ISC 205 is intl/freight); USPS prohibited domestically. Net: flagged products get FedEx-only rates + adult signature.
- Batteries: USPS hazmat codes 816/818/820 (820 also intl); FedEx `BATTERY`/`STANDALONE_BATTERY` package-level; UPS has no explicit parcel accessorial — declaring batteries to UPS requires the full `HazMat` container (see `ups_shipmentrequest_*.json` examples). **Decided 2026-07-08:** model excepted/Section II batteries only — `in_equipment`: allow everywhere, no UPS declaration, FedEx `BATTERY` + details, USPS 818 (per-client marked-package assumption); `standalone`: USPS 820 + FedEx `STANDALONE_BATTERY`, UPS gated out; `ground_only`: ground services only on all carriers, USPS 816. Full matrix in `issues/06-lithium-battery-family.md`.
- **Scope cut inside this wave:** batteries and alcohol only. Full dangerous-goods declarations (UN numbers, `dangerousGoodsDetail`, UPS HazMat commodity data) are a paperwork engine, not a flag — out of scope until a client contractually needs it.

### Wave 4 — Cheap adjacent wins (pull in opportunistically)

- `carrier_release` — USPS `carrierRelease` boolean (with its documented mail-class/service-code incompatibilities), UPS accessorial 402 (US50/PR only), FedEx = `NO_SIGNATURE_REQUIRED` signature option. Mutually exclusive with Wave 1 services — encode that.
- `dry_ice` — UPS/FedEx with weight detail; USPS = hazmat code 819. Only if a cold-chain-ish client shows up.
- `email_notification` — UPS/FedEx only; low risk, low demand.

### Deferred — real dependency or no demand yet

| Service | Why deferred |
|---|---|
| `hold_at_location` | FedEx requires the separate Location Search API (virtualized in sandbox) + a location-picker UI; USPS code 985 confirmed unreachable via Labels v3. UPS-only support isn't worth the UI until asked for. |
| `evening_delivery` / appointment / date-certain | FedEx Ground Home Delivery only; niche. |
| `cremated_remains` | USPS-only, trivially easy, but zero known demand — activate on first request. |
| Ship-page operator-selection UI (`available` mode) | Cross-cutting, not a service. Waves 1–3 ship fine on `required`/`default` method modes; build this when a client wants per-shipment operator choice. |

### Out of scope — recommend cutting (revisit only on explicit client demand)

| Service | Reason |
|---|---|
| COD (all carriers) | Payment reconciliation product, not a label flag; near-dead in US e-commerce; per-carrier structural splits (FedEx Ground=package vs Express=shipment; UPS EU-only shipment-level). |
| Certified Mail, Registered Mail, Return Receipt, Tracking Plus, ancillary endorsements | USPS legal-correspondence features, wrong market for a parcel 3PL tool. Registered is CS-API-only (not eVS); Return Receipt 955 is rejected by the Labels API since 2025 anyway. If non-delivery endorsements are ever needed, model them as label options, not special services. |
| UPS Premier / Premium Care, FedEx Priority Alert | Contract-gated enterprise monitoring; can't even be enabled per-shipment. |
| Import Control, Returns Clearance, return labels | Returns are a full feature (own workflow, own labels), not a toggle — separate roadmap item. |
| Saturday Pickup | Pickup management is outside the app's job (it packs and labels; it doesn't schedule pickups). |
| Protection from Freezing / Refrigeration | Cold chain isn't the target market. |
| Residential delivery | Not selectable — it's address classification. Belongs to rating-accuracy work. |
| Full hazmat / dangerous goods declarations | See Wave 3 cut. |

## Per-service playbook (repeat for every wave)

1. **Spec one-pager** (in this directory): normalized code, scope, per-carrier field mapping, carrier-service + country scope rows, config schema, eligibility/precheck mechanism, error/retry expectations, mutual exclusions, test plan. Source everything from the two research docs — they already contain the answers.
2. **Seed scope rows** in `CarrierServiceSpecialServiceSeeder` (with `restricted_countries` where the research says so). USPS rows validate against `usps-stc-list.csv`, which is the durable source of truth.
3. **Wire adapters**: capability map entry, rate-request mapping, ship-request mapping, `appliedServices` reporting. Follow the `HasSaturdayDelivery` / Saturday-delivery code paths as the template, but note per-carrier eligibility strategy differs:
   - **USPS** — client-side validation against the STC CSV; purchase-time errors are structured (`source.parameter`) and safe to build a resolver on. The rating endpoint is a confirmed false-positive source — never use it as a precheck.
   - **UPS** — day-map-style preemptive guess where possible; grow the empirical `{error code → accessorial}` map (`111562`, `111262`, `111538`, `110646` already known) for retry.
   - **FedEx** — Service Availability API is the precheck (production-verified; sandbox lies). Ship-endpoint rejection handling can't be rehearsed in any environment — design defensively: precheck-first, treat error-based retry as best-effort.
4. **Tests**: Pest feature tests via `FakeCarrierAdapter` for resolver/mode/scoping behavior; adapter unit tests against recorded/fixture payloads; live sandbox probes only where trustworthy (USPS ✅, UPS ✅, FedEx ❌).
5. **Activate**: add the code to `$wiredCodes` in `SpecialServiceSeeder` — the seeder design already makes activation the last, deliberate step.
6. **Staged rollout**: enable on one shipping method in the demo tenant first; watch `appliedServices` vs. requested for silent drops (the UPS `SubVersion` silent-ignore class of bug); then real tenants.
7. **Feed back**: any new carrier error code or eligibility surprise goes into `carrier-restrictions-and-availability.md` so the research stays the source of truth.

## Cadence and scope-decision process

- Work **one wave at a time, vertically** (one service across all three carriers), not carrier-by-carrier — Saturday delivery already proved the vertical slice.
- After each wave, re-run the deferred/out-of-scope lists against actual client requests. The rubric for promoting anything: named client demand + reachable API + fits the flag model + testable. Two strikes on that list = stays cut.
- Track each wave as an issue in the tracker (`docs/issues/` issues) so they're independently grabbable.

## Issues (published 2026-07-08)

All seven are complete. Their files retain the decisions, carrier evidence, and test notes:

1. `issues/01-signature-required.md`
2. `issues/02-adult-signature-required.md`
3. `issues/03-declared-value-source-decision.md`
4. `issues/04-declared-value.md`
5. `issues/05-alcohol-compliance-gating.md`
6. `issues/06-lithium-battery-family.md`
7. `issues/07-scoping-visibility.md`

Wave 4 and the deferred list are intentionally not ticketed — re-evaluate after Wave 3.
