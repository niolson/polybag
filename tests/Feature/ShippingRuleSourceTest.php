<?php

use App\Contracts\DirectCarrierAdapter;
use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageShipping\PackageAutoShippingRequest;
use App\DataTransferObjects\PostageSources\ObservedServiceIdentity;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\PackageStatus;
use App\Enums\PostageSourceKind;
use App\Enums\ShippingRuleSource;
use App\Enums\UnlistedServices;
use App\Http\Integrations\Ups\Requests\CreateShipment as UpsCreateShipment;
use App\Http\Integrations\Ups\Requests\Rate as UpsRate;
use App\Models\BoxSize;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\Package;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShippingMethod;
use App\Models\ShippingMethodPostageSource;
use App\Models\ShippingRule;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

/*
|--------------------------------------------------------------------------
| A rule names a source and a service — `carrier-catalog-reset/07`
|--------------------------------------------------------------------------
|
| One service can be bought on a carrier account and resold through Amazon,
| at different prices. These tests quote both from one mock adapter, so the
| only thing telling them apart is the source each rate names.
|
*/

beforeEach(function (): void {
    app(CarrierRegistry::class)->reset();
    $this->actingAs(User::factory()->create());

    $carrier = Carrier::factory()->create(['name' => 'MockCarrier', 'active' => true]);
    $this->ground = CarrierService::factory()->create([
        'carrier_id' => $carrier->id,
        'name' => 'Ground',
        'service_code' => 'GROUND',
        'active' => true,
    ]);
    $this->express = CarrierService::factory()->create([
        'carrier_id' => $carrier->id,
        'name' => 'Express',
        'service_code' => 'EXPRESS',
        'active' => true,
    ]);

    $this->method = ShippingMethod::factory()->create();
    $this->method->carrierServices()->attach([$this->ground->id, $this->express->id]);
    ShippingMethodPostageSource::factory()->amazon()->for($this->method)->create();
    $this->package = sourceRulePackage($this->method);
});

afterEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

