# `adult_signature_required` end-to-end

Status: done
Type: AFK

## Parent

`docs/issues/archive/special-services/implementation-priorities.md` (Wave 1)

## What to build

Extend the signature plumbing from issue 01 with the adult variant, so age-restricted shipments carry an adult-signature requirement on all three carriers. Also the compliance backstop that `alcohol` (issue 05) auto-pairs with.

Carrier mapping:

- **USPS** — extra service code `922` (Adult Signature Required, 21+) + `physicalSignatureRequired`
- **UPS** — `DeliveryConfirmation` subtype Adult Signature Required (package-level code set)
- **FedEx** — `signatureOptionType = ADULT`; **US-only** → all FedEx scope rows get `restricted_countries: [US]`

## Acceptance criteria

- [ ] Scope rows seeded for all three carriers; FedEx rows restricted to US
- [ ] Adapters map the code in rate + ship requests and report it in `appliedServices`
- [ ] Mutual-exclusion handling: `adult_signature_required` supersedes `signature_required` when both resolve for a package (send only the adult variant, never both)
- [ ] Pest feature + adapter unit tests, mirroring issue 01
- [ ] `adult_signature_required` added to `$wiredCodes` as the final step

## Blocked by

- `01-signature-required.md`

## Comments

**Implemented 2026-07-09** (awaiting review + sandbox verification).

- USPS 922 (STC-valid GA/PM/PME — scope rows include PME), UPS `DCISType = 3`, FedEx `signatureOptionType = ADULT` with US-only scope rows.
- Supersede rule implemented centrally: `SpecialServiceResolver::normalizeCodes()` + `ShippingRateService` drop `signature_required` whenever `adult_signature_required` resolves, and adapters map adult first. Covered by tests including the case where signature is unscoped for a carrier but adult covers the requirement.

**Sandbox-verified 2026-07-09.** 922 + 931 combined label purchased and voided via the adapter path (Adult Signature $9.70 + Insurance > $500 $11.95 line items). FedEx `ADULT` confirmed in production `signatureOptionsList`.

**Closed 2026-08-22.** Merged to `main` 2026-07-09 in PR #72 (`902a8582`) and live since. The
review this was parked on never happened as a separate pass; closing it retroactively rather
than leaving a gate open on shipped code.
