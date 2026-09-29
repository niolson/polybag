# A UPS or FedEx *Use* rule buys nothing when the order has a due-by date

Status: needs-triage

Repo: `polybag`

Severity: medium. Automation never buys what the rule names for UPS or FedEx on a method
with a commitment, and every seeded method has one. The refusal message says no rate
arrives on time, which is wrong and sends the operator to the shipping method.
Verified: confirmed (test below fails on `main` at `6d47232`).

## Problem

A *Use* rule naming a direct service is pre-selected without rate shopping.
`RuleEvaluator::directResult()` builds a placeholder `RateResponse`: price `0.0` and no
delivery date. `selectedRateForAutoShip()` asks the carrier's adapter to resolve it.

- `UspsAdapter::resolvePreSelectedRate()` quotes the service, so the result has a price
  and a delivery date.
- `UpsAdapter` and `FedexAdapter` only filter the placeholder by packaging and return it
  as it is. It still has no delivery date.

The rule's choice then goes through `RateSelector::selectForAutomation()` "because a
rule's choice is still unattended". There, `isOnTime()` counts a rate with no delivery
date as late whenever a deadline exists. `OfferRequirements::refusesAsLate()` refuses it
when the method has *Exclude rates that deliver after the due-by date*, which defaults to
on. It isn't a fall-through, either: the selection returns no rate, and autoShip reports
"No On-Time Rates".

A deadline exists when the Shipment has `deliver_by` or its method has `commitment_days`.
`ShippingMethodSeeder` gives every method a commitment (1, 2 or 5 days), so on a default
install a *Use Direct, UPS Ground* rule never buys through batch ship or auto-ship, even
when UPS quotes Ground as arriving the next day.

## Evidence

In `tests/Feature/ShippingRuleSourceTest.php`'s setup, with an adapter whose
`resolvePreSelectedRate()` returns its argument, as UPS and FedEx do:

```php
it('buys a UPS/FedEx-style pre-selected direct rate for a shipment with a due-by date', function (): void {
    $this->package->shipment->update(['deliver_by' => now()->addDays(5)]);
    $quoted = new RateResponse(
        carrier: 'MockCarrier', serviceCode: 'GROUND', serviceName: 'Ground', price: 9.00,
        deliveryDate: now()->addDays(2)->toDateString(),
        carrierServiceId: $this->ground->id, carrierId: $this->ground->carrier_id,
    );
    $adapter = Mockery::mock(DirectCarrierAdapter::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('isConfigured')->andReturnTrue();
    $adapter->shouldReceive('prepareRateRequest')->andReturnNull();
    $adapter->shouldReceive('getRates')->andReturn(collect([$quoted]));
    $adapter->shouldReceive('resolvePreSelectedRate')->andReturnUsing(fn (RateResponse $r): RateResponse => $r);
    $adapter->shouldReceive('createShipment')->andReturn(ShipResponse::success(
        trackingNumber: 'RULE123', cost: 9.00, carrier: 'MockCarrier', service: 'Ground', labelData: base64_encode('label'),
    ));
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    ShippingRule::factory()->source(ShippingRuleSource::Direct)->create([
        'shipping_method_id' => $this->method->id,
        'carrier_service_id' => $this->ground->id,
    ]);

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $this->package,
        new PackageAutoShippingRequest(userId: auth()->id(), cleanupOnFailure: false),
    );

    expect($result->success)->toBeTrue(); // fails: "No On-Time Rates … none of this package's rates does"
});
```

## What to build

A rule's choice should be judged on a real quote. Two ways to get one:

- Make UPS and FedEx resolve a pre-selected rate the way USPS does: quote the one
  service and keep the cheapest compatible variant. The rule's rate is then priced and
  dated.
- Or drop the pre-selected-rate path and route a direct *Use* rule through a
  `RuleRateScope` (`kinds: [Direct]`, the service, not strict), which `directResult()`
  already does for Amazon Shipping. The rule then selects among the rates rate shopping
  quoted, with their dates. This also removes the need for
  `offerForUnquotedRate()` (`02`) and for the placeholder price, and it fixes `17` along
  the way, because the scope path already applies exclusions.

The second is smaller and fits `05`. Add the test above, and one against the real
`UpsAdapter` with a quoted delivery date.

## Comments
