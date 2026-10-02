# `declared_value` end-to-end

Status: done
Type: AFK

## Parent

`docs/issues/archive/special-services/implementation-priorities.md` (Wave 2)

## What to build

First `requires_value` service — proves the config path end to end: pivot `config` → resolver → DTO → adapter fields → `appliedServices`, with the amount stored on `package_special_services.config` for audit.

**Amount source (decided in issue 03):** derived only. `shipments.value` first; if null/zero, sum of `shipment_items.value × quantity` for the items packed in the package (`products` has no value column — the chain is two levels). Multi-package shipments should prefer the per-package item sum so each label declares only its own contents. No method config, no operator entry. If neither level yields a usable value, surface an error telling the operator to set a value by editing the shipment — never guess, never silently skip the service.

Carrier mapping:

- **USPS** — extra service codes `930`/`931` + required `packageValue` field; 930 auto-upgrades to 931 above $500; **931 requires `physicalSignatureRequired`** (interaction with Wave 1 — model the implied signature requirement, don't fight the API)
- **UPS** — `PackageServiceOptions/DeclaredValue` (`CurrencyCode` + `MonetaryValue`); not the freight-only `Insurance` accessorial. Caps $5,000/$50,000: no silent clamping — exclude/error visibly for UPS when the derived value exceeds the cap (issue 03 decision)
- **FedEx** — plain `requestedPackageLineItems[].declaredValue` (`amount` + `currency`); not a special-service enum at all

## Acceptance criteria

- [ ] Amount derived via the two-level chain (shipment value → package item sum) through `RateRequest`/`ShipRequest` DTOs → all three adapters
- [ ] Missing/zero value at both levels produces an operator-facing error directing them to edit the shipment (rate time, before any label attempt)
- [ ] USPS 930/931 threshold handled, including the 931 → signature-required implication
- [ ] UPS cap overflow errors visibly rather than clamping
- [ ] Rate responses reflect the declared-value surcharge so quoted price matches purchase price
- [ ] `appliedServices` + pivot audit row record the amount actually declared
- [ ] Pest feature + adapter unit tests, including the $500-crossing USPS case
- [ ] `declared_value` added to `$wiredCodes` as the final step

## Blocked by

- `02-adult-signature-required.md` (signature plumbing the USPS 931 path leans on)
- ~~`03-declared-value-source-decision.md`~~ resolved 2026-07-08 — decision inlined above

## Comments

**Implemented 2026-07-09** (awaiting review + sandbox verification).

- Two-level derivation in `SpecialServiceResolver::declaredValueForPackage()` (shipment value for single-package shipments; per-package item sum otherwise); flows via new `specialServiceConfig` on both DTOs.
- Missing value → `MissingDeclaredValueException` → blocking operator error on the Ship page ("Declared Value Required") at rate time, and a specific failure on ship.
- USPS 930/931 threshold at $500 with `packageValue` + 931's `physicalSignatureRequired`; UPS `DeclaredValue` (cap $50,000); FedEx line-item `declaredValue` (cap $50,000); USPS insurance cap $5,000. Caps enforced via new `CarrierAdapterInterface::declaredValueCap()` — carrier excluded visibly at rate time, never clamped.
- Amount audited on `package_special_services.config` at ship time.
- Sandbox-verify: exact USPS field placement of `packageValue`/`physicalSignatureRequired` inside `packageDescription` for Labels v3 and the rating spec — implemented per the API reference but not yet probed.

**Sandbox-verified and FIXED 2026-07-09.** Probing found the reference docs' placement wrong: Labels v3 reads `packageValue`/`physicalSignatureRequired` only from `packageDescription.packageOptions` — anywhere else they are silently unread (930 kept erroring `160017` while we were sending the value). The rating endpoint differs: it reads `packageDescription.packageValue` directly and prices insurance value-sensitively ($4.40 @ $200, $59.95 @ $4,000). Adapter fixed accordingly (label paths use `packageOptions`; rating path unchanged); 930 and 931 labels purchased + voided through the adapter. Also learned: `physicalSignatureRequired` is optional in practice even with 931, contra the docs — we still send it.

**Closed 2026-08-22.** Merged to `main` 2026-07-09 in PR #72 (`902a8582`) and live since. The
review this was parked on never happened as a separate pass; closing it retroactively rather
than leaving a gate open on shipped code.
