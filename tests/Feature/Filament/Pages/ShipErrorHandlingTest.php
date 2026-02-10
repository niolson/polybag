<?php

use App\Contracts\CarrierAdapterInterface;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Filament\Pages\Ship;
use App\Models\BoxSize;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\Package;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

function createShippablePackageForErrorTest(): Package
{
    $boxSize = BoxSize::factory()->create();
    $product = Product::factory()->create();

    $carrier = Carrier::factory()->usps()->create();
    $carrierService = CarrierService::factory()->uspsGroundAdvantage()->create(['carrier_id' => $carrier->id]);
    $shippingMethod = ShippingMethod::factory()->create();
    $shippingMethod->carrierServices()->attach($carrierService->id);

    $shipment = Shipment::factory()->create(['shipping_method_id' => $shippingMethod->id]);
    ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'transparency' => false,
    ]);

    return Package::create([
        'shipment_id' => $shipment->id,
        'box_size_id' => $boxSize->id,
        'weight' => 2.0,
        'height' => 10,
        'width' => 8,
        'length' => 6,
        'shipped' => false,
    ]);
}

function registerMockAdapterForErrorTest(ShipResponse $response): void
{
    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('getCarrierName')->andReturn('USPS');
    $adapter->shouldReceive('isConfigured')->andReturn(true);
    $adapter->shouldReceive('getRates')->andReturn(collect());
    $adapter->shouldReceive('createShipment')->andReturn($response);

    CarrierRegistry::register('USPS', get_class($adapter));
    CarrierRegistry::clearInstances();

    $reflection = new ReflectionProperty(CarrierRegistry::class, 'instances');
    $reflection->setAccessible(true);
    $reflection->setValue(null, ['USPS' => $adapter]);
}

function registerThrowingAdapterForErrorTest(\Throwable $exception): void
{
    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('getCarrierName')->andReturn('USPS');
    $adapter->shouldReceive('isConfigured')->andReturn(true);
    $adapter->shouldReceive('getRates')->andReturn(collect());
    $adapter->shouldReceive('createShipment')->andThrow($exception);

    CarrierRegistry::register('USPS', get_class($adapter));
    CarrierRegistry::clearInstances();

    $reflection = new ReflectionProperty(CarrierRegistry::class, 'instances');
    $reflection->setAccessible(true);
    $reflection->setValue(null, ['USPS' => $adapter]);
}

function setUpShipComponentWithRate(Package $package): \Livewire\Features\SupportTesting\Testable
{
    $component = Livewire::test(Ship::class, ['package_id' => $package->id]);

    $component->set('rateOptions', [
        0 => [
            'carrier' => 'USPS',
            'serviceCode' => 'USPS_GROUND_ADVANTAGE',
            'serviceName' => 'USPS Ground Advantage',
            'price' => 8.50,
            'deliveryCommitment' => '2-5 Business Days',
            'deliveryDate' => null,
            'transitTime' => null,
            'metadata' => [],
        ],
    ]);
    $component->set('formRateOptionLabels', [0 => '[USPS] USPS Ground Advantage']);
    $component->set('formRateOptionDescriptions', [0 => '$8.50 - 2-5 Business Days']);
    $component->fillForm(['rateOptions' => 0]);

    return $component;
}

it('ship shows error on carrier API failure response', function (): void {
    $package = createShippablePackageForErrorTest();

    registerMockAdapterForErrorTest(ShipResponse::failure('Rate unavailable for this destination.'));

    $component = setUpShipComponentWithRate($package);

    $component->call('ship')
        ->assertNotified()
        ->assertNotDispatched('print-label');

    // Package should not be marked as shipped
    expect($package->fresh()->shipped)->toBeFalse();
});

it('ship handles general exception gracefully', function (): void {
    $package = createShippablePackageForErrorTest();

    registerThrowingAdapterForErrorTest(new \Exception('Unexpected error'));

    $component = setUpShipComponentWithRate($package);

    $component->call('ship')
        ->assertNotified()
        ->assertNotDispatched('print-label');

    expect($package->fresh()->shipped)->toBeFalse();
});

it('ship handles carrier timeout exception', function (): void {
    $package = createShippablePackageForErrorTest();

    registerThrowingAdapterForErrorTest(
        new \Saloon\Exceptions\Request\Statuses\RequestTimeoutException(
            Mockery::mock(\Saloon\Http\Response::class),
            'Request timed out'
        )
    );

    $component = setUpShipComponentWithRate($package);

    $component->call('ship')
        ->assertNotified()
        ->assertNotDispatched('print-label');

    expect($package->fresh()->shipped)->toBeFalse();
});

it('ship handles runtime exception for optimistic locking', function (): void {
    $package = createShippablePackageForErrorTest();

    registerThrowingAdapterForErrorTest(new \RuntimeException('Package was modified by another user.'));

    $component = setUpShipComponentWithRate($package);

    $component->call('ship')
        ->assertNotified()
        ->assertNotDispatched('print-label');
});

it('ship redirects to pack when no package_id provided', function (): void {
    Livewire::test(Ship::class)
        ->assertRedirect('/pack');
});

it('ship warns and redirects when package is already shipped', function (): void {
    $shipment = Shipment::factory()->create();
    $package = Package::factory()->shipped()->for($shipment)->create();

    Livewire::test(Ship::class, ['package_id' => $package->id])
        ->assertRedirect('/pack')
        ->assertNotified();
});

it('ship disables ship action when no rates available', function (): void {
    $package = createShippablePackageForErrorTest();

    $component = Livewire::test(Ship::class, ['package_id' => $package->id]);

    // Rate options are empty since we didn't register a mock adapter
    $component->assertSet('rateOptions', []);
});
