<?php

use App\Contracts\CarrierAdapterInterface;
use App\Services\Carriers\CarrierRegistry;
use App\Services\Carriers\FedexAdapter;
use App\Services\Carriers\UpsAdapter;
use App\Services\Carriers\UspsAdapter;

beforeEach(function (): void {
    app(CarrierRegistry::class)->clearInstances();
});

it('returns USPS adapter for USPS carrier', function (): void {
    $adapter = app(CarrierRegistry::class)->get('USPS');

    expect($adapter)->toBeInstanceOf(UspsAdapter::class)
        ->and($adapter)->toBeInstanceOf(CarrierAdapterInterface::class)
        ->and($adapter->getCarrierName())->toBe('USPS');
});

it('returns FedEx adapter for FedEx carrier', function (): void {
    $adapter = app(CarrierRegistry::class)->get('FedEx');

    expect($adapter)->toBeInstanceOf(FedexAdapter::class)
        ->and($adapter)->toBeInstanceOf(CarrierAdapterInterface::class)
        ->and($adapter->getCarrierName())->toBe('FedEx');
});

it('returns UPS adapter for UPS carrier', function (): void {
    $adapter = app(CarrierRegistry::class)->get('UPS');

    expect($adapter)->toBeInstanceOf(UpsAdapter::class)
        ->and($adapter)->toBeInstanceOf(CarrierAdapterInterface::class)
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

    app(CarrierRegistry::class)->register('CustomCarrier', $mockAdapter::class);

    expect(app(CarrierRegistry::class)->has('CustomCarrier'))->toBeTrue();

    $adapter = app(CarrierRegistry::class)->get('CustomCarrier');
    expect($adapter->getCarrierName())->toBe('CustomCarrier');
});
