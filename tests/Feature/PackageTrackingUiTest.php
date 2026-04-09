<?php

use App\Contracts\CarrierAdapterInterface;
use App\DataTransferObjects\Shipping\CancelResponse;
use App\DataTransferObjects\Shipping\PreparedRateRequest;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\DataTransferObjects\Tracking\TrackingEventData;
use App\DataTransferObjects\Tracking\TrackShipmentResponse;
use App\Enums\Role;
use App\Enums\TrackingStatus;
use App\Filament\Resources\PackageResource\Pages\ListPackages;
use App\Filament\Resources\PackageResource\Pages\ViewPackage;
use App\Filament\Resources\ShipmentResource\Pages\ViewShipment;
use App\Filament\Resources\ShipmentResource\RelationManagers\PackagesRelationManager;
use App\Filament\Widgets\ExceptionsWidget;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Saloon\Http\Response;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
});

function trackingAdapter(): CarrierAdapterInterface
{
    return new class implements CarrierAdapterInterface
    {
        public function getCarrierName(): string
        {
            return 'FedEx';
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

        public function isConfigured(): bool
        {
            return true;
        }

        public function cancelShipment(string $trackingNumber, Package $package): CancelResponse
        {
            return CancelResponse::failure('Not implemented');
        }

        public function supportsTracking(): bool
        {
            return true;
        }

        public function trackShipment(Package $package): TrackShipmentResponse
        {
            return TrackShipmentResponse::success(
                status: TrackingStatus::InTransit,
                events: [
                    new TrackingEventData(
                        timestamp: CarbonImmutable::parse('2026-04-08T15:00:00Z'),
                        location: 'Memphis, TN, US',
                        description: 'Departed FedEx hub',
                    ),
                ],
                estimatedDeliveryAt: CarbonImmutable::parse('2026-04-10T18:00:00Z'),
                statusLabel: 'In transit',
                details: ['raw' => ['provider' => 'fedex']],
            );
        }

        public function supportsMultiPackage(): bool
        {
            return false;
        }

        public function supportsManifest(): bool
        {
            return false;
        }

        public function resolvePreSelectedRate(RateResponse $rate, Package $package): RateResponse
        {
            return $rate;
        }
    };
}

it('shows track action on package list and view pages for shipped packages', function () {
    $package = Package::factory()->fedex()->create();

    Livewire::test(ListPackages::class)
        ->assertActionVisible(TestAction::make('track')->table($package));

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->assertActionVisible('track');
});

it('mounts the track action and refreshes tracking details', function () {
    $package = Package::factory()->fedex()->create([
        'tracking_status' => TrackingStatus::PreTransit,
        'tracking_details' => null,
    ]);

    app(CarrierRegistry::class)->registerInstance('FedEx', trackingAdapter());

    Livewire::test(ListPackages::class)
        ->mountTableAction('track', $package);

    $package->refresh();

    expect($package->tracking_status)->toBe(TrackingStatus::InTransit)
        ->and(data_get($package->tracking_details, 'events.0.description'))->toBe('Departed FedEx hub');
});

it('shows track action in the shipment packages relation manager', function () {
    $shipment = Shipment::factory()->create();
    $package = Package::factory()->fedex()->for($shipment)->create();

    Livewire::test(PackagesRelationManager::class, [
        'ownerRecord' => $shipment,
        'pageClass' => ViewShipment::class,
    ])->assertActionVisible(TestAction::make('track')->table($package));
});

it('includes tracking counts in the exceptions widget', function () {
    Package::factory()->shipped()->create([
        'tracking_status' => TrackingStatus::Exception,
    ]);
    Package::factory()->shipped()->create([
        'tracking_status' => TrackingStatus::PreTransit,
        'shipped_at' => now()->subDays(3),
    ]);

    Livewire::test(ExceptionsWidget::class)
        ->assertSee('Tracking Exceptions')
        ->assertSee('Stuck Pre-Transit');
});
