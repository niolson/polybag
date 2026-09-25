<?php

use App\Contracts\DirectCarrierAdapter;
use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageShipping\PackageAutoShippingRequest;
use App\DataTransferObjects\PostageSources\ObservedServiceIdentity;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\AmazonChannelType;
use App\Enums\PackageStatus;
use App\Enums\ShippingRuleSource;
use App\Enums\SourceEnvironment;
use App\Models\BoxSize;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\Package;
use App\Models\Product;
use App\Models\ServiceApproval;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShippingMethod;
use App\Models\ShippingRule;
use App\Models\User;
use App\Services\Carriers\AmazonBuyShippingAdapter;
use App\Services\Carriers\CarrierRegistry;

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

    // The hook row is what allows Amazon Buy Shipping on a method until `12`.
    $amazon = Carrier::firstOrCreate(['name' => AmazonBuyShippingAdapter::SOURCE_NAME]);
    $hookRow = CarrierService::firstOrCreate(
        ['carrier_id' => $amazon->id, 'service_code' => AmazonBuyShippingAdapter::CATALOG_SERVICE_CODE],
        ['name' => 'Amazon Buy Shipping'],
    );

    $this->method = ShippingMethod::factory()->create();
    $this->method->carrierServices()->attach([$this->ground->id, $this->express->id, $hookRow->id]);
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
            environment: SourceEnvironment::Production,
            channelType: AmazonChannelType::Amazon,
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
function sourceRuleAdapter(array $rates, ?RateResponse $preSelected = null): void
{
    $adapter = Mockery::mock(DirectCarrierAdapter::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('isConfigured')->andReturnTrue();
    $adapter->shouldReceive('prepareRateRequest')->andReturnNull();
    $adapter->shouldReceive('getRates')->andReturn(collect($rates));
    $adapter->shouldReceive('resolvePreSelectedRate')->andReturn($preSelected);
    $adapter->shouldReceive('createShipment')->andReturnUsing(fn (ShipRequest $request): ShipResponse => ShipResponse::success(
        trackingNumber: 'RULE123',
        cost: $request->selectedRate->price,
        carrier: 'MockCarrier',
        service: $request->selectedRate->serviceName,
        labelData: base64_encode('label'),
    ));

    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);
}

function approveAmazonFor(Package $package, CarrierService $service): void
{
    ServiceApproval::factory()->create([
        'source' => 'amazon',
        'environment' => SourceEnvironment::Production,
        'external_carrier_id' => 'MOCK',
        'external_service_id' => $service->service_code,
        'client_id' => $package->shipment->client_id,
    ]);
}

function autoShipUnderRule(Package $package): Package
{
    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package,
        new PackageAutoShippingRequest(userId: auth()->id(), cleanupOnFailure: false),
    );

    expect($result->success)->toBeTrue();

    return $package->fresh();
}

it('highlights and buys the direct rate for Direct, a service, though Amazon\'s is cheaper', function (): void {
    approveAmazonFor($this->package, $this->ground);
    $direct = sourceRuleDirectRate($this->ground, 9.00);
    sourceRuleAdapter([sourceRuleAmazonRate($this->ground, 4.00), $direct], preSelected: $direct);

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
    approveAmazonFor($this->package, $this->ground);
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
    // Only the rule's pre-selection would hand back the unlisted service.
    sourceRuleAdapter([sourceRuleDirectRate($this->ground, 6.00)], preSelected: sourceRuleDirectRate($unlisted, 2.00));

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

it('removes every Amazon offer a carrier carries, mapped or not', function (): void {
    $onTrac = Carrier::factory()->create(['name' => 'OnTrac']);
    $onTracGround = CarrierService::factory()->create(['carrier_id' => $onTrac->id, 'service_code' => 'ONTRAC_GROUND']);
    $unmapped = new RateResponse(
        carrier: 'OnTrac',
        serviceCode: 'ONTRAC_NEXT_DAY',
        serviceName: 'OnTrac Next Day',
        price: 3.00,
        observedService: new ObservedServiceIdentity(
            source: 'amazon',
            environment: SourceEnvironment::Production,
            channelType: AmazonChannelType::Amazon,
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

    expect(collect($options->rateOptions)->pluck('serviceCode')->all())->toBe(['GROUND']);
});
