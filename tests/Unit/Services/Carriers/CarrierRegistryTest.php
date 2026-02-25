<?php

use App\Contracts\CarrierAdapterInterface;
use App\Services\Carriers\CarrierRegistry;
use App\Services\Carriers\FedexAdapter;
use App\Services\Carriers\UpsAdapter;
use App\Services\Carriers\UspsAdapter;

beforeEach(function (): void {
    CarrierRegistry::clearInstances();
});

it('returns USPS adapter for USPS carrier', function (): void {
    $adapter = CarrierRegistry::get('USPS');

    expect($adapter)->toBeInstanceOf(UspsAdapter::class)
        ->and($adapter)->toBeInstanceOf(CarrierAdapterInterface::class)
        ->and($adapter->getCarrierName())->toBe('USPS');
});

it('returns FedEx adapter for FedEx carrier', function (): void {
    $adapter = CarrierRegistry::get('FedEx');

    expect($adapter)->toBeInstanceOf(FedexAdapter::class)
        ->and($adapter)->toBeInstanceOf(CarrierAdapterInterface::class)
        ->and($adapter->getCarrierName())->toBe('FedEx');
});

it('returns UPS adapter for UPS carrier', function (): void {
    $adapter = CarrierRegistry::get('UPS');

    expect($adapter)->toBeInstanceOf(UpsAdapter::class)
        ->and($adapter)->toBeInstanceOf(CarrierAdapterInterface::class)
        ->and($adapter->getCarrierName())->toBe('UPS');
});

it('throws exception for unknown carrier', function (): void {
    CarrierRegistry::get('UnknownCarrier');
})->throws(InvalidArgumentException::class, 'Unknown carrier: UnknownCarrier');

it('checks if carrier exists', function (): void {
    expect(CarrierRegistry::has('USPS'))->toBeTrue()
        ->and(CarrierRegistry::has('FedEx'))->toBeTrue()
        ->and(CarrierRegistry::has('UPS'))->toBeTrue()
        ->and(CarrierRegistry::has('DHL'))->toBeFalse();
});

it('returns all registered carrier names', function (): void {
    $names = CarrierRegistry::getCarrierNames();

    expect($names)->toBeArray()
        ->and($names)->toContain('USPS')
        ->and($names)->toContain('FedEx')
        ->and($names)->toContain('UPS');
});

it('caches adapter instances', function (): void {
    $adapter1 = CarrierRegistry::get('USPS');
    $adapter2 = CarrierRegistry::get('USPS');

    expect($adapter1)->toBe($adapter2);
});

it('allows registering custom adapters', function (): void {
    $mockAdapter = new class implements CarrierAdapterInterface
    {
        public function getCarrierName(): string
        {
            return 'CustomCarrier';
        }

        public function getRates(\App\DataTransferObjects\Shipping\RateRequest $request, array $serviceCodes): \Illuminate\Support\Collection
        {
            return collect();
        }

        public function prepareRateRequest(\App\DataTransferObjects\Shipping\RateRequest $request, array $serviceCodes): ?\App\DataTransferObjects\Shipping\PreparedRateRequest
        {
            return null;
        }

        public function parseRateResponse(\Saloon\Http\Response $response, \App\DataTransferObjects\Shipping\RateRequest $request, array $serviceCodes): \Illuminate\Support\Collection
        {
            return collect();
        }

        public function createShipment(\App\DataTransferObjects\Shipping\ShipRequest $request): \App\DataTransferObjects\Shipping\ShipResponse
        {
            return \App\DataTransferObjects\Shipping\ShipResponse::failure('Not implemented');
        }

        public function cancelShipment(string $trackingNumber, \App\Models\Package $package): \App\DataTransferObjects\Shipping\CancelResponse
        {
            return \App\DataTransferObjects\Shipping\CancelResponse::failure('Not implemented');
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
            return false;
        }

        public function resolvePreSelectedRate(\App\DataTransferObjects\Shipping\RateResponse $rate, \App\Models\Package $package): \App\DataTransferObjects\Shipping\RateResponse
        {
            return $rate;
        }
    };

    CarrierRegistry::register('CustomCarrier', $mockAdapter::class);

    expect(CarrierRegistry::has('CustomCarrier'))->toBeTrue();

    $adapter = CarrierRegistry::get('CustomCarrier');
    expect($adapter->getCarrierName())->toBe('CustomCarrier');
});
