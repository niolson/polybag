<?php

use App\Contracts\CarrierAdapterInterface;
use App\DataTransferObjects\Shipping\CancelResponse;
use App\DataTransferObjects\Shipping\PreparedRateRequest;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Filament\Resources\PackageResource\Pages\ListPackages;
use App\Filament\Resources\PackageResource\Pages\ViewPackage;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Saloon\Http\Response;

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
        'status' => PackageStatus::Unshipped,
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

    $testAdapterClass = get_class(new class implements CarrierAdapterInterface
    {
        public function getCarrierName(): string
        {
            return 'USPS';
        }

        public function getRates(RateRequest $request, array $serviceCodes): Collection
        {
            return collect();
        }

        public function prepareRateRequest(RateRequest $request, array $serviceCodes): ?PreparedRateRequest
        {
            return null;
        }

        public function parseRateResponse(Response $response, RateRequest $request, array $serviceCodes): Collection
        {
            return collect();
        }

        public function createShipment(ShipRequest $request): ShipResponse
        {
            return ShipResponse::failure('Not implemented');
        }

        public function cancelShipment(string $trackingNumber, Package $package): CancelResponse
        {
            return CancelResponse::success('Label voided successfully.');
        }

        public function isConfigured(): bool
        {
            return true;
        }

        public function supportsMultiPackage(): bool
        {
            return false;
        }

        public function supportsManifest(): bool
        {
            return true;
        }

        public function resolvePreSelectedRate(RateResponse $rate, Package $package): RateResponse
        {
            return $rate;
        }
    });

    app(CarrierRegistry::class)->register('USPS', $testAdapterClass);
    app(CarrierRegistry::class)->clearInstances();

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
        ->and($package->status)->toBe(PackageStatus::Unshipped)
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
        'status' => PackageStatus::Unshipped,
        'tracking_number' => null,
        'carrier' => null,
    ]);

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->assertActionHidden('void');
});