function sourceRulePackage(ShippingMethod $method): Package
{
    $product = Product::factory()->create(['weight' => 1.5]);
    $shipment = Shipment::factory()->create(['shipping_method_id' => $method->id]);
    $shipmentItem = ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    $package = Package::factory()->for($shipment)->create([
        'box_size_id' => BoxSize::factory()->create()->id,
        'weight' => 2.0,
        'height' => 10,
        'width' => 8,
        'length' => 6,
        'status' => PackageStatus::Unshipped,
    ]);

    $package->packageItems()->create([
        'shipment_item_id' => $shipmentItem->id,
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    return $package;
}

function sourceRuleDirectRate(CarrierService $service, float $price): RateResponse
{
    return new RateResponse(
        carrier: 'MockCarrier',
        serviceCode: $service->service_code,
        serviceName: $service->name,
        price: $price,
        carrierServiceId: $service->id,
        carrierId: $service->carrier_id,
    );
}

/**
 * Amazon's offer of a mapped service: it carries the mapped carrier and code,
 * exactly as the direct rate for the same service does.
 */
function sourceRuleAmazonRate(CarrierService $service, float $price): RateResponse
{
    return new RateResponse(
        carrier: 'MockCarrier',
        serviceCode: $service->service_code,
        serviceName: $service->name,
        price: $price,
        observedService: new ObservedServiceIdentity(
            source: 'amazon',
            externalCarrierId: 'MOCK',
            externalServiceId: $service->service_code,
        ),
        carrierServiceId: $service->id,
        carrierId: $service->carrier_id,
    );
}

/**
 * Quotes the given rates, and buys whichever the workflow hands it at its own
 * price, so the cost recorded says which rate was bought.
 *
 * @param  array<int, RateResponse>  $rates
 */
function sourceRuleAdapter(array $rates): void
{
    $adapter = Mockery::mock(DirectCarrierAdapter::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('isConfigured')->andReturnTrue();
    $adapter->shouldReceive('prepareRateRequest')->andReturnNull();
    $adapter->shouldReceive('getRates')->andReturn(collect($rates));
    $adapter->shouldReceive('createShipment')->andReturnUsing(fn (ShipRequest $request): ShipResponse => ShipResponse::success(
        trackingNumber: 'RULE123',
        cost: $request->selectedRate->price,
        carrier: 'MockCarrier',
        service: $request->selectedRate->serviceName,
        labelData: base64_encode('label'),
    ));

    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);
}

function autoShipUnderRule(Package $package): Package
{
    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package,
        new PackageAutoShippingRequest(userId: auth()->id(), cleanupOnFailure: false),
    );

    expect($result->success)->toBeTrue("{$result->title}: {$result->message}");

    return $package->fresh();
}

it('highlights and buys the direct rate for Direct, a service, though Amazon\'s is cheaper', function (): void {
    sourceRuleAdapter([sourceRuleAmazonRate($this->ground, 4.00), sourceRuleDirectRate($this->ground, 9.00)]);

    ShippingRule::factory()->source(ShippingRuleSource::Direct)->create([
        'shipping_method_id' => $this->method->id,
        'carrier_service_id' => $this->ground->id,
    ]);

    $options = app(PackageShippingWorkflow::class)->prepareRates($this->package);
    $selected = $options->rateOptions[$options->selectedRateIndex];

    expect($selected['price'])->toBe(9.00)
        ->and($selected['observedService'])->toBeNull()
        ->and(autoShipUnderRule($this->package)->cost)->toEqual(9.00);
});

it('buys the cheaper of direct and Amazon for Any priced source, a service', function (): void {
    sourceRuleAdapter([
        sourceRuleDirectRate($this->ground, 9.00),
        sourceRuleAmazonRate($this->ground, 4.00),
        sourceRuleDirectRate($this->express, 1.00),
    ]);

    ShippingRule::factory()->source(ShippingRuleSource::AnyPriced)->create([
        'shipping_method_id' => $this->method->id,
        'carrier_service_id' => $this->ground->id,
    ]);

    $options = app(PackageShippingWorkflow::class)->prepareRates($this->package);

    expect($options->rateOptions[$options->selectedRateIndex]['price'])->toBe(4.00)
        ->and(autoShipUnderRule($this->package)->cost)->toEqual(4.00);
});

it('falls through to rate shopping when no source quotes an Any priced source service', function (): void {
    sourceRuleAdapter([sourceRuleDirectRate($this->express, 7.00)]);

    ShippingRule::factory()->source(ShippingRuleSource::AnyPriced)->create([
        'shipping_method_id' => $this->method->id,
        'carrier_service_id' => $this->ground->id,
    ]);

    expect(autoShipUnderRule($this->package)->cost)->toEqual(7.00);
});

it('rate shops when a Use rule names a service the method does not list', function (): void {
    $unlisted = CarrierService::factory()->create(['carrier_id' => $this->ground->carrier_id, 'service_code' => 'OVERNIGHT']);
    sourceRuleAdapter([sourceRuleDirectRate($this->ground, 6.00), sourceRuleDirectRate($unlisted, 2.00)]);

    ShippingRule::factory()->create(['carrier_service_id' => $unlisted->id]);

    expect(autoShipUnderRule($this->package)->cost)->toEqual(6.00);
});

it('removes a service from every source for Exclude, any source', function (): void {
    sourceRuleAdapter([
        sourceRuleDirectRate($this->ground, 9.00),
        sourceRuleAmazonRate($this->ground, 4.00),
        sourceRuleDirectRate($this->express, 12.00),
    ]);

    ShippingRule::factory()->excludeService()->create([
        'shipping_method_id' => $this->method->id,
        'carrier_service_id' => $this->ground->id,
    ]);

    $options = app(PackageShippingWorkflow::class)->prepareRates($this->package);

    expect(collect($options->rateOptions)->pluck('serviceCode')->all())->toBe(['EXPRESS'])
        ->and(autoShipUnderRule($this->package)->cost)->toEqual(12.00);
});

it('removes every Amazon offer a carrier carries, mapped or not, even under any service', function (): void {
    // *Any service* would otherwise let automation buy the unmapped one.
    $this->method->postageSourceFor(PostageSourceKind::Amazon)->update(['unlisted_services' => UnlistedServices::Any]);
    $onTrac = Carrier::factory()->create(['name' => 'OnTrac']);
    $onTracGround = CarrierService::factory()->create(['carrier_id' => $onTrac->id, 'service_code' => 'ONTRAC_GROUND']);
    $unmapped = new RateResponse(
        carrier: 'OnTrac',
        serviceCode: 'ONTRAC_NEXT_DAY',
        serviceName: 'OnTrac Next Day',
        price: 3.00,
        observedService: new ObservedServiceIdentity(
            source: 'amazon',
            externalCarrierId: 'ONTRAC',
            externalServiceId: 'ONTRAC_NEXT_DAY',
        ),
        carrierId: $onTrac->id,
    );

    sourceRuleAdapter([$unmapped, sourceRuleAmazonRate($onTracGround, 3.50), sourceRuleDirectRate($this->ground, 9.00)]);

    ShippingRule::factory()->excludeCarrier($onTrac, ShippingRuleSource::Amazon)->create([
        'shipping_method_id' => $this->method->id,
    ]);

    $options = app(PackageShippingWorkflow::class)->prepareRates($this->package);

    expect(collect($options->rateOptions)->pluck('serviceCode')->all())->toBe(['GROUND'])
        ->and(autoShipUnderRule($this->package)->cost)->toEqual(9.00);
});

// --- project-review/17 and 18 ------------------------------------------------

it('does not buy a pre-selected direct rate an earlier Exclude rule removes', function (): void {
    sourceRuleAdapter([sourceRuleDirectRate($this->ground, 9.00), sourceRuleDirectRate($this->express, 12.00)]);

    ShippingRule::factory()->excludeService()->create([
        'shipping_method_id' => $this->method->id,
        'carrier_service_id' => $this->ground->id,
        'priority' => 0,
    ]);
    ShippingRule::factory()->source(ShippingRuleSource::Direct)->create([
        'shipping_method_id' => $this->method->id,
        'carrier_service_id' => $this->ground->id,
        'priority' => 10,
    ]);

    $options = app(PackageShippingWorkflow::class)->prepareRates($this->package);
    expect(collect($options->rateOptions)->pluck('serviceCode')->all())->not->toContain('GROUND');

    $shipped = autoShipUnderRule($this->package);

    expect($shipped->service)->not->toBe('Ground')
        ->and($shipped->cost)->toEqual(12.00);
});

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

    expect($result->success)->toBeTrue("{$result->title}: {$result->message}");
});

