# Scoping visibility: surface special-service scoping and applied/stripped services

Status: done
Type: AFK

## Parent

`docs/issues/archive/special-services/implementation-priorities.md`

## What to build

Make special-service scoping legible instead of silent, in two places:

1. **Ship page (operational)** — each quoted rate shows which special services were applied, and a visible indicator when a default-mode service was stripped for that carrier service (the `resolveForPackageAndRate()` filtering) or a carrier was excluded by a required/compliance code. This is the view that pre-empts "why didn't FedEx do Saturday?" support questions.
2. **Special Services list (admin)** — a "Carrier services" column/summary on `SpecialServiceResource` showing which carrier services each special service is scoped to, so an admin doesn't have to open every Carrier Service edit page (the existing `SpecialServicesRelationManager` view is inverted — per carrier service only).

Also settle scope-row ownership: rows are currently seeded **and** freely UI-editable via the relation manager, which lets admin edits drift from researched carrier facts (e.g. FedEx `ADULT` = US-only) and fight reseeding. Recommendation from planning: treat seeded scope rows as code-owned — read-only in the relation manager (or editable only to *add* restrictions), keeping the UI for visibility rather than authorship. Match the pattern `SpecialServiceResource` already uses for the catalog (`canCreate`/`canDelete` false).

## Acceptance criteria

- [ ] Ship page rate cards show applied special services per rate and flag stripped default-mode services with a reason (out of scope for carrier service / country-restricted)
- [ ] Carrier exclusions driven by required/compliance codes are surfaced to the operator (not just absent rates)
- [ ] Special Services list shows per-service carrier-service scoping at a glance
- [ ] Scope-row ownership decision implemented and documented in the relation manager (seeded rows protected from drift)
- [ ] Pest tests for the Livewire/Filament surfaces (table columns, ship-page indicators)

## Blocked by

None - can start immediately (more useful once issues 01–02 add real scoped services to display)

## Comments

**Implemented 2026-07-09** (awaiting review).

- Ship page: each rate card shows green badges for special services that will apply and struck-through gray badges (with tooltip) for requested services stripped by carrier-service scoping. Data computed in `EloquentPackageShippingWorkflow::prepareRates()`.
- Carrier exclusions were already notified as warnings; the new declared-value blocking error is notified too.
- Special Services list: new "Carrier Services" column summarizing scope per carrier (e.g. "FedEx (7), UPS (5)", tooltip lists services; "Unrestricted" when no rows).
- Scope-row ownership: **code-owned** — the CarrierService relation manager is now read-only (attach/edit/detach removed) with a description pointing at `CarrierServiceSpecialServiceSeeder`.

**Closed 2026-08-22.** Merged to `main` 2026-07-09 in PR #72 (`902a8582`) and live since. The
review this was parked on never happened as a separate pass; closing it retroactively rather
than leaving a gate open on shipped code.
