<?php

use App\Contracts\DirectCarrierAdapter;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\PackageStatus;
use App\Enums\ServiceCapability;
use App\Filament\Pages\Ship;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use Livewire\Livewire;

beforeEach(function (): void {
    app(CarrierRegistry::class)->reset();
    $this->actingAs(User::factory()->admin()->create());
});

afterEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

/**
 * A USPS adapter that quotes two rates and sells one label, counting how many
 * it sold — the whole point of these tests is that the count stays at one.
 */
function registerCountingUspsAdapter(int &$purchases): void
{
    $adapter = Mockery::mock(DirectCarrierAdapter::class);
    $adapter->shouldReceive('getCarrierName')->andReturn('USPS');
    $adapter->shouldReceive('isConfigured')->andReturnTrue();
    $adapter->shouldReceive('prepareRateRequest')->andReturnNull();
    $adapter->shouldReceive('serviceCapability')->andReturn(ServiceCapability::Supported);
    $adapter->shouldReceive('offerCapability')->andReturn(ServiceCapability::Supported);
    $adapter->shouldReceive('offerDeclaredValueCap')->andReturnNull();
    $adapter->shouldReceive('getRates')->andReturn(collect([
        new RateResponse('USPS', 'USPS_GROUND_ADVANTAGE', 'Ground Advantage', 8.50),
        new RateResponse('USPS', 'PRIORITY_MAIL', 'Priority Mail', 12.10),
    ]));
    $adapter->shouldReceive('resolvePreSelectedRate')->andReturnUsing(fn (RateResponse $rate): RateResponse => $rate);
    $adapter->shouldReceive('createShipment')->andReturnUsing(function () use (&$purchases): ShipResponse {
        $purchases++;

        return ShipResponse::success(
            trackingNumber: '9400111899223197428490',
            cost: 8.50,
            carrier: 'USPS',
            service: 'Ground Advantage',
            labelData: base64_encode('LABEL-BYTES'),
            labelFormat: 'zpl',
        );
    });

    app(CarrierRegistry::class)->registerInstance('USPS', $adapter);
}

function uspsPackage(): Package
{
    $usps = Carrier::factory()->usps()->create();
    $method = ShippingMethod::factory()->create();
    $method->carrierServices()->attach(CarrierService::factory()->uspsGroundAdvantage()->create(['carrier_id' => $usps->id])->id);
    $method->carrierServices()->attach(CarrierService::factory()->create(['carrier_id' => $usps->id, 'service_code' => 'PRIORITY_MAIL', 'name' => 'Priority Mail'])->id);

    $shipment = Shipment::factory()->for($method)->create();

    return Package::factory()->for($shipment)->create(['status' => PackageStatus::Unshipped]);
}

it('shows the bought label instead of the rate list once the purchase is made', function (): void {
    $purchases = 0;
    registerCountingUspsAdapter($purchases);
    $package = uspsPackage();

    // The print is dispatched to the browser and may fail there, in which
    // case the page stays open — so what it renders after `ship` is what a
    // packer with a misconfigured printer sees.
    Livewire::test(Ship::class, ['package_id' => $package->id])
        ->assertActionVisible('Ship')
        ->assertActionHidden('Print again')
        ->set('selectedRateIndex', 0)
        ->call('ship')
        ->assertDispatched('print-label')
        ->assertSee('Label Purchased')
        ->assertSee('9400111899223197428490')
        ->assertDontSee('Select Shipping Rate')
        ->assertActionHidden('Ship')
        ->assertActionVisible('Print again');

    expect($purchases)->toBe(1)
        ->and($package->fresh()->status)->toBe(PackageStatus::Shipped);
});

it('does not buy a second label when ship is called again on the open page', function (): void {
    $purchases = 0;
    registerCountingUspsAdapter($purchases);
    $package = uspsPackage();

    Livewire::test(Ship::class, ['package_id' => $package->id])
        ->set('selectedRateIndex', 0)
        ->call('ship')
        // A different rate, chosen from a page that never left: the request
        // the hidden action would have made if it were still on screen.
        ->set('selectedRateIndex', 1)
        ->call('ship')
        ->assertNotified('Already Shipped');

    expect($purchases)->toBe(1)
        ->and($package->fresh()->tracking_number)->toBe('9400111899223197428490');
});

it('prints the stored label again from the shipped page', function (): void {
    $purchases = 0;
    registerCountingUspsAdapter($purchases);
    $package = uspsPackage();

    Livewire::test(Ship::class, ['package_id' => $package->id])
        ->set('selectedRateIndex', 0)
        ->call('ship')
        ->callAction('Print again')
        ->assertDispatched('print-label');

    expect($purchases)->toBe(1);
});
