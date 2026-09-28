# Amazon Buy Shipping treats a method's default special service as required

Status: needs-triage

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
