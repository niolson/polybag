<?php

use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\PackageData;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\Http\Integrations\Fedex\Requests\CreateShipment;
use App\Http\Integrations\Fedex\Requests\Rates;
use App\Services\Carriers\FedexAdapter;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

beforeEach(function (): void {
    $this->adapter = new FedexAdapter;
});

it('returns FedEx as carrier name', function (): void {
    expect($this->adapter->getCarrierName())->toBe('FedEx');
});

it('supports multi-package shipments', function (): void {
    expect($this->adapter->supportsMultiPackage())->toBeTrue();
});

it('checks if adapter is configured', function (): void {
    config([
        'services.fedex.api_key' => 'test_api_key',
        'services.fedex.api_secret' => 'test_api_secret',
        'services.fedex.account_number' => 'test_account',
    ]);

    expect($this->adapter->isConfigured())->toBeTrue();
});

it('returns false when not configured', function (): void {
    config([
        'services.fedex.api_key' => null,
        'services.fedex.api_secret' => null,
        'services.fedex.account_number' => null,
    ]);

    expect($this->adapter->isConfigured())->toBeFalse();
});

it('fetches rates from FedEx API', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Rates::class => MockResponse::make([
            'output' => [
                'rateReplyDetails' => [
                    [
                        'serviceType' => 'FEDEX_GROUND',
                        'serviceName' => 'FedEx Ground',
                        'ratedShipmentDetails' => [
                            ['totalNetCharge' => 12.75],
                        ],
                        'commit' => [
                            'dateDetail' => ['dayOfWeek' => 'FRIDAY'],
                            'transitDays' => 'THREE_DAYS',
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $request = new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: '90210',
        packages: [new PackageData(weight: 5.0, length: 12, width: 10, height: 8)],
    );

    $rates = $this->adapter->getRates($request, ['FEDEX_GROUND']);

    expect($rates)->toHaveCount(1);

    $rate = $rates->first();
    expect($rate)->toBeInstanceOf(RateResponse::class)
        ->and($rate->carrier)->toBe('FedEx')
        ->and($rate->serviceCode)->toBe('FEDEX_GROUND')
        ->and($rate->serviceName)->toBe('FedEx Ground')
        ->and($rate->price)->toBe(12.75)
        ->and($rate->transitTime)->toBe('THREE_DAYS');

    Saloon::assertSent(Rates::class);
});

it('filters rates by service codes', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Rates::class => MockResponse::make([
            'output' => [
                'rateReplyDetails' => [
                    [
                        'serviceType' => 'FEDEX_GROUND',
                        'serviceName' => 'FedEx Ground',
                        'ratedShipmentDetails' => [['totalNetCharge' => 12.75]],
                    ],
                    [
                        'serviceType' => 'FEDEX_EXPRESS_SAVER',
                        'serviceName' => 'FedEx Express Saver',
                        'ratedShipmentDetails' => [['totalNetCharge' => 25.00]],
                    ],
                    [
                        'serviceType' => 'PRIORITY_OVERNIGHT',
                        'serviceName' => 'Priority Overnight',
                        'ratedShipmentDetails' => [['totalNetCharge' => 50.00]],
                    ],
                ],
            ],
        ]),
    ]);

    $request = new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: '90210',
        packages: [new PackageData(weight: 5.0, length: 12, width: 10, height: 8)],
    );

    // Only request FEDEX_GROUND and PRIORITY_OVERNIGHT
    $rates = $this->adapter->getRates($request, ['FEDEX_GROUND', 'PRIORITY_OVERNIGHT']);

    expect($rates)->toHaveCount(2);

    $serviceCodes = $rates->pluck('serviceCode')->toArray();
    expect($serviceCodes)->toContain('FEDEX_GROUND')
        ->and($serviceCodes)->toContain('PRIORITY_OVERNIGHT')
        ->and($serviceCodes)->not->toContain('FEDEX_EXPRESS_SAVER');
});

it('cancels a FedEx shipment', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        \App\Http\Integrations\Fedex\Requests\CancelShipment::class => MockResponse::make([
            'output' => ['cancelledShipment' => true],
        ], 200),
    ]);

    $shipment = \App\Models\Shipment::factory()->create();
    $package = \App\Models\Package::factory()->shipped()->for($shipment)->create([
        'carrier' => 'FedEx',
        'tracking_number' => '794644790138',
    ]);

    config(['services.fedex.account_number' => 'test_account']);

    $response = $this->adapter->cancelShipment('794644790138', $package);

    expect($response->success)->toBeTrue()
        ->and($response->message)->toBe('FedEx shipment cancelled.');

    Saloon::assertSent(\App\Http\Integrations\Fedex\Requests\CancelShipment::class);
});

