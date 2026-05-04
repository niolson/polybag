<?php

use App\Contracts\CarrierAdapterInterface;
use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageShipping\PackageAutoShippingRequest;
use App\DataTransferObjects\PackageShipping\PackageShippingRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\PackageStatus;
use App\Enums\ShippingRuleAction;
use App\Exceptions\NoActiveCarrierServicesException;
use App\Models\BoxSize;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\Package;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShippingMethod;
use App\Models\ShippingRule;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;

beforeEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

afterEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

function createWorkflowPackage(): Package
{
    $boxSize = BoxSize::factory()->create();
    $product = Product::factory()->create(['weight' => 1.5]);
    $carrier = Carrier::factory()->create(['name' => 'MockCarrier', 'active' => true]);
    $carrierService = CarrierService::factory()->create([
        'carrier_id' => $carrier->id,
        'name' => 'Ground',
        'service_code' => 'GROUND',
        'active' => true,
    ]);
    $shippingMethod = ShippingMethod::factory()->create();
    $shippingMethod->carrierServices()->attach($carrierService->id);
    ShippingRule::factory()->create([
        'shipping_method_id' => $shippingMethod->id,
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $carrierService->id,
    ]);
    $shipment = Shipment::factory()->create(['shipping_method_id' => $shippingMethod->id]);

    $shipmentItem = ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    $package = Package::factory()->for($shipment)->create([
        'box_size_id' => $boxSize->id,
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

it('prepares sorted rate options for a package', function (): void {
    $package = createWorkflowPackage();

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('isConfigured')->once()->andReturnTrue();
    $adapter->shouldReceive('prepareRateRequest')->once()->andReturnNull();
    $adapter->shouldReceive('getRates')->once()->andReturn(collect([
        new RateResponse('MockCarrier', 'EXPRESS', 'Express', 15.00, '1 day'),
        new RateResponse('MockCarrier', 'GROUND', 'Ground', 7.25, '3 days'),
    ]));

    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $options = app(PackageShippingWorkflow::class)->prepareRates($package);

    expect($options->rateOptions)->toHaveCount(2)
        ->and($options->rateOptions[0]['serviceCode'])->toBe('GROUND')
        ->and($options->selectedRateIndex)->toBe(0)
        ->and($options->rateOptionLabels[0])->toBe('[MockCarrier] Ground');
});

it('throws when a shipping method has no active carrier services', function (): void {
    $method = ShippingMethod::factory()->create();
    $shipment = Shipment::factory()->create(['shipping_method_id' => $method->id]);
    $package = Package::factory()->for($shipment)->create();

    expect(fn () => app(PackageShippingWorkflow::class)->prepareRates($package))
        ->toThrow(NoActiveCarrierServicesException::class);
});

it('returns a failure result and cleans up when auto ship rate lookup throws', function (): void {
    $method = ShippingMethod::factory()->create();
    $shipment = Shipment::factory()->create(['shipping_method_id' => $method->id]);
    $package = Package::factory()->for($shipment)->create(['status' => PackageStatus::Unshipped]);

    $result = app(PackageShippingWorkflow::class)->autoShip($package, new PackageAutoShippingRequest);

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Auto Ship Error')
        ->and(Package::find($package->id))->toBeNull();
});

it('ships a package with the selected rate and marks it shipped', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = createWorkflowPackage();
    $rate = new RateResponse('MockCarrier', 'GROUND', 'Ground', 7.25, '3 days');

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('createShipment')->once()->andReturn(
        ShipResponse::success(
            trackingNumber: 'TRACK123',
            cost: 7.25,
            carrier: 'MockCarrier',
            service: 'Ground',
            labelData: base64_encode('label'),
        )
    );

    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(selectedRate: $rate, userId: $user->id),
    );

    expect($result->success)->toBeTrue()
        ->and($result->printRequest)->not->toBeNull()
        ->and($package->fresh()->status)->toBe(PackageStatus::Shipped)
        ->and($package->fresh()->tracking_number)->toBe('TRACK123')
        ->and($package->fresh()->shipped_by_user_id)->toBe($user->id);
});

it('auto ships through a rule preselected rate', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = createWorkflowPackage();

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('resolvePreSelectedRate')->once()->andReturnUsing(fn (RateResponse $rate): RateResponse => $rate);
    $adapter->shouldReceive('createShipment')->once()->andReturn(
        ShipResponse::success(
            trackingNumber: 'AUTO123',
            cost: 7.25,
            carrier: 'MockCarrier',
            service: 'Ground',
            labelData: base64_encode('label'),
        )
    );

    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package,
        new PackageAutoShippingRequest(userId: $user->id, cleanupOnFailure: false),
    );

    expect($result->success)->toBeTrue()
        ->and($result->summaryMessage())->toContain('AUTO123')
        ->and($package->fresh()->status)->toBe(PackageStatus::Shipped);
});

it('passes label format and dpi into auto ship requests', function (): void {
    $package = createWorkflowPackage();

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('resolvePreSelectedRate')->once()->andReturnUsing(fn (RateResponse $rate): RateResponse => $rate);
    $adapter->shouldReceive('createShipment')
        ->once()
        ->withArgs(fn ($shipRequest): bool => $shipRequest->labelFormat === 'zpl' && $shipRequest->labelDpi === 203)
        ->andReturn(ShipResponse::success(
            trackingNumber: 'ZPL123',
            cost: 5.00,
            carrier: 'MockCarrier',
            service: 'Ground',
            labelFormat: 'zpl',
            labelDpi: 203,
        ));

    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package,
        new PackageAutoShippingRequest(labelFormat: 'zpl', labelDpi: 203),
    );

    expect($result->success)->toBeTrue()
        ->and($result->response->labelFormat)->toBe('zpl')
        ->and($result->response->labelDpi)->toBe(203);
});

it('can preserve an unshipped package when auto ship fails', function (): void {
    $package = createWorkflowPackage();

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('resolvePreSelectedRate')->once()->andReturnUsing(fn (RateResponse $rate): RateResponse => $rate);
    $adapter->shouldReceive('createShipment')->once()->andReturn(
        ShipResponse::failure('Address validation failed')
    );

    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package,
        new PackageAutoShippingRequest(cleanupOnFailure: false),
    );

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('Address validation failed')
        ->and($package->fresh())->not->toBeNull()
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('cleans up an unshipped package when auto ship fails by default', function (): void {
    $package = createWorkflowPackage();

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('resolvePreSelectedRate')->once()->andReturnUsing(fn (RateResponse $rate): RateResponse => $rate);
    $adapter->shouldReceive('createShipment')->once()->andReturn(
        ShipResponse::failure('Address validation failed')
    );

    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $result = app(PackageShippingWorkflow::class)->autoShip($package, new PackageAutoShippingRequest);

    expect($result->success)->toBeFalse()
        ->and(Package::find($package->id))->toBeNull();
});
