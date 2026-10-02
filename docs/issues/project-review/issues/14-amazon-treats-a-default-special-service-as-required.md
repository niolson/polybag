# Amazon Buy Shipping treats a method's default special service as required

Status: done — 2026-10-01

Repo: `polybag`

Severity: medium. A method that *prefers* signature confirmation loses every Amazon offer
without a Confirmation group (OnTrac and others, often the cheapest). The Ship page never
shows them and automation buys something more expensive, or nothing.
Verified: confirmed (test below fails on `main` at `6d47232`).

## Problem

A shipping method attaches special services in two modes. **Required** must be honoured
or the offer is excluded (ADR-0002 decision 8). **Default** is a preference: an offer that
can't express it keeps its place and is bought without it.
`ShippingRateService::buildTask()` says so ("A preference this offer cannot express is
dropped, not fatal"), and the direct adapters behave that way.

The Buy Shipping task receives required and default codes as one list,
`RateRequest::$specialServiceCodes`. `AmazonBuyShippingAdapter::honoursRequiredServices()`
then drops every offer whose value-added service groups can't provide *each* code in that
list. So a default `signature_required` excludes OnTrac exactly as a required one would.
The method's own docblock contradicts this: it says a default nobody can honour "is
dropped from the purchase instead", which `confirmationPreferences()` does at purchase.
Quoting never gets that far.

The purchase side has a mirror of the same confusion.
`SpecialServiceResolver::resolveForPackageAndRate()` filters default codes through the
**catalog** special-service scoping of the mapped `CarrierService`. ADR-0006 decision 10
says that scoping applies to direct offers only. A mapped Amazon offer can therefore be
quoted with a default the offer supports and bought without it.

## Evidence

In `tests/Feature/AmazonBuyShippingTest.php`:

```php
it('keeps an Amazon offer that cannot add a signature the method only prefers', function (): void {
    (new SpecialServiceSeeder)->run();
    Saloon::fake([GetShippingRates::class => amazonRatesResponse()]);

    amazonShippingMethodFor($this->package);
    $this->package->shipment->fresh()->shippingMethod->specialServices()->attach(
        SpecialService::where('code', 'signature_required')->value('id'),
        ['mode' => 'default'],
    );

    $rates = app(ShippingRateService::class)->getShippingRates($this->package->id);

    // OnTrac offers no Confirmation group; a preference must not remove it.
    expect($rates->pluck('carrier')->all())->toContain('OnTrac');   // fails: ['UPS']
});
```

With no special service attached, the same test passes. The existing
`drops an offer that cannot honour a hard-required signature` test covers the required case
and should keep passing.

## What to build

- Carry required and default codes separately to the adapter. `RateRequest` could gain
  `requiredSpecialServiceCodes`, or `buildTask()` could pass the two lists. Then
  `honoursRequiredServices()` checks only the required ones.
- Keep asking for the defaults at purchase where the offer offers them, as
  `confirmationPreferences()` already does.
- In `resolveForPackageAndRate()`, apply catalog scoping to direct rates only, and leave
  an Amazon offer's defaults to its own value-added service groups.

## Comments

- 2026-10-01 — Fixed as proposed. `RateRequest` gained `requiredSpecialServiceCodes`, the
  subset of `specialServiceCodes` an offer must honour. `ShippingRateService::buildTask()`
  fills it with the codes that survived its required loop, and both per-task requests
  pass it through `withSpecialServiceCodes($codes, $requiredCodes)`. A call that leaves
  out the second argument keeps the codes that were already required and are still asked
  for. It is left out of `fingerprint()`: it splits codes the digest already covers,
  and decides which offers are listed, not what any of them costs.
  `AmazonBuyShippingAdapter::honoursRequiredServices()` now checks only the required
  codes. Defaults are still asked for at purchase where the offer has the group, as before.
  `SpecialServiceResolver::resolveForPackageAndRate()` looks up the catalog service only
  for a rate whose `sourceKind()` is `Direct`, so an Amazon offer keeps its defaults.
  Regression tests in `AmazonBuyShippingTest`: the Evidence test, which now expects
  `['OnTrac', 'UPS']`, plus a required-mode counterpart through `ShippingRateService`
  that still drops OnTrac. In `SpecialServiceResolverTest`, an Amazon-quoted rate mapped
  to a scoped-out service keeps its default, and the same service quoted direct does not.
  The Evidence test and the resolver test fail without the fix. The existing hard-required
  adapter test now marks its code as required.
- 2026-10-01 — A review of the first pass found three gaps, each confirmed by a failing
  test. (1) A required plain signature beside a preferred adult one was dropped as
  superseded, so Amazon admitted offers with no signature at all.
  `SpecialServiceResolver::supersedeByMode()` now lets the adult signature replace the
  plain one only when it is at least as binding. `resolveByModeForPackage()` gives
  rating, purchase and the fingerprint the same split. `buildTask()` still sends a direct
  carrier one signature. An Amazon offer keeps both, and the purchase buys the strongest
  the offer has. The filter does not accept an adult signature for a required plain one:
  a later review found that the purchase never asks for that substitute, so an offer with
  only `ADULT_SIGNATURE_CONFIRMATION` answered its required group with
  `NO_CONFIRMATION` (test: `drops an offer that can add only an adult signature when a
  plain one is required`).
  (2) `requiredSpecialServiceCodes` was left out of `fingerprint()`, so an offer quoted
  while a signature was preferred stayed spendable after it became required.
  `fromPackage()` now fills the field and the fingerprint covers it. (3) The purchase-side
  scoping change never took effect, because `rateFromOffer()` rebuilds the rate without
  its observed identity, so every redeemed rate read as direct.
  `resolveForPackageAndRate()` now takes the offer, and `ShipRequest` passes it. The
  offer's `postage_source` decides direct or not. Tests: `holds a required signature
  when the method only prefers an adult one`, `retires an offer when a special service
  it was quoted as preferring becomes required`, and `buys a preferred signature on a
  mapped Amazon offer whatever the catalog scopes`.
