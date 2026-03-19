<?php

use App\Contracts\CarrierAdapterInterface;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\ShippingRuleAction;
use App\Exceptions\NoActiveCarrierServicesException;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Models\ShippingRule;
use App\Services\Carriers\CarrierRegistry;
use App\Services\LabelGenerationService;

beforeEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

afterEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

it('returns failure when no rates available', function (): void {
    // Create a shipment with no carrier services configured
    $method = ShippingMethod::factory()->create();
    $shipment = Shipment::factory()->create(['shipping_method_id' => $method->id]);
    $package = Package::factory()->for($shipment)->create();

    // No carrier services attached to the method = NoActiveCarrierServicesException
    // which should bubble up as a failure
    expect(fn () => app(LabelGenerationService::class)->generateLabel($package))
        ->toThrow(NoActiveCarrierServicesException::class);
});

it('applies rule pre-selection and skips rate shopping', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'MockCarrier', 'active' => true]);
    $service = CarrierService::factory()->create([
        'carrier_id' => $carrier->id,
        'name' => 'Priority Mail',
        'service_code' => 'PRIORITY_MAIL',
        'active' => true,
    ]);
    $method = ShippingMethod::factory()->create();
    $method->carrierServices()->attach($service->id);
    $shipment = Shipment::factory()->create(['shipping_method_id' => $method->id]);
    $package = Package::factory()->for($shipment)->create();

    ShippingRule::factory()->create([
        'shipping_method_id' => $method->id,
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $service->id,
    ]);

    $mockResponse = ShipResponse::success(
        trackingNumber: 'RULE123',
        cost: 7.50,
        carrier: 'MockCarrier',
        service: 'Priority Mail',
        labelData: base64_encode('fake-label'),
    );

    $mockAdapter = Mockery::mock(CarrierAdapterInterface::class);
    $mockAdapter->shouldReceive('resolvePreSelectedRate')->once()->andReturnUsing(fn ($rate) => $rate);
    $mockAdapter->shouldReceive('createShipment')->once()->andReturn($mockResponse);

    app(CarrierRegistry::class)->registerInstance('MockCarrier', $mockAdapter);

    $result = app(LabelGenerationService::class)->generateLabel($package);

    expect($result->success)->toBeTrue()
        ->and($result->response->trackingNumber)->toBe('RULE123')
        ->and($result->selectedRate->serviceCode)->toBe('PRIORITY_MAIL');
});

it('returns failure when carrier returns unsuccessful response', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'MockCarrier', 'active' => true]);
    $service = CarrierService::factory()->create([
        'carrier_id' => $carrier->id,
        'name' => 'Test Service',
        'service_code' => 'TEST',
        'active' => true,
    ]);
    $method = ShippingMethod::factory()->create();
    $method->carrierServices()->attach($service->id);
    $shipment = Shipment::factory()->create(['shipping_method_id' => $method->id]);
    $package = Package::factory()->for($shipment)->create();

    // UseService rule to skip rate shopping (avoids needing a real rate service)
    ShippingRule::factory()->create([
        'shipping_method_id' => $method->id,
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $service->id,
    ]);

    $mockAdapter = Mockery::mock(CarrierAdapterInterface::class);
    $mockAdapter->shouldReceive('resolvePreSelectedRate')->once()->andReturnUsing(fn ($rate) => $rate);
    $mockAdapter->shouldReceive('createShipment')->once()->andReturn(
        ShipResponse::failure('Address validation failed')
    );

    app(CarrierRegistry::class)->registerInstance('MockCarrier', $mockAdapter);

    $result = app(LabelGenerationService::class)->generateLabel($package);

    expect($result->success)->toBeFalse()
        ->and($result->errorMessage)->toBe('Address validation failed');
});

it('passes label format and DPI to ship request', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'MockCarrier', 'active' => true]);
    $service = CarrierService::factory()->create([
        'carrier_id' => $carrier->id,
        'name' => 'Test Service',
        'service_code' => 'TEST',
        'active' => true,
    ]);
    $method = ShippingMethod::factory()->create();
    $method->carrierServices()->attach($service->id);
    $shipment = Shipment::factory()->create(['shipping_method_id' => $method->id]);
    $package = Package::factory()->for($shipment)->create();

    ShippingRule::factory()->create([
        'shipping_method_id' => $method->id,
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $service->id,
    ]);

    $mockAdapter = Mockery::mock(CarrierAdapterInterface::class);
    $mockAdapter->shouldReceive('resolvePreSelectedRate')->once()->andReturnUsing(fn ($rate) => $rate);
    $mockAdapter->shouldReceive('createShipment')
        ->once()
        ->withArgs(function ($shipRequest) {
            return $shipRequest->labelFormat === 'zpl' && $shipRequest->labelDpi === 203;
        })
        ->andReturn(ShipResponse::success(
            trackingNumber: 'ZPL123',
            cost: 5.00,
            carrier: 'MockCarrier',
            service: 'Test Service',
            labelFormat: 'zpl',
            labelDpi: 203,
        ));

    app(CarrierRegistry::class)->registerInstance('MockCarrier', $mockAdapter);

    $result = app(LabelGenerationService::class)->generateLabel($package, 'zpl', 203);

    expect($result->success)->toBeTrue()
        ->and($result->response->labelFormat)->toBe('zpl');
});
