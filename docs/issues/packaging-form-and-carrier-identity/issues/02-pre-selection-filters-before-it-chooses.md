# Pre-selection filters before it chooses; `resolvePreSelectedRate()` becomes nullable

Status: needs-triage

Repo: `polybag`

## Parent

[ADR-0005](../../../adr/0005-packaging-form-and-carrier-identity.md), decision 4 — the
second site, and "the empty outcome is part of the contract".

## What to build

A shipping rule that pre-selects a service goes
`EloquentPackageShippingWorkflow::selectedRateForAutoShip()` → the adapter's
`resolvePreSelectedRate()` → `RateSelector::selectForAutomation()` and never passes
through `ShippingRateService`, so `01`'s filter does not cover it. Once `04` and `05`
let flat-rate rates survive as `exactly(…)` requirements, `UspsAdapter`'s "cheapest
variant" for a small box would be the flat-rate envelope and an automated rule would buy
it unseen. This slice closes that path **before** either of them opens it. Still no
observable behaviour change: every rate is `shipperPackaging()` and every Package's
packaging is null, so nothing is filtered yet and nothing returns null yet.

### The contract

`CarrierAdapterInterface::resolvePreSelectedRate(RateResponse $rate, Package $package): ?RateResponse`.
Null means "no compatible variant of this service exists for this Package". The docblock
says so, and says that the caller falls through to rate shopping.

### Five implementations

- **`UspsAdapter`** fetches its variants as today, runs `01`'s filter on the collection
  against `$package->boxSize?->carrier_packaging` (null until `03`, read through the
  same accessor `PackageData::fromPackage()` will use), and returns the cheapest of what
  remains — or null when nothing does. When the fetch itself comes back empty it falls to
  the synthetic-rate case below rather than returning the rule's rate unconditionally.
- **`FedexAdapter`, `UpsAdapter`, `AmazonBuyShippingAdapter`, `FakeCarrierAdapter`**
  return the rate as-is today. They now pass it through the filter first: the rule's
  synthetic rate carries `shipperPackaging()` (a rule names a service, never a packaging —
  `RuleEvaluator` sets it explicitly in `01`), so for a Package in carrier packaging the
  filter drops it and the adapter returns null.

The filter call is the same one-liner in all five; resist a base class for it.

### The caller

`selectedRateForAutoShip()` treats null as "no pre-selection": it logs at `info` with the
rule's carrier, service and the Package's packaging, then continues into the rate-shopping
branch exactly as if `hasPreSelectedRate()` had been false, where `01`'s filter runs on
real rates. `excludedServiceCodes` from the rule still apply on that path.

### Release note

A rule that pre-selects a service the Package's packaging cannot use now rate-shops
instead of buying. Nothing can trigger that until `03`, but the contract changes here,
so the line goes in this PR.

## Acceptance criteria

- [ ] The interface returns `?RateResponse` and all five implementations compile under
      PHPStan with no `@phpstan-ignore`
- [ ] `UspsAdapter::resolvePreSelectedRate()` test: variants `[SP, exactly(UspsFlatRateEnvelope)]`
      where the flat-rate one is cheaper, Package in shipper packaging → the `SP` variant
      is returned, not the cheapest
- [ ] `UspsAdapter::resolvePreSelectedRate()` test: a Package whose box size declares
      carrier packaging and variants that are all `shipperPackaging()` → null. (Until `03`
      lands, build this with a hand-set packaging rather than a column — or land it in
      `03`'s PR; say which)
- [ ] For each of the other four adapters: a `shipperPackaging()` rule rate against a
      Package with no packaging is returned unchanged; against a Package with carrier
      packaging (same caveat) returns null
- [ ] `EloquentPackageShippingWorkflow` feature test: a rule pre-selects a service, the
      adapter returns null, and the workflow buys through rate shopping instead — asserting
      both the log line and that `ShippingRateService::getShippingRates()` was reached
- [ ] Existing auto-ship and batch-ship suites pass unchanged
- [ ] Release-note line in the PR description
- [ ] `vendor/bin/pint --dirty --format agent` clean

## Blocked by

- [`01`](01-packaging-requirement-on-every-rate-and-the-shared-filter.md) — the filter and
  the requirement it reads