it('buys a Direct UPS Use rule\'s service through the real UpsAdapter when the shipment has a due-by date', function (): void {
    $ups = Carrier::factory()->ups()->create(['active' => true]);
    $upsGround = CarrierService::factory()->upsGround()->for($ups)->create(['active' => true]);
    createUpsAccount();

    $method = ShippingMethod::factory()->create();
    $method->carrierServices()->attach($upsGround->id);
    $package = sourceRulePackage($method);
    $package->shipment->update(['deliver_by' => now()->addDays(5)]);

    ShippingRule::factory()->source(ShippingRuleSource::Direct)->create([
        'shipping_method_id' => $method->id,
        'carrier_service_id' => $upsGround->id,
    ]);

    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        UpsRate::class => MockResponse::make(['RateResponse' => ['RatedShipment' => [[
            'Service' => ['Code' => '03'],
            'TotalCharges' => ['MonetaryValue' => '11.00'],
            'TimeInTransit' => ['ServiceSummary' => ['EstimatedArrival' => [
                'BusinessDaysInTransit' => '2',
                'Arrival' => ['Date' => now()->addDays(2)->format('Ymd')],
            ]]],
        ]]]]),
        UpsCreateShipment::class => MockResponse::make(['ShipmentResponse' => ['ShipmentResults' => [
            'ShipmentIdentificationNumber' => '1Z9999999999999999',
            'ShipmentCharges' => ['TotalCharges' => ['MonetaryValue' => '11.00']],
            'PackageResults' => [
                'TrackingNumber' => '1Z9999999999999999',
                'ShippingLabel' => ['GraphicImage' => 'R0lGODlhAQABAAAAACw='],
            ],
        ]]]),
    ]);

    $shipped = autoShipUnderRule($package);

    expect($shipped->tracking_number)->toBe('1Z9999999999999999')
        ->and($shipped->cost)->toEqual(11.00);
});
