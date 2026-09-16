<?php

use App\Contracts\DirectCarrierAdapter;
use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\CancelResponse;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\PreparedRateRequest;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\DataTransferObjects\Tracking\TrackShipmentResponse;
use App\Enums\CustomsDocumentDelivery;
use App\Enums\ServiceCapability;
use App\Models\Package;
use App\Services\Carriers\CarrierRegistry;
use App\Services\Carriers\Concerns\ConsultsCarrierPolicyForOffers;
use App\Services\Carriers\FedexAdapter;
use App\Services\Carriers\UpsAdapter;
use App\Services\Carriers\UspsAdapter;
use Illuminate\Support\Collection;
use Saloon\Http\Response;

beforeEach(function (): void {
    app(CarrierRegistry::class)->clearInstances();
});

it('returns USPS adapter for USPS carrier', function (): void {
    $adapter = app(CarrierRegistry::class)->get('USPS');

    expect($adapter)->toBeInstanceOf(UspsAdapter::class)
        ->and($adapter)->toBeInstanceOf(DirectCarrierAdapter::class)
        ->and($adapter->getCarrierName())->toBe('USPS');
});

it('returns FedEx adapter for FedEx carrier', function (): void {
    $adapter = app(CarrierRegistry::class)->get('FedEx');

    expect($adapter)->toBeInstanceOf(FedexAdapter::class)
        ->and($adapter)->toBeInstanceOf(DirectCarrierAdapter::class)
        ->and($adapter->getCarrierName())->toBe('FedEx');
});

it('returns UPS adapter for UPS carrier', function (): void {
    $adapter = app(CarrierRegistry::class)->get('UPS');

    expect($adapter)->toBeInstanceOf(UpsAdapter::class)
        ->and($adapter)->toBeInstanceOf(DirectCarrierAdapter::class)
        ->and($adapter->getCarrierName())->toBe('UPS');
});

it('throws exception for unknown carrier', function (): void {
    app(CarrierRegistry::class)->get('UnknownCarrier');
})->throws(InvalidArgumentException::class, 'Unknown carrier: UnknownCarrier');

it('checks if carrier exists', function (): void {
    expect(app(CarrierRegistry::class)->has('USPS'))->toBeTrue()
        ->and(app(CarrierRegistry::class)->has('FedEx'))->toBeTrue()
        ->and(app(CarrierRegistry::class)->has('UPS'))->toBeTrue()
        ->and(app(CarrierRegistry::class)->has('DHL'))->toBeFalse();
});

it('returns all registered carrier names', function (): void {
    $names = app(CarrierRegistry::class)->getCarrierNames();

    expect($names)->toBeArray()
        ->and($names)->toContain('USPS')
        ->and($names)->toContain('FedEx')
        ->and($names)->toContain('UPS');
});

it('caches adapter instances', function (): void {
    $adapter1 = app(CarrierRegistry::class)->get('USPS');
    $adapter2 = app(CarrierRegistry::class)->get('USPS');

    expect($adapter1)->toBe($adapter2);
});

it('allows registering custom adapters', function (): void {
    $mockAdapter = new class implements DirectCarrierAdapter
    {
        use ConsultsCarrierPolicyForOffers;

        public function getCarrierName(): string
        {
            return 'CustomCarrier';
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

        public function isConfigured(): bool
        {
            return true;
        }

        public function supportsMultiPackage(): bool
        {
            return false;
        }

        public function supportsCarrierManifest(): bool
        {
            return false;
        }

        public function resolvePreSelectedRate(RateResponse $rate, Package $package): RateResponse
        {
            return $rate;
        }

        public function packagingRequirementFor(RateResponse $rate): PackagingRequirement
        {
            return PackagingRequirement::shipperPackaging();
        }

        public function customsDocumentDelivery(AddressData $from, AddressData $to, ?RateResponse $rate = null): CustomsDocumentDelivery
        {
            return CustomsDocumentDelivery::None;
        }

        public function serviceCapability(string $serviceCode): ServiceCapability
        {
            return ServiceCapability::NotImplemented;
        }

        public function declaredValueCap(): ?float
        {
            return null;
        }
    };

    app(CarrierRegistry::class)->register('CustomCarrier', $mockAdapter::class);

    expect(app(CarrierRegistry::class)->has('CustomCarrier'))->toBeTrue();

    $adapter = app(CarrierRegistry::class)->get('CustomCarrier');
    expect($adapter->getCarrierName())->toBe('CustomCarrier');
});
