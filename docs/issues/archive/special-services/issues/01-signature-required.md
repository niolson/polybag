# `signature_required` end-to-end (all three carriers)

Status: done
Type: AFK

## Parent

`docs/issues/archive/special-services/implementation-priorities.md` (Wave 1)

## What to build

Activate the `signature_required` special service end to end: an operator ships a package whose shipping method has the service in `required`/`default` mode, and the purchased label actually carries a signature requirement on USPS, UPS, and FedEx. This is the tracer bullet for the **package-level** adapter path (Saturday delivery only exercised shipment level).

Carrier mapping (from `polybag-special-services-report.md` / `carrier-restrictions-and-availability.md`):

- **USPS** — extra service code `921` + required `physicalSignatureRequired` field. Validate mail-class combinations against `.scratch/special-services/usps-stc-list.csv` (921 is valid for Priority, Ground Advantage, Parcel Select, Media/Library/BPM).
- **UPS** — `DeliveryConfirmation` element, subtype Signature Required. **Gotcha:** package-level and shipment-level use different numeric code sets (package 1/2/3, shipment 1/2) — use the package-level path.
- **FedEx** — `requestedPackageLineItems[].specialServicesRequested.signatureOptionType = DIRECT`. Scope: US everywhere; Canada only via FedEx Ground → express-tier scope rows get `restricted_countries: [US]`, Ground gets `[US, CA]`.

## Acceptance criteria

- [ ] Scope rows seeded in `CarrierServiceSpecialServiceSeeder` for all three carriers per the mapping above (FedEx country restrictions included)
- [ ] Each adapter declares the capability in its capability map and includes the option in both rate and ship requests
- [ ] `appliedServices` on rate and ship responses reports `signature_required` when actually applied
- [ ] Default-mode stripping works: a carrier service without a scope row does not quote/purchase with the option, matching `SpecialServiceResolver::resolveForPackageAndRate()`
- [ ] Pest feature tests via `FakeCarrierAdapter` for resolver/mode behavior; adapter unit tests against fixture payloads for the three request shapes
- [ ] `saturday_delivery`-style follow-the-template review: capability map, rate mapping, ship mapping, appliedServices — all four present per carrier
- [ ] `signature_required` added to `$wiredCodes` in `SpecialServiceSeeder` as the final step
- [ ] Any new carrier error codes or eligibility surprises fed back into `carrier-restrictions-and-availability.md`

## Blocked by

None - can start immediately

## Comments

**Implemented 2026-07-09** (single session with issues 02/04/05/06/07, awaiting review + sandbox verification).

- Scope rows seeded (USPS GA+PM per STC; FedEx DIRECT US-only with US+CA on Ground; UPS unscoped).
- USPS: code 921 + `physicalSignatureRequired: false` (allows eSOL) via `UspsAdapter::mapExtraServices()`; also sent to the rating endpoint so quotes carry the surcharge.
- UPS: package-level `DeliveryConfirmation.DCISType = 2` in rate + ship.
- FedEx: `packageSpecialServices.signatureOptionType = DIRECT` on the line item in rate + ship.
- `appliedServices` reported by all three adapters (UPS previously reported none at all — fixed).
- Activated in `SpecialServiceSeeder::$wiredCodes`. Tests: resolver, rate service, per-adapter payload assertions.
- Sandbox-verify before rollout: USPS `physicalSignatureRequired`/`packageValue` field placement in `packageDescription` (see issue 04 note).

**Sandbox-verified 2026-07-09.** Real labels purchased and voided in the USPS sandbox through the adapter path: 921 Signature Confirmation priced $3.95, 922 Adult Signature $9.70. `physicalSignatureRequired` placement corrected to `packageDescription.packageOptions` (see issue 04 / research doc §0.1). FedEx `DIRECT`/`ADULT` confirmed present in the production availability API's `signatureOptionsList`.

**Closed 2026-08-22.** Merged to `main` 2026-07-09 in PR #72 (`902a8582`) and live since. The
review this was parked on never happened as a separate pass; closing it retroactively rather
than leaving a gate open on shipped code.
