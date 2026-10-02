# Lithium battery family: wire the decided policy matrix

Status: done
Type: AFK

## Parent

`docs/issues/archive/special-services/implementation-priorities.md` (Wave 3)

## Decision (made 2026-07-08, approved by Nick in planning conversation)

PolyBag models **excepted / Section II** batteries only. Fully regulated dangerous-goods declarations (UPS `HazMat` container with UN data, DG contract, `DGSignatoryInfo`, `SubVersion: 1701` — see `.scratch/special-services/ups_shipmentrequest_dry_ice_or_lithium_batteries.json` and `ups_shipmentrequest_hazardous_materials.json` for what that would entail) remain out of scope.

| `hazmat_class` | USPS | UPS | FedEx |
|---|---|---|---|
| `lithium_battery_in_equipment` | code **818** (marked) | **allow, no API declaration** (Section II needs none; marking is a warehouse concern) | `BATTERY` + `batteryDetails` |
| `lithium_battery_standalone` | code **820** (unmarked; also intl-valid) | **gated out** (no capability) | `STANDALONE_BATTERY` + `batteryDetails` |
| `lithium_battery_ground_only` | code **816**, ground classes only | ground services only | ground services only |

USPS 818 carries a per-client assumption that battery packages are physically marked — record this assumption in the client-facing docs/UI helper text.

## What to build

Activate the three battery services so that packing a product with a battery `hazmat_class` produces correctly gated rates and correctly flagged labels per the matrix. As with issue 05, the resolver already emits the codes once active; the work is capability maps, scope rows, and adapter field mapping.

- Ground-only enforcement is expressed as scope rows limited to ground-network carrier services (USPS Ground Advantage/Parcel Select, UPS Ground, FedEx Ground/Home Delivery) — first case of scoping by service *type* across all carriers.
- FedEx `batteryDetails` (material/packing type) derived from the `hazmat_class`; note FedEx restricts the Section II surcharge tiers per `carrier-restrictions-and-availability.md` §3.1 — verify against the availability API pattern, production-only.
- UPS `in_equipment`: allow rates, send nothing — assert in tests that no `HazMat` container is emitted.

## Acceptance criteria

- [ ] Matrix above implemented via capability maps + scope rows; each cell covered by a Pest test
- [ ] `lithium_battery_ground_only` package gets no air-service rates from any carrier
- [ ] `lithium_battery_standalone` package gets no UPS rates
- [ ] USPS ship requests carry the correct 8xx code per class; FedEx requests carry the correct enum + `batteryDetails`; UPS requests for `in_equipment` are clean of hazmat fields
- [ ] Marking assumption for USPS 818 surfaced in helper text where clients configure product hazmat classes
- [ ] All three codes added to `$wiredCodes` as the final step

## Blocked by

- `01-signature-required.md` (package-level adapter pattern; no dependency on 02/04/05 — can run parallel to Wave 2)

## Comments

**Implemented 2026-07-09** per the decided matrix (awaiting review + sandbox verification).

- USPS: 818/820/816 with `contentType: HAZMAT`; 820 also on international labels. Scope rows: 818 → GA/PM/PME, 820/816 → GA only (Pub 52 surface-only). Note: `UspsAdapter` previously marked `lithium_battery_ground_only` **Prohibited** — changed to Supported + ground scoping per the decision.
- UPS: in-equipment and ground-only allowed with **no API declaration** (asserted clean of HazMat fields in tests); standalone NotImplemented → excluded. Ground-only scoped to UPS Ground.
- FedEx: `BATTERY` + `batteryDetails` (CONTAINED_IN_EQUIPMENT / LITHIUM_ION — material assumed ion, classes don't distinguish) or `STANDALONE_BATTERY`; standalone + ground-only scoped to Ground/Home Delivery.
- Marking assumption surfaced in the Product form hazmat helper text.

**Production-verified and REVISED 2026-07-09** via the FedEx Service Availability API (brief guarded `sandbox_mode` flip, one read-only call, restored — response saved as `fedex-availability-production-response-2026-07-09.json`):

- **`STANDALONE_BATTERY` does not exist** on any FedEx service for a US lane (zero occurrences). Standalone lithium via FedEx is full-DG territory (out of scope). **Matrix revised: `lithium_battery_standalone` is now USPS-only** (820, Ground Advantage) — FedEx gated out via capability map alongside UPS; seeder no longer scopes it to FedEx (full-sync seeder removes the stale rows on reseed).
- **Express battery payload validated exactly**: `batteryOptionList` enumerates `LITHIUM_ION`/`CONTAINED_IN_EQUIPMENT`/`IATA_SECTION_II` = "Ion Contained in Equipment (UN3481, PI967)" — our `batteryDetails` verbatim.
- **Ground is different**: batteries on FEDEX_GROUND/GROUND_HOME_DELIVERY surface only as a DANGEROUS_GOODS subtype, not the BATTERY service. Adapter revised: battery fields sent on Express requests only; ground ships clean (marks are physical, matching the UPS posture); mixed rate requests omit battery fields so ground rates are never poisoned (express quotes then miss the small battery surcharge — accepted trade-off).
- USPS side: 820 battery label with `contentType: HAZMAT` purchased + voided in sandbox through the adapter path.

**Closed 2026-08-22.** Merged to `main` 2026-07-09 in PR #72 (`902a8582`) and live since. The
review this was parked on never happened as a separate pass; closing it retroactively rather
than leaving a gate open on shipped code.
