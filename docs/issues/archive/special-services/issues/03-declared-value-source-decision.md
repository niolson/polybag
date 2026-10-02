# Declared value: decide where the amount comes from

Status: done — decision recorded 2026-07-08
Type: HITL

## Parent

`docs/issues/archive/special-services/implementation-priorities.md` (Wave 2)

## What to build

A decision, not code. Before `declared_value` can be wired (issue 04), decide the source of the declared amount per package:

1. **Derived** — sum of shipment item values (retail/declared value on `ShipmentItem`/`Product`) for the items packed in the package
2. **Method config** — a fixed amount or formula configured on the shipping-method pivot `config` (e.g. "always declare $200")
3. **Operator entry** — typed on the Ship page at label time (requires the operator-selection UI that doesn't exist yet — see the deferred `available`-mode work)

Considerations to settle alongside:

- Behavior in Manual Ship and Batch Ship flows (no operator interaction in batch)
- USPS auto-upgrades code 930 → 931 above $500, and 931 additionally requires `physicalSignatureRequired` — a derived amount can silently change the service mix
- UPS caps: $5,000 (local) / $50,000 (remote) — what happens when the derived value exceeds the cap (clamp vs. warn vs. block)
- Whether the amount is stored on the `package_special_services` pivot `config` for audit (recommended regardless of source)

## Acceptance criteria

- [x] Source of the amount decided and recorded in this issue (with fallback order if hybrid, e.g. derived-with-method-override)
- [x] Cap-overflow behavior decided
- [x] Decision reflected in `implementation-priorities.md` Wave 2 and inherited by issue 04

## Blocked by

None - can start immediately (decision can run parallel to issues 01/02)

## Comments

**Decision (Nick, 2026-07-08):**

Derived only — no method config, no operator entry on the Ship page.

Fallback chain: `shipments.value` → if null/zero, sum of `shipment_items.value × quantity` for the items in the package. When neither yields a usable value, **do not guess and do not block silently** — show an error telling the operator to set a value by editing the shipment.

Notes from recording the decision:

- Nick's original chain had a third fallback to product value, but **`products` has no monetary value column** (only `weight`, `handling_surcharge`, `hs_tariff_number`). The chain is two levels as the schema stands; adding a product value column is a separate schema decision if the two-level chain proves insufficient in practice.
- Multi-package wrinkle for the implementer: `shipments.value` is shipment-level. For single-package shipments use it directly; when a shipment splits across packages, prefer the per-package item sum (level 2) so each label declares only what it contains, rather than declaring the full shipment value on every package.
- Cap overflow (UPS $5,000/$50,000): consistent with the error-over-silent-adjustment posture above — no silent clamping; exclude/error visibly for the affected carrier.
