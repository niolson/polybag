<?php

use App\Enums\Role;
use App\Filament\Resources\PackageResource\Pages\ListPackages;
use App\Filament\Resources\PackageResource\Pages\ViewPackage;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
});

it('shows void action for shipped packages', function (): void {
    $shipment = Shipment::factory()->create();
    $package = Package::factory()->shipped()->for($shipment)->create();

    Livewire::test(ListPackages::class)
        ->assertActionVisible(TestAction::make('void')->table($package));
});

it('hides void action for unshipped packages', function (): void {
    $shipment = Shipment::factory()->create();
    $package = Package::factory()->for($shipment)->create([
        'shipped' => false,
        'tracking_number' => null,
        'carrier' => null,
    ]);

    Livewire::test(ListPackages::class)
        ->assertActionHidden(TestAction::make('void')->table($package));
});

it('voids a label and clears shipping fields', function (): void {
    $shipment = Shipment::factory()->create();
    $package = Package::factory()->shipped()->for($shipment)->create([
        'carrier' => 'USPS',
        'tracking_number' => '9400111899223456789012',
        'service' => 'USPS Ground Advantage',
        'cost' => 8.50,
        'label_data' => 'base64labeldata',
        'label_orientation' => 'portrait',
    ]);

    $testAdapterClass = get_class(new class implements \App\Contracts\CarrierAdapterInterface
    {
        public function getCarrierName(): string
        {
            return 'USPS';
        }

        public function getRates(\App\DataTransferObjects\Shipping\RateRequest $request, array $serviceCodes): \Illuminate\Support\Collection
        {
            return collect();
        }

        public function createShipment(\App\DataTransferObjects\Shipping\ShipRequest $request): \App\DataTransferObjects\Shipping\ShipResponse
        {
            return \App\DataTransferObjects\Shipping\ShipResponse::failure('Not implemented');
        }

        public function cancelShipment(string $trackingNumber, \App\Models\Package $package): \App\DataTransferObjects\Shipping\CancelResponse
        {
            return \App\DataTransferObjects\Shipping\CancelResponse::success('Label voided successfully.');
        }

        public function isConfigured(): bool
        {
            return true;
        }

        public function supportsMultiPackage(): bool
        {
            return false;
        }
    });

    CarrierRegistry::register('USPS', $testAdapterClass);
    CarrierRegistry::clearInstances();

    Livewire::test(ListPackages::class)
        ->callAction(TestAction::make('void')->table($package))
        ->assertNotified();

    $package->refresh();

    expect($package->tracking_number)->toBeNull()
        ->and($package->carrier)->toBeNull()
        ->and($package->service)->toBeNull()
        ->and($package->cost)->toBeNull()
        ->and($package->label_data)->toBeNull()
        ->and($package->label_orientation)->toBeNull()
        ->and($package->shipped)->toBeFalse()
        ->and($package->shipped_at)->toBeNull()
        ->and($package->shipped_by_user_id)->toBeNull()
        ->and($package->weight)->not->toBeNull()
        ->and($package->height)->not->toBeNull()
        ->and($package->width)->not->toBeNull()
        ->and($package->length)->not->toBeNull();
});

it('shows void action on view page for shipped packages', function (): void {
    $shipment = Shipment::factory()->create();
    $package = Package::factory()->shipped()->for($shipment)->create();

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->assertActionVisible('void');
});

it('hides void action on view page for unshipped packages', function (): void {
    $shipment = Shipment::factory()->create();
    $package = Package::factory()->for($shipment)->create([
        'shipped' => false,
        'tracking_number' => null,
        'carrier' => null,
    ]);

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->assertActionHidden('void');
});