it('returns failure when FedEx cancel errors', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        \App\Http\Integrations\Fedex\Requests\CancelShipment::class => MockResponse::make([
            'errors' => [['message' => 'Tracking number not found']],
        ], 404),
    ]);

    $shipment = \App\Models\Shipment::factory()->create();
    $package = \App\Models\Package::factory()->shipped()->for($shipment)->create([
        'carrier' => 'FedEx',
        'tracking_number' => '000000000000',
    ]);

    config(['services.fedex.account_number' => 'test_account']);

    $response = $this->adapter->cancelShipment('000000000000', $package);

    expect($response->success)->toBeFalse()
        ->and($response->message)->toContain('404');
});

it('returns empty collection when API returns no rates', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Rates::class => MockResponse::make(['output' => ['rateReplyDetails' => []]]),
    ]);

    $request = new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: '90210',
        packages: [new PackageData(weight: 5.0, length: 12, width: 10, height: 8)],
    );

    $rates = $this->adapter->getRates($request, ['FEDEX_GROUND']);

    expect($rates)->toHaveCount(0);
});

it('creates shipment and returns tracking info', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        CreateShipment::class => MockResponse::make([
            'output' => [
                'transactionShipments' => [
                    [
                        'masterTrackingNumber' => '794644790138',
                        'completedShipmentDetail' => [
                            'shipmentRating' => [
                                'shipmentRateDetails' => [
                                    ['totalNetCharge' => 12.75],
                                ],
                            ],
                        ],
                        'pieceResponses' => [
                            [
                                'trackingNumber' => '794644790138',
                                'packageDocuments' => [
                                    ['encodedLabel' => 'JVBERi0xLjQKYmFzZTY0bGFiZWxkYXRh'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $fromAddress = new AddressData(
        firstName: 'Shipping',
        lastName: 'Center',
        streetAddress: '123 Warehouse St',
        city: 'Seattle',
        stateOrProvince: 'WA',
        postalCode: '98072',
        company: 'Test Company',
        phone: '555-123-4567',
    );

    $toAddress = new AddressData(
        firstName: 'John',
        lastName: 'Doe',
        streetAddress: '456 Main St',
        city: 'Los Angeles',
        stateOrProvince: 'CA',
        postalCode: '90210',
        phone: '555-987-6543',
    );

    $packageData = new PackageData(weight: 5.0, length: 12, width: 10, height: 8);

    $selectedRate = new RateResponse(
        carrier: 'FedEx',
        serviceCode: 'FEDEX_GROUND',
        serviceName: 'FedEx Ground',
        price: 12.75,
        metadata: [
            'serviceType' => 'FEDEX_GROUND',
        ],
    );

    $request = new ShipRequest(
        fromAddress: $fromAddress,
        toAddress: $toAddress,
        packageData: $packageData,
        selectedRate: $selectedRate,
    );

    $response = $this->adapter->createShipment($request);

    expect($response->success)->toBeTrue()
        ->and($response->trackingNumber)->toBe('794644790138')
        ->and($response->cost)->toBe(12.75)
        ->and($response->carrier)->toBe('FedEx')
        ->and($response->service)->toBe('FedEx Ground')
        ->and($response->labelData)->toBe('JVBERi0xLjQKYmFzZTY0bGFiZWxkYXRh');

    Saloon::assertSent(CreateShipment::class);
});

it('returns failure response when shipment creation fails', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        CreateShipment::class => MockResponse::make([
            'output' => [
                'transactionShipments' => [],
            ],
        ]),
    ]);

    $fromAddress = new AddressData(
        firstName: 'Shipping',
        lastName: 'Center',
        streetAddress: '123 Warehouse St',
        city: 'Seattle',
        stateOrProvince: 'WA',
        postalCode: '98072',
    );

    $toAddress = new AddressData(
        firstName: 'John',
        lastName: 'Doe',
        streetAddress: '456 Main St',
        city: 'Los Angeles',
        stateOrProvince: 'CA',
        postalCode: '90210',
    );

    $packageData = new PackageData(weight: 5.0, length: 12, width: 10, height: 8);

    $selectedRate = new RateResponse(
        carrier: 'FedEx',
        serviceCode: 'FEDEX_GROUND',
        serviceName: 'FedEx Ground',
        price: 12.75,
        metadata: ['serviceType' => 'FEDEX_GROUND'],
    );

    $request = new ShipRequest(
        fromAddress: $fromAddress,
        toAddress: $toAddress,
        packageData: $packageData,
        selectedRate: $selectedRate,
    );

    $response = $this->adapter->createShipment($request);

    expect($response->success)->toBeFalse()
        ->and($response->errorMessage)->toBe('FedEx response missing shipment data');
});
