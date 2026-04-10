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
use App\Events\TrackingStatusUpdated;
use App\Models\Package;
use App\Models\User;
use App\Notifications\TrackingExceptionDetected;
use App\Services\Carriers\CarrierRegistry;
use App\Services\TrackingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Saloon\Http\Response;

it('refreshes a package tracking snapshot and dispatches a status change event', function () {
    Event::fake([TrackingStatusUpdated::class]);

    $package = Package::factory()->fedex()->create([
        'tracking_status' => TrackingStatus::PreTransit,
        'tracking_details' => null,
    ]);

    $adapter = new class implements CarrierAdapterInterface
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
                        timestamp: CarbonImmutable::parse('2026-04-08T12:00:00Z'),
                        location: 'Memphis, TN, US',
                        description: 'Arrived at FedEx location',
                        statusCode: 'IT',
                        status: 'IN_TRANSIT',
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

        public function serviceCapability(string $serviceCode): \App\Enums\ServiceCapability
        {
            return \App\Enums\ServiceCapability::NotImplemented;
        }
    };

    app(CarrierRegistry::class)->registerInstance('FedEx', $adapter);

    $response = app(TrackingService::class)->refreshPackage($package);

    $package->refresh();

    expect($response->success)->toBeTrue()
        ->and($package->tracking_status)->toBe(TrackingStatus::InTransit)
        ->and($package->tracking_updated_at)->not->toBeNull()
        ->and($package->tracking_checked_at)->not->toBeNull()
        ->and(data_get($package->tracking_details, 'events.0.description'))->toBe('Arrived at FedEx location')
        ->and(data_get($package->tracking_details, 'raw.provider'))->toBe('fedex');

    Event::assertDispatched(TrackingStatusUpdated::class, function (TrackingStatusUpdated $event) use ($package): bool {
        return $event->package->is($package)
            && $event->previousStatus === TrackingStatus::PreTransit
            && $event->currentStatus === TrackingStatus::InTransit;
    });
});

it('returns unsupported tracking safely without changing status unexpectedly', function () {
    $package = Package::factory()->shipped()->create([
        'carrier' => 'UPS',
        'tracking_status' => TrackingStatus::PreTransit,
    ]);

    $adapter = new class implements CarrierAdapterInterface
    {
        public function getCarrierName(): string
        {
            return 'UPS';
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
            return false;
        }

        public function trackShipment(Package $package): TrackShipmentResponse
        {
            return TrackShipmentResponse::unsupported();
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

        public function serviceCapability(string $serviceCode): \App\Enums\ServiceCapability
        {
            return \App\Enums\ServiceCapability::NotImplemented;
        }
    };

    app(CarrierRegistry::class)->registerInstance('UPS', $adapter);

    $response = app(TrackingService::class)->refreshPackage($package);

    $package->refresh();

    expect($response->supported)->toBeFalse()
        ->and($package->tracking_status)->toBe(TrackingStatus::PreTransit)
        ->and(data_get($package->tracking_details, 'supported'))->toBeFalse();
});

it('notifies operational users when a package enters exception or is stuck in pre-transit', function () {
    Notification::fake();

    $admin = User::factory()->create(['role' => Role::Admin, 'active' => true]);
    $manager = User::factory()->create(['role' => Role::Manager, 'active' => true]);
    User::factory()->create(['role' => Role::User, 'active' => true]);

    $package = Package::factory()->fedex()->create([
        'tracking_status' => TrackingStatus::InTransit,
        'shipped_at' => now()->subDays(3),
        'tracking_details' => [],
    ]);

    $adapter = new class implements CarrierAdapterInterface
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
                status: TrackingStatus::Exception,
                statusLabel: 'Delayed',
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

        public function serviceCapability(string $serviceCode): \App\Enums\ServiceCapability
        {
            return \App\Enums\ServiceCapability::NotImplemented;
        }
    };

    app(CarrierRegistry::class)->registerInstance('FedEx', $adapter);

    app(TrackingService::class)->refreshPackage($package);

    Notification::assertSentTo([$admin, $manager], TrackingExceptionDetected::class);
});
