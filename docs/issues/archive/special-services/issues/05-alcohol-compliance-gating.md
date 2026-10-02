# `alcohol` compliance: FedEx-only gating + auto-paired adult signature

Status: done
Type: AFK

## Parent

`docs/issues/archive/special-services/implementation-priorities.md` (Wave 3)

## What to build

Activate `alcohol` so that packing a product flagged `contains_alcohol` produces: FedEx-only rates domestically, the `ALCOHOL` special service + `alcoholDetail` on the FedEx label, and an automatic adult-signature requirement. `SpecialServiceResolver::resolveProductRequiredCodes()` already emits the code once the service is active — the work is gating and adapter wiring.

Carrier posture (domestic):

- **FedEx** — `ALCOHOL` package-level special service; `alcoholDetail.alcoholRecipientType` is **required** once selected (`LICENSEE`/`CONSUMER`). Default `CONSUMER`; make it overridable via the service `config_schema` / client config.
- **UPS** — domestic small-package unsupported (ISC 205 is international/freight-only) → do not declare capability in `UpsAdapter`
- **USPS** — prohibited domestically → do not declare capability in `UspsAdapter`

Gating mechanism: the adapter **capability map** is the carrier-level gate (a required code an adapter doesn't support excludes that carrier's rates); scope rows handle service-level scoping within FedEx. Note the scoping model is default-allow per carrier (no rows for a carrier = unrestricted), so carrier exclusion must come from the capability maps, not from absent scope rows.

## Acceptance criteria

- [ ] Package containing a `contains_alcohol` product gets FedEx rates only; USPS/UPS excluded at rate time with the exclusion visible in rate diagnostics/logs
- [ ] FedEx ship request carries `ALCOHOL` + `alcoholRecipientType`; label purchase succeeds in the fake-adapter E2E path
- [ ] `adult_signature_required` auto-applied whenever `alcohol` resolves (compliance pairing), using the issue 02 plumbing
- [ ] Rate/label behavior covered by Pest feature tests (FakeCarrierAdapter) + FedEx adapter unit tests; FedEx ship-rejection handling designed defensively (precheck-first) since it is unrehearsable in sandbox
- [ ] `alcohol` added to `$wiredCodes` as the final step; flagged products keep shipping as before until then

## Blocked by

- `02-adult-signature-required.md`

## Comments

**Implemented 2026-07-09** (awaiting review; FedEx ship rejection path unrehearsable by design).

- Capability maps gate carriers: USPS Prohibited (27 CFR 72.11), UPS NotImplemented, FedEx Supported → flagged products get FedEx-only rates with the exclusion reason surfaced.
- FedEx sends `ALCOHOL` + `alcoholDetail.alcoholRecipientType = CONSUMER` (LICENSEE would need per-client config later); scope rows exclude Ground Economy (SmartPost prohibits alcohol).
- Adult signature auto-paired in `SpecialServiceResolver::resolveProductRequiredCodes()` (only when the signature service is active).

**Review fix 2026-07-09 (P2):** the adult-signature pairing originally ran *before* the active-service filter, so deactivating `alcohol` didn't fully disable alcohol compliance when `adult_signature_required` stayed active. Pairing now happens after the filter and only when alcohol itself survives it — deactivating the alcohol service is again a complete kill switch. Regression test added ("does not enforce adult signature for alcohol products while the alcohol service is inactive").

**Closed 2026-08-22.** Merged to `main` 2026-07-09 in PR #72 (`902a8582`) and live since. The
review this was parked on never happened as a separate pass; closing it retroactively rather
than leaving a gate open on shipped code.
