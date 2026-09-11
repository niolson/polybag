<?php

use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\CustomsItem;
use App\DataTransferObjects\Shipping\PackageData;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\Enums\FedexPackageType;
use App\Enums\LabelReferenceSource;
use App\Enums\TrackingStatus;
use App\Http\Integrations\Fedex\Requests\CancelShipment;
use App\Http\Integrations\Fedex\Requests\CreateShipment;
use App\Http\Integrations\Fedex\Requests\Rates;
use App\Http\Integrations\Fedex\Requests\TrackShipment;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\CarrierAccountScope;
use App\Models\CarrierService;
use App\Models\Client;
use App\Models\Location;
use App\Models\Package;
use App\Models\Shipment;
use App\Services\Carriers\FedexAdapter;
use App\Services\SettingsService;
use Carbon\CarbonImmutable;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;

beforeEach(function (): void {
    $this->adapter = new FedexAdapter;
    createFedexAccount();
});

it('returns FedEx as carrier name', function (): void {
    expect($this->adapter->getCarrierName())->toBe('FedEx');
});

it('supports multi-package shipments', function (): void {
    expect($this->adapter->supportsMultiPackage())->toBeTrue();
});

it('checks if adapter is configured', function (): void {
    // createFedexAccount() in beforeEach provides an active FedEx CarrierAccount.
    expect($this->adapter->isConfigured())->toBeTrue();
});

it('returns false when not configured', function (): void {
    CarrierAccount::query()->delete();

    expect($this->adapter->isConfigured())->toBeFalse();
});

it('returns false when only an empty active account exists', function (): void {
    CarrierAccount::query()->delete();
    CarrierAccount::factory()->fedex()->create([
        'carrier_id' => Carrier::where('name', 'FedEx')->value('id'),
        'credentials' => null,
        'secret_credentials' => null,
    ]);

    expect($this->adapter->isConfigured())->toBeFalse();
});

it('is configured in sandbox mode with only sandbox credentials', function (): void {
    CarrierAccount::query()->delete();
    createFedexAccount([
        'api_key' => null,
        'api_secret' => null,
        'sandbox_api_key' => 'sandbox_key',
        'sandbox_api_secret' => 'sandbox_secret',
    ]);

    app(SettingsService::class)->set('sandbox_mode', true);

    expect($this->adapter->isConfigured())->toBeTrue();
});

it('is not configured in production mode when only sandbox credentials are set', function (): void {
    CarrierAccount::query()->delete();
    createFedexAccount([
        'api_key' => null,
        'api_secret' => null,
        'sandbox_api_key' => 'sandbox_key',
        'sandbox_api_secret' => 'sandbox_secret',
    ]);

    app(SettingsService::class)->set('sandbox_mode', false);

    expect($this->adapter->isConfigured())->toBeFalse();
});

it('prepares FedEx rates without keeping account state on the adapter', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Rates::class => MockResponse::make(['output' => ['rateReplyDetails' => []]]),
    ]);

    CarrierAccount::query()->delete();

    $carrier = Carrier::firstOrCreate(['name' => 'FedEx']);
    $firstClient = Client::factory()->create();
    $secondClient = Client::factory()->create();

    $firstAccount = CarrierAccount::factory()->fedex()->create([
        'carrier_id' => $carrier->id,
        'credentials' => ['account_number' => 'first_account'],
        'secret_credentials' => ['api_key' => 'first_key', 'api_secret' => 'first_secret'],
    ]);
    $secondAccount = CarrierAccount::factory()->fedex()->create([
        'carrier_id' => $carrier->id,
        'credentials' => ['account_number' => 'second_account'],
        'secret_credentials' => ['api_key' => 'second_key', 'api_secret' => 'second_secret'],
    ]);

    CarrierAccountScope::factory()->forAccount($firstAccount)->clientScoped($firstClient)->create();
    CarrierAccountScope::factory()->forAccount($secondAccount)->clientScoped($secondClient)->create();

    $this->adapter->getRates(rateRequestForClient($firstClient->id), ['FEDEX_GROUND']);
    $this->adapter->getRates(rateRequestForClient($secondClient->id), ['FEDEX_GROUND']);

    Saloon::assertSent(function ($request): bool {
        return $request instanceof Rates
            && data_get($request->body()->all(), 'accountNumber.value') === 'second_account';
    });

    expect((new ReflectionClass($this->adapter))->hasProperty('currentAccount'))->toBeFalse();
});

it('keeps the client account through FedEx Saturday retry and One Rate follow-up', function (): void {
    $sentAccountNumbers = [];
    $oneRateAccountNumbers = [];

    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        function (PendingRequest $pendingRequest) use (&$sentAccountNumbers): MockResponse {
            $body = $pendingRequest->body()->all();
            $sentAccountNumbers[] = data_get($body, 'accountNumber.value');

            return MockResponse::make([
                'errors' => [
                    ['code' => 'SERVICE.PACKAGECOMBINATION.INVALID', 'message' => 'Saturday delivery is not available.'],
                ],
            ], 400);
        },
        function (PendingRequest $pendingRequest) use (&$sentAccountNumbers): MockResponse {
            $body = $pendingRequest->body()->all();
            $sentAccountNumbers[] = data_get($body, 'accountNumber.value');

            return MockResponse::make(['output' => ['rateReplyDetails' => []]]);
        },
        function (PendingRequest $pendingRequest) use (&$sentAccountNumbers, &$oneRateAccountNumbers): MockResponse {
            $body = $pendingRequest->body()->all();
            $sentAccountNumbers[] = data_get($body, 'accountNumber.value');

            if (in_array('FEDEX_ONE_RATE', data_get($body, 'requestedShipment.shipmentSpecialServices.specialServiceTypes', []), true)) {
                $oneRateAccountNumbers[] = data_get($body, 'accountNumber.value');
            }

            return MockResponse::make(['output' => ['rateReplyDetails' => []]]);
        },
    ]);

    CarrierAccount::query()->delete();

    $carrier = Carrier::firstOrCreate(['name' => 'FedEx']);
    $client = Client::factory()->create();

    $globalAccount = CarrierAccount::factory()->fedex()->create([
        'carrier_id' => $carrier->id,
        'credentials' => ['account_number' => 'global_account'],
        'secret_credentials' => ['api_key' => 'global_key', 'api_secret' => 'global_secret'],
    ]);
    $clientAccount = CarrierAccount::factory()->fedex()->create([
        'carrier_id' => $carrier->id,
        'credentials' => ['account_number' => 'client_account'],
        'secret_credentials' => ['api_key' => 'client_key', 'api_secret' => 'client_secret'],
    ]);

    CarrierAccountScope::factory()->forAccount($globalAccount)->global()->create();
    CarrierAccountScope::factory()->forAccount($clientAccount)->clientScoped($client)->create();

    $request = new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: '90210',
        packages: [new PackageData(
            weight: 5.0,
            length: 12,
            width: 10,
            height: 8,
            fedexPackageType: FedexPackageType::FEDEX_SMALL_BOX,
        )],
        specialServiceCodes: ['saturday_delivery'],
        clientId: $client->id,
        shipDate: CarbonImmutable::parse('2026-07-03'),
    );

    $this->adapter->getRates($request, ['FIRST_OVERNIGHT']);

    expect($sentAccountNumbers)->toBe(['client_account', 'client_account', 'client_account'])
        ->and($oneRateAccountNumbers)->toBe(['client_account']);
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

it('uses request countries when building FedEx rate payloads', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Rates::class => MockResponse::make([
            'output' => [
                'rateReplyDetails' => [],
            ],
        ]),
    ]);

    $request = new RateRequest(
        originPostalCode: 'L4W5K6',
        destinationPostalCode: '99502',
        originCountry: 'CA',
        destinationCountry: 'US',
        packages: [new PackageData(weight: 5.0, length: 12, width: 10, height: 8)],
    );

    $this->adapter->getRates($request, ['FEDEX_GROUND']);

    Saloon::assertSent(function (Rates $request): bool {
        $body = $request->body()->all();

        return ($body['requestedShipment']['shipper']['address']['countryCode'] ?? null) === 'CA'
            && ($body['requestedShipment']['recipient']['address']['countryCode'] ?? null) === 'US'
            && ($body['requestedShipment']['customsClearanceDetail']['dutiesPayment']['paymentType'] ?? null) === 'SENDER';
    });
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

it('includes smart post info detail for sub-pound smart post rate requests', function (): void {
    $location = Location::factory()->create(['fedex_hub_id' => '5015']);

    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Rates::class => MockResponse::make([
            'output' => [
                'rateReplyDetails' => [
                    [
                        'serviceType' => 'SMART_POST',
                        'serviceName' => 'FedEx Ground Economy',
                        'ratedShipmentDetails' => [
                            ['totalNetCharge' => 9.25],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $request = new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: '90210',
        packages: [new PackageData(weight: 0.75, length: 10, width: 8, height: 6)],
        locationId: $location->id,
    );

    $this->adapter->getRates($request, ['SMART_POST']);

    Saloon::assertSent(function (Rates $request): bool {
        $body = $request->body()->all();

        return ($body['requestedShipment']['shipper']['address']['postalCode'] ?? null) === '98072'
            && ($body['requestedShipment']['recipient']['address']['postalCode'] ?? null) === '90210'
            && ($body['requestedShipment']['pickupType'] ?? null) === 'USE_SCHEDULED_PICKUP'
            && ($body['requestedShipment']['rateRequestType'] ?? null) === ['ACCOUNT']
            && ($body['requestedShipment']['serviceType'] ?? null) === 'SMART_POST'
            && ($body['requestedShipment']['smartPostInfoDetail']['hubId'] ?? null) === '5015'
            && ($body['requestedShipment']['smartPostInfoDetail']['indicia'] ?? null) === 'PRESORTED_STANDARD'
            && ($body['requestedShipment']['smartPostInfoDetail']['ancillaryEndorsement'] ?? null) === 'ADDRESS_CORRECTION'
            && ($body['requestedShipment']['requestedPackageLineItems'][0]['weight']['value'] ?? null) === 0.75
            && ! isset($body['requestedShipment']['shipDatestamp']);
    });
});

it('includes parcel select smart post info detail for 1lb and up rate requests', function (): void {
    $location = Location::factory()->create(['fedex_hub_id' => '5015']);

    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Rates::class => MockResponse::make([
            'output' => [
                'rateReplyDetails' => [
                    [
                        'serviceType' => 'SMART_POST',
                        'serviceName' => 'FedEx Ground Economy',
                        'ratedShipmentDetails' => [
                            ['totalNetCharge' => 10.50],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $request = new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: '90210',
        packages: [new PackageData(weight: 2.0, length: 12, width: 10, height: 8)],
        locationId: $location->id,
    );

    $this->adapter->getRates($request, ['SMART_POST']);

    Saloon::assertSent(function (Rates $request): bool {
        $body = $request->body()->all();
        $detail = $body['requestedShipment']['smartPostInfoDetail'] ?? [];

        return ($body['requestedShipment']['shipper']['address']['postalCode'] ?? null) === '98072'
            && ($body['requestedShipment']['recipient']['address']['postalCode'] ?? null) === '90210'
            && ($body['requestedShipment']['pickupType'] ?? null) === 'USE_SCHEDULED_PICKUP'
            && ($body['requestedShipment']['rateRequestType'] ?? null) === ['ACCOUNT']
            && ($body['requestedShipment']['serviceType'] ?? null) === 'SMART_POST'
            && ($detail['hubId'] ?? null) === '5015'
            && ($detail['indicia'] ?? null) === 'PARCEL_SELECT'
            && ($body['requestedShipment']['requestedPackageLineItems'][0]['weight']['value'] ?? null) === 2.0
            && ! array_key_exists('ancillaryEndorsement', $detail)
            && ! isset($body['requestedShipment']['shipDatestamp']);
    });
});

it('cancels a FedEx shipment', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        CancelShipment::class => MockResponse::make([
            'output' => ['cancelledShipment' => true],
        ], 200),
    ]);

    $shipment = Shipment::factory()->create();
    $package = Package::factory()->shipped()->for($shipment)->create([
        'carrier' => 'FedEx',
        'tracking_number' => '794644790138',
    ]);

    config(['services.fedex.account_number' => 'test_account']);

    $response = $this->adapter->cancelShipment('794644790138', $package);

    expect($response->success)->toBeTrue()
        ->and($response->message)->toBe('FedEx shipment cancelled.');

    Saloon::assertSent(CancelShipment::class);
});

it('returns failure when FedEx cancel errors', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        CancelShipment::class => MockResponse::make([
            'errors' => [['message' => 'Tracking number not found']],
        ], 404),
    ]);

    $shipment = Shipment::factory()->create();
    $package = Package::factory()->shipped()->for($shipment)->create([
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

it('stands in for international rates the FedEx sandbox cannot quote', function (): void {
    app(SettingsService::class)->set('sandbox_mode', true);

    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Rates::class => MockResponse::make(['output' => ['rateReplyDetails' => []]]),
    ]);

    $request = new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: 'M5H 2N2',
        destinationCountry: 'CA',
        packages: [new PackageData(weight: 5.0, length: 12, width: 10, height: 8)],
        shipDate: CarbonImmutable::parse('2026-03-02'),
    );

    $rates = $this->adapter->getRates($request, ['FEDEX_INTERNATIONAL_PRIORITY', 'INTERNATIONAL_ECONOMY']);

    expect($rates->pluck('serviceCode')->all())
        ->toBe(['INTERNATIONAL_ECONOMY', 'FEDEX_INTERNATIONAL_PRIORITY']);

    $priority = $rates->firstWhere('serviceCode', 'FEDEX_INTERNATIONAL_PRIORITY');
    expect($priority->carrier)->toBe('FedEx')
        ->and($priority->price)->toBeGreaterThan(0.0)
        // createShipment reads the service type off the metadata, so a stubbed
        // rate has to carry it exactly as a quoted one does.
        ->and($priority->metadata['serviceType'])->toBe('FEDEX_INTERNATIONAL_PRIORITY')
        ->and($priority->metadata['isSandboxStub'])->toBeTrue()
        ->and($priority->deliveryDate)->toBe('2026-03-05');

    // The sandbox rate API is never asked: it would answer domestic services.
    Saloon::assertNotSent(Rates::class);
});

it('prices a sandbox international rate the same way every time it is quoted', function (): void {
    app(SettingsService::class)->set('sandbox_mode', true);

    $request = new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: 'M5H 2N2',
        destinationCountry: 'CA',
        packages: [new PackageData(weight: 5.0, length: 12, width: 10, height: 8)],
    );

    $first = $this->adapter->getRates($request, ['FEDEX_INTERNATIONAL_PRIORITY']);
    $second = $this->adapter->getRates($request, ['FEDEX_INTERNATIONAL_PRIORITY']);

    expect($second->first()->price)->toBe($first->first()->price);
});

it('names a sandbox international rate the way the service catalog does', function (): void {
    app(SettingsService::class)->set('sandbox_mode', true);

    CarrierService::create([
        'carrier_id' => Carrier::where('name', 'FedEx')->value('id'),
        'service_code' => 'FEDEX_INTERNATIONAL_PRIORITY',
        'name' => 'FedEx International Priority®',
    ]);

    $request = new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: 'M5H 2N2',
        destinationCountry: 'CA',
        packages: [new PackageData(weight: 5.0, length: 12, width: 10, height: 8)],
    );

    $rates = $this->adapter->getRates($request, ['FEDEX_INTERNATIONAL_PRIORITY']);

    expect($rates->first()->serviceName)->toBe('FedEx International Priority®');
});

it('quotes nothing in sandbox when no requested service ships internationally', function (): void {
    app(SettingsService::class)->set('sandbox_mode', true);

    $request = new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: 'M5H 2N2',
        destinationCountry: 'CA',
        packages: [new PackageData(weight: 5.0, length: 12, width: 10, height: 8)],
    );

    expect($this->adapter->getRates($request, ['FEDEX_GROUND']))->toBeEmpty();
});

it('leaves domestic sandbox rates to the FedEx sandbox API', function (): void {
    app(SettingsService::class)->set('sandbox_mode', true);

    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Rates::class => MockResponse::make(['output' => ['rateReplyDetails' => []]]),
    ]);

    $request = new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: '90210',
        packages: [new PackageData(weight: 5.0, length: 12, width: 10, height: 8)],
    );

    $this->adapter->getRates($request, ['FEDEX_GROUND']);

    Saloon::assertSent(Rates::class);
});

it('asks FedEx for international rates outside sandbox mode', function (): void {
    app(SettingsService::class)->set('sandbox_mode', false);

    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Rates::class => MockResponse::make(['output' => ['rateReplyDetails' => []]]),
    ]);

    $request = new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: 'M5H 2N2',
        destinationCountry: 'CA',
        packages: [new PackageData(weight: 5.0, length: 12, width: 10, height: 8)],
    );

    $this->adapter->getRates($request, ['FEDEX_INTERNATIONAL_PRIORITY']);

    Saloon::assertSent(Rates::class);
});

it('declines to prepare a sandbox international rate request so the stub answers it', function (): void {
    app(SettingsService::class)->set('sandbox_mode', true);

    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
    ]);

    $request = new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: 'M5H 2N2',
        destinationCountry: 'CA',
        packages: [new PackageData(weight: 5.0, length: 12, width: 10, height: 8)],
    );

    expect($this->adapter->prepareRateRequest($request, ['FEDEX_INTERNATIONAL_PRIORITY']))->toBeNull();
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

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof CreateShipment) {
            return false;
        }

        $body = $request->body()->all();

        assertMatchesFedexSchema($body, 'CreateShipmentRequest');

        return ($body['requestedShipment']['serviceType'] ?? null) === 'FEDEX_GROUND'
            && ($body['requestedShipment']['packagingType'] ?? null) === 'YOUR_PACKAGING'
            && ! isset($body['requestedShipment']['customsClearanceDetail'])
            && ! isset($body['requestedShipment']['smartPostInfoDetail'])
            && ! isset($body['requestedShipment']['shipmentSpecialServices']);
    });
});

it('uses the ship-from country for FedEx customs duties payment', function (): void {
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
        streetAddress: '5985 EXPLORER DR',
        city: 'Mississauga',
        stateOrProvince: 'ON',
        postalCode: 'L4W5K6',
        country: 'CA',
        company: 'RTC',
        phone: '9052125456',
    );

    $toAddress = new AddressData(
        firstName: 'John',
        lastName: 'Doe',
        streetAddress: '1 MARKET ST',
        city: 'Lancaster',
        stateOrProvince: 'PA',
        postalCode: '17601',
        country: 'US',
        phone: '555-987-6543',
    );

    $packageData = new PackageData(weight: 5.0, length: 12, width: 10, height: 8);

    $selectedRate = new RateResponse(
        carrier: 'FedEx',
        serviceCode: 'FEDEX_INTERNATIONAL_PRIORITY',
        serviceName: 'FedEx International Priority',
        price: 12.75,
        metadata: [
            'serviceType' => 'FEDEX_INTERNATIONAL_PRIORITY',
        ],
    );

    $request = new ShipRequest(
        fromAddress: $fromAddress,
        toAddress: $toAddress,
        packageData: $packageData,
        selectedRate: $selectedRate,
        customsItems: [
            new CustomsItem(
                description: 'Dictionaries',
                quantity: 1,
                unitValue: 15,
                weight: 5,
                countryOfOrigin: 'CA',
            ),
        ],
    );

    $this->adapter->createShipment($request);

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof CreateShipment) {
            return false;
        }

        assertMatchesFedexSchema($request->body()->all(), 'CreateShipmentRequest');

        $body = $request->body()->all();

        return ($body['requestedShipment']['customsClearanceDetail']['dutiesPayment']['payor']['responsibleParty']['address']['countryCode'] ?? null) === 'CA';
    });
});

it('includes smart post info detail in create shipment requests for smart post service', function (): void {
    $location = Location::factory()->create(['fedex_hub_id' => '5983']);

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
                                    ['totalNetCharge' => 10.50],
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

    $packageData = new PackageData(weight: 1.8, length: 12, width: 10, height: 8);

    $selectedRate = new RateResponse(
        carrier: 'FedEx',
        serviceCode: 'SMART_POST',
        serviceName: 'FedEx Ground Economy',
        price: 10.50,
        metadata: [
            'serviceType' => 'SMART_POST',
        ],
    );

    $request = new ShipRequest(
        fromAddress: $fromAddress,
        toAddress: $toAddress,
        packageData: $packageData,
        selectedRate: $selectedRate,
        locationId: $location->id,
    );

    $this->adapter->createShipment($request);

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof CreateShipment) {
            return false;
        }

        assertMatchesFedexSchema($request->body()->all(), 'CreateShipmentRequest');

        $body = $request->body()->all();
        $detail = $body['requestedShipment']['smartPostInfoDetail'] ?? [];

        return ($body['accountNumber']['value'] ?? null) === 'test_account'
            && ($body['labelResponseOptions'] ?? null) === 'LABEL'
            && ($body['requestedShipment']['shipper']['contact']['personName'] ?? null) === 'Shipping Center'
            && ($body['requestedShipment']['shipper']['address']['postalCode'] ?? null) === '98072'
            && ($body['requestedShipment']['recipients'][0]['contact']['personName'] ?? null) === 'John Doe'
            && ($body['requestedShipment']['recipients'][0]['address']['postalCode'] ?? null) === '90210'
            && ($body['requestedShipment']['serviceType'] ?? null) === 'SMART_POST'
            && ($body['requestedShipment']['pickupType'] ?? null) === 'USE_SCHEDULED_PICKUP'
            && ($body['requestedShipment']['labelSpecification']['imageType'] ?? null) === 'PDF'
            && ($body['requestedShipment']['requestedPackageLineItems'][0]['weight']['value'] ?? null) === 1.8
            && ($detail['hubId'] ?? null) === '5983'
            && ($detail['indicia'] ?? null) === 'PARCEL_SELECT'
            && ! array_key_exists('ancillaryEndorsement', $detail);
    });
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

it('maps a FedEx tracking response into normalized tracking data', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        TrackShipment::class => MockResponse::make([
            'output' => [
                'completeTrackResults' => [
                    [
                        'trackResults' => [
                            [
                                'latestStatusDetail' => [
                                    'code' => 'IT',
                                    'description' => 'In transit',
                                ],
                                'estimatedDeliveryTimeWindow' => [
                                    'window' => [
                                        'ends' => '2026-04-10T18:00:00Z',
                                    ],
                                ],
                                'scanEvents' => [
                                    [
                                        'date' => '2026-04-08T12:00:00Z',
                                        'eventDescription' => 'Departed FedEx hub',
                                        'derivedStatusCode' => 'IT',
                                        'derivedStatus' => 'IN_TRANSIT',
                                        'scanLocation' => [
                                            'city' => 'Memphis',
                                            'stateOrProvinceCode' => 'TN',
                                            'countryCode' => 'US',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $package = Package::factory()->fedex()->create([
        'carrier' => 'FedEx',
        'tracking_number' => '794644790138',
    ]);

    $response = $this->adapter->trackShipment($package);

    expect($response->success)->toBeTrue()
        ->and($response->status)->toBe(TrackingStatus::InTransit)
        ->and($response->statusLabel)->toBe('In transit')
        ->and($response->estimatedDeliveryAt?->toIso8601String())->toBe('2026-04-10T18:00:00+00:00')
        ->and($response->events)->toHaveCount(1)
        ->and($response->events[0]->description)->toBe('Departed FedEx hub')
        ->and($response->events[0]->location)->toBe('Memphis, TN, US');
});

it('defers to the FedEx summary delivery timestamp when the delivered scan event has no timestamp', function (): void {
    // Aligns FedEx with the bug #1 fix: a delivered scan event (statusCode DL)
    // that carries no parseable timestamp must not short-circuit deliveredAt to
    // null — the shared resolveDeliveredAt template falls through to the summary
    // fallback (latestStatusDetail + dateAndTimes).
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        TrackShipment::class => MockResponse::make([
            'output' => [
                'completeTrackResults' => [
                    [
                        'trackResults' => [
                            [
                                'latestStatusDetail' => [
                                    'code' => 'DL',
                                    'description' => 'Delivered',
                                ],
                                'dateAndTimes' => [
                                    ['dateTime' => '2026-04-14T13:45:00Z'],
                                ],
                                // Delivered scan event, but no date -> null timestamp.
                                'scanEvents' => [
                                    [
                                        'eventDescription' => 'Delivered',
                                        'derivedStatusCode' => 'DL',
                                        'derivedStatus' => 'DELIVERED',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $package = Package::factory()->fedex()->create([
        'carrier' => 'FedEx',
        'tracking_number' => '794644790139',
    ]);

    $response = $this->adapter->trackShipment($package);

    expect($response->status)->toBe(TrackingStatus::Delivered)
        ->and($response->deliveredAt?->format('Y-m-d H:i:s'))->toBe('2026-04-14 13:45:00');
});

it('maps FedEx delivery exceptions and returns into tracking statuses', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        TrackShipment::class => MockResponse::make([
            'output' => [
                'completeTrackResults' => [
                    [
                        'trackResults' => [
                            [
                                'latestStatusDetail' => [
                                    'code' => 'SE',
                                    'description' => 'Shipment exception',
                                ],
                                'scanEvents' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $package = Package::factory()->fedex()->create([
        'carrier' => 'FedEx',
        'tracking_number' => '794644790138',
    ]);

    $response = $this->adapter->trackShipment($package);

    expect($response->status)->toBe(TrackingStatus::Exception);
});

it('maps FedEx ready for pickup statuses away from pre-transit', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        TrackShipment::class => MockResponse::make([
            'output' => [
                'completeTrackResults' => [
                    [
                        'trackResults' => [
                            [
                                'latestStatusDetail' => [
                                    'code' => 'HL',
                                    'description' => 'Ready for pickup',
                                ],
                                'scanEvents' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $package = Package::factory()->fedex()->create([
        'carrier' => 'FedEx',
        'tracking_number' => '794644790138',
    ]);

    $response = $this->adapter->trackShipment($package);

    expect($response->status)->toBe(TrackingStatus::Exception);
});

it('maps package-level special services and declared value into the ship request', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        CreateShipment::class => MockResponse::make([
            'output' => [
                'transactionShipments' => [
                    [
                        'masterTrackingNumber' => '794644790138',
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

    $request = new ShipRequest(
        fromAddress: new AddressData(
            firstName: 'Shipping',
            lastName: 'Center',
            streetAddress: '123 Warehouse St',
            city: 'Seattle',
            stateOrProvince: 'WA',
            postalCode: '98072',
            phone: '555-123-4567',
        ),
        toAddress: new AddressData(
            firstName: 'John',
            lastName: 'Doe',
            streetAddress: '456 Main St',
            city: 'Los Angeles',
            stateOrProvince: 'CA',
            postalCode: '90210',
            phone: '555-987-6543',
        ),
        packageData: new PackageData(weight: 5.0, length: 12, width: 10, height: 8),
        selectedRate: new RateResponse(
            carrier: 'FedEx',
            serviceCode: 'FEDEX_GROUND',
            serviceName: 'FedEx Ground',
            price: 12.75,
            metadata: ['serviceType' => 'FEDEX_GROUND'],
        ),
        specialServiceCodes: ['adult_signature_required', 'alcohol', 'declared_value'],
        specialServiceConfig: ['declared_value' => ['amount' => 250.00, 'currency' => 'USD']],
    );

    $response = $this->adapter->createShipment($request);

    expect($response->success)->toBeTrue()
        ->and($response->appliedServices)->toBe(['adult_signature_required', 'alcohol', 'declared_value']);

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof CreateShipment) {
            return false;
        }

        assertMatchesFedexSchema($request->body()->all(), 'CreateShipmentRequest');

        $lineItem = $request->body()->all()['requestedShipment']['requestedPackageLineItems'][0] ?? [];
        $special = $lineItem['packageSpecialServices'] ?? [];

        return ($special['specialServiceTypes'] ?? null) === ['SIGNATURE_OPTION', 'ALCOHOL']
            && ($special['signatureOptionType'] ?? null) === 'ADULT'
            && ($special['alcoholDetail']['alcoholRecipientType'] ?? null) === 'CONSUMER'
            && ($lineItem['declaredValue'] ?? null) === ['amount' => 250.00, 'currency' => 'USD'];
    });
});

it('omits battery fields from ground ship requests', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        CreateShipment::class => MockResponse::make([
            'output' => [
                'transactionShipments' => [
                    [
                        'masterTrackingNumber' => '794644790138',
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

    $request = new ShipRequest(
        fromAddress: new AddressData(
            firstName: 'Shipping',
            lastName: 'Center',
            streetAddress: '123 Warehouse St',
            city: 'Seattle',
            stateOrProvince: 'WA',
            postalCode: '98072',
            phone: '555-123-4567',
        ),
        toAddress: new AddressData(
            firstName: 'John',
            lastName: 'Doe',
            streetAddress: '456 Main St',
            city: 'Los Angeles',
            stateOrProvince: 'CA',
            postalCode: '90210',
            phone: '555-987-6543',
        ),
        packageData: new PackageData(weight: 5.0, length: 12, width: 10, height: 8),
        selectedRate: new RateResponse(
            carrier: 'FedEx',
            serviceCode: 'FEDEX_GROUND',
            serviceName: 'FedEx Ground',
            price: 12.75,
            metadata: ['serviceType' => 'FEDEX_GROUND'],
        ),
        specialServiceCodes: ['lithium_battery_in_equipment'],
    );

    $response = $this->adapter->createShipment($request);

    // Ground network: battery declaration is an Express/IATA construct —
    // ground ships clean (package marks only, no API fields)
    expect($response->success)->toBeTrue()
        ->and($response->appliedServices)->toBe([]);

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof CreateShipment) {
            return false;
        }

        assertMatchesFedexSchema($request->body()->all(), 'CreateShipmentRequest');

        $lineItem = $request->body()->all()['requestedShipment']['requestedPackageLineItems'][0] ?? [];

        return ! array_key_exists('packageSpecialServices', $lineItem);
    });
});

it('maps battery details into express ship requests', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        CreateShipment::class => MockResponse::make([
            'output' => [
                'transactionShipments' => [
                    [
                        'masterTrackingNumber' => '794644790138',
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

    $request = new ShipRequest(
        fromAddress: new AddressData(
            firstName: 'Shipping',
            lastName: 'Center',
            streetAddress: '123 Warehouse St',
            city: 'Seattle',
            stateOrProvince: 'WA',
            postalCode: '98072',
            phone: '555-123-4567',
        ),
        toAddress: new AddressData(
            firstName: 'John',
            lastName: 'Doe',
            streetAddress: '456 Main St',
            city: 'Los Angeles',
            stateOrProvince: 'CA',
            postalCode: '90210',
            phone: '555-987-6543',
        ),
        packageData: new PackageData(weight: 5.0, length: 12, width: 10, height: 8),
        selectedRate: new RateResponse(
            carrier: 'FedEx',
            serviceCode: 'PRIORITY_OVERNIGHT',
            serviceName: 'FedEx Priority Overnight',
            price: 42.10,
            metadata: ['serviceType' => 'PRIORITY_OVERNIGHT'],
        ),
        specialServiceCodes: ['lithium_battery_in_equipment'],
    );

    $response = $this->adapter->createShipment($request);

    expect($response->success)->toBeTrue()
        ->and($response->appliedServices)->toBe(['lithium_battery_in_equipment']);

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof CreateShipment) {
            return false;
        }

        assertMatchesFedexSchema($request->body()->all(), 'CreateShipmentRequest');

        $special = $request->body()->all()['requestedShipment']['requestedPackageLineItems'][0]['packageSpecialServices'] ?? [];

        // Exact combination the production availability API enumerates (UN3481, PI967)
        return ($special['specialServiceTypes'] ?? null) === ['BATTERY']
            && ($special['batteryDetails'][0]['batteryPackingType'] ?? null) === 'CONTAINED_IN_EQUIPMENT'
            && ($special['batteryDetails'][0]['batteryMaterialType'] ?? null) === 'LITHIUM_ION';
    });
});

it('prints the client-selected reference on the label', function (LabelReferenceSource $source, callable $expected): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        CreateShipment::class => MockResponse::make([
            'output' => [
                'transactionShipments' => [
                    [
                        'masterTrackingNumber' => '794644790138',
                        'completedShipmentDetail' => [
                            'shipmentRating' => ['shipmentRateDetails' => [['totalNetCharge' => 12.75]]],
                        ],
                        'pieceResponses' => [
                            [
                                'trackingNumber' => '794644790138',
                                'packageDocuments' => [['encodedLabel' => 'JVBERi0xLjQKYmFzZTY0bGFiZWxkYXRh']],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $client = Client::factory()->create(['label_reference_source' => $source]);
    $shipment = Shipment::factory()->create([
        'client_id' => $client->id,
        'shipment_reference' => 'ORD-10042',
        'country' => 'US',
    ]);
    $package = Package::factory()->for($shipment)->create();

    $rate = new RateResponse(
        carrier: 'FedEx',
        serviceCode: 'FEDEX_2_DAY',
        serviceName: 'FedEx 2Day',
        price: 12.75,
        metadata: ['serviceType' => 'FEDEX_2_DAY'],
    );

    expect($this->adapter->createShipment(ShipRequest::fromPackageAndRate($package, $rate))->success)->toBeTrue();

    Saloon::assertSent(function ($request) use ($package, $expected): bool {
        if (! $request instanceof CreateShipment) {
            return false;
        }

        assertMatchesFedexSchema($request->body()->all(), 'CreateShipmentRequest');

        $lineItem = $request->body()->all()['requestedShipment']['requestedPackageLineItems'][0];

        return ($lineItem['customerReferences'] ?? null) === $expected($package);
    });
})->with([
    'shipment reference' => [
        LabelReferenceSource::ShipmentReference,
        fn (Package $package): array => [['customerReferenceType' => 'CUSTOMER_REFERENCE', 'value' => 'ORD-10042']],
    ],
    'package id' => [
        LabelReferenceSource::PackageId,
        fn (Package $package): array => [['customerReferenceType' => 'CUSTOMER_REFERENCE', 'value' => (string) $package->id]],
    ],
    'none' => [
        LabelReferenceSource::None,
        fn (Package $package): ?array => null,
    ],
]);

it('sends a recipient phone number stored before its area code was recognized', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        CreateShipment::class => MockResponse::make([
            'output' => [
                'transactionShipments' => [
                    [
                        'masterTrackingNumber' => '794644790138',
                        'completedShipmentDetail' => [
                            'shipmentRating' => ['shipmentRateDetails' => [['totalNetCharge' => 12.75]]],
                        ],
                        'pieceResponses' => [
                            [
                                'trackingNumber' => '794644790138',
                                'packageDocuments' => [['encodedLabel' => 'JVBERi0xLjQKYmFzZTY0bGFiZWxkYXRh']],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $shipment = Shipment::factory()->create([
        'country' => 'US',
        'phone' => '370-579-7375',
    ]);

    // Imported while libphonenumber rejected the 370 area code, so the row kept
    // the phone number the customer gave and nothing normalized. Written past the
    // model's saving hook, which would re-parse it now.
    Shipment::whereKey($shipment->id)->update(['phone_e164' => null]);

    $package = Package::factory()->for($shipment->refresh())->create();

    $rate = new RateResponse(
        carrier: 'FedEx',
        serviceCode: 'FEDEX_2_DAY',
        serviceName: 'FedEx 2Day',
        price: 12.75,
        metadata: ['serviceType' => 'FEDEX_2_DAY'],
    );

    $response = $this->adapter->createShipment(ShipRequest::fromPackageAndRate($package, $rate));

    expect($response->success)->toBeTrue();

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof CreateShipment) {
            return false;
        }

        assertMatchesFedexSchema($request->body()->all(), 'CreateShipmentRequest');

        $contact = $request->body()->all()['requestedShipment']['recipients'][0]['contact'] ?? [];

        return ($contact['phoneNumber'] ?? null) === '3705797375';
    });
});

it('uses the shipper phone when the recipient has no usable phone number', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        CreateShipment::class => MockResponse::make([
            'output' => [
                'transactionShipments' => [
                    [
                        'masterTrackingNumber' => '794644790138',
                        'completedShipmentDetail' => [
                            'shipmentRating' => ['shipmentRateDetails' => [['totalNetCharge' => 12.75]]],
                        ],
                        'pieceResponses' => [
                            [
                                'trackingNumber' => '794644790138',
                                'packageDocuments' => [['encodedLabel' => 'JVBERi0xLjQKYmFzZTY0bGFiZWxkYXRh']],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $request = new ShipRequest(
        fromAddress: new AddressData(
            firstName: 'Shipping',
            lastName: 'Center',
            streetAddress: '123 Warehouse St',
            city: 'Seattle',
            stateOrProvince: 'WA',
            postalCode: '98072',
            phone: '4255551234',
        ),
        toAddress: new AddressData(
            firstName: 'John',
            lastName: 'Doe',
            streetAddress: '456 Customer Ave',
            city: 'Austin',
            stateOrProvince: 'TX',
            postalCode: '78701',
        ),
        packageData: new PackageData(weight: 0.95, length: 4, width: 6, height: 6),
        selectedRate: new RateResponse(
            carrier: 'FedEx',
            serviceCode: 'FEDEX_2_DAY',
            serviceName: 'FedEx 2Day',
            price: 12.75,
            metadata: ['serviceType' => 'FEDEX_2_DAY'],
        ),
    );

    expect($this->adapter->createShipment($request)->success)->toBeTrue();

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof CreateShipment) {
            return false;
        }

        assertMatchesFedexSchema($request->body()->all(), 'CreateShipmentRequest');

        $contact = $request->body()->all()['requestedShipment']['recipients'][0]['contact'] ?? [];

        return ($contact['phoneNumber'] ?? null) === '4255551234';
    });
});

it('does not stand in the shipper phone when the recipient clears customs', function (array $destination): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        CreateShipment::class => MockResponse::make([
            'output' => [
                'transactionShipments' => [
                    [
                        'masterTrackingNumber' => '794644790138',
                        'completedShipmentDetail' => [
                            'shipmentRating' => ['shipmentRateDetails' => [['totalNetCharge' => 12.75]]],
                        ],
                        'pieceResponses' => [
                            [
                                'trackingNumber' => '794644790138',
                                'packageDocuments' => [['encodedLabel' => 'JVBERi0xLjQKYmFzZTY0bGFiZWxkYXRh']],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $request = new ShipRequest(
        fromAddress: new AddressData(
            firstName: 'Shipping',
            lastName: 'Center',
            streetAddress: '123 Warehouse St',
            city: 'Seattle',
            stateOrProvince: 'WA',
            postalCode: '98072',
            phone: '4255551234',
        ),
        toAddress: new AddressData(
            firstName: 'John',
            lastName: 'Doe',
            streetAddress: '456 Customer Ave',
            city: $destination['city'],
            stateOrProvince: $destination['stateOrProvince'],
            postalCode: $destination['postalCode'],
            country: $destination['country'],
        ),
        packageData: new PackageData(weight: 0.95, length: 4, width: 6, height: 6),
        selectedRate: new RateResponse(
            carrier: 'FedEx',
            serviceCode: 'FEDEX_2_DAY',
            serviceName: 'FedEx 2Day',
            price: 12.75,
            metadata: ['serviceType' => 'FEDEX_2_DAY'],
        ),
        customsItems: [
            new CustomsItem(description: 'Dictionaries', quantity: 2, unitValue: 25.235, weight: 0.4),
        ],
    );

    expect($this->adapter->createShipment($request)->success)->toBeTrue();

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof CreateShipment) {
            return false;
        }

        assertMatchesFedexSchema($request->body()->all(), 'CreateShipmentRequest');

        $contact = $request->body()->all()['requestedShipment']['recipients'][0]['contact'] ?? [];

        return ! array_key_exists('phoneNumber', $contact);
    });
})->with([
    'us territory' => [['city' => 'San Lorenzo', 'stateOrProvince' => 'PR', 'postalCode' => '00754', 'country' => 'US']],
    'military post office' => [['city' => 'APO', 'stateOrProvince' => 'AE', 'postalCode' => '09123', 'country' => 'US']],
    'foreign country' => [['city' => 'Toronto', 'stateOrProvince' => 'ON', 'postalCode' => 'M5V 2T6', 'country' => 'CA']],
]);

it('declares customs for a Puerto Rico destination that shares the US country code', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        CreateShipment::class => MockResponse::make([
            'output' => [
                'transactionShipments' => [
                    [
                        'masterTrackingNumber' => '794644790138',
                        'completedShipmentDetail' => [
                            'shipmentRating' => ['shipmentRateDetails' => [['totalNetCharge' => 12.75]]],
                        ],
                        'pieceResponses' => [
                            [
                                'trackingNumber' => '794644790138',
                                'packageDocuments' => [['encodedLabel' => 'JVBERi0xLjQKYmFzZTY0bGFiZWxkYXRh']],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $request = new ShipRequest(
        fromAddress: new AddressData(
            firstName: 'Shipping',
            lastName: 'Center',
            streetAddress: '123 Warehouse St',
            city: 'Seattle',
            stateOrProvince: 'WA',
            postalCode: '98072',
            phone: '5551234567',
        ),
        toAddress: new AddressData(
            firstName: 'John',
            lastName: 'Doe',
            streetAddress: 'PO BOX 1686',
            city: 'San Lorenzo',
            stateOrProvince: 'PR',
            postalCode: '00754',
            country: 'US',
            phone: '7999078831',
        ),
        packageData: new PackageData(weight: 0.95, length: 4, width: 6, height: 6),
        selectedRate: new RateResponse(
            carrier: 'FedEx',
            serviceCode: 'FEDEX_2_DAY',
            serviceName: 'FedEx 2Day',
            price: 12.75,
            metadata: ['serviceType' => 'FEDEX_2_DAY'],
        ),
        customsItems: [
            new CustomsItem(description: 'Dictionaries', quantity: 2, unitValue: 25.235, weight: 0.4),
        ],
    );

    expect($this->adapter->createShipment($request)->success)->toBeTrue();

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof CreateShipment) {
            return false;
        }

        assertMatchesFedexSchema($request->body()->all(), 'CreateShipmentRequest');

        $commodity = $request->body()->all()['requestedShipment']['customsClearanceDetail']['commodities'][0] ?? [];

        return ($commodity['customsValue']['amount'] ?? null) === '50.47'
            && ($commodity['customsValue']['currency'] ?? null) === 'USD';
    });
});

it('fails before calling FedEx when customs applies but nothing can be declared', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
    ]);

    $request = new ShipRequest(
        fromAddress: new AddressData(
            firstName: 'Shipping',
            lastName: 'Center',
            streetAddress: '123 Warehouse St',
            city: 'Seattle',
            stateOrProvince: 'WA',
            postalCode: '98072',
            phone: '5551234567',
        ),
        toAddress: new AddressData(
            firstName: 'John',
            lastName: 'Doe',
            streetAddress: 'PO BOX 1686',
            city: 'San Lorenzo',
            stateOrProvince: 'PR',
            postalCode: '00754',
            country: 'US',
            phone: '7999078831',
        ),
        packageData: new PackageData(weight: 0.95, length: 4, width: 6, height: 6),
        selectedRate: new RateResponse(
            carrier: 'FedEx',
            serviceCode: 'FEDEX_2_DAY',
            serviceName: 'FedEx 2Day',
            price: 12.75,
            metadata: ['serviceType' => 'FEDEX_2_DAY'],
        ),
    );

    $response = $this->adapter->createShipment($request);

    expect($response->success)->toBeFalse()
        ->and($response->errorMessage)->toContain('customs declaration');

    Saloon::assertNotSent(CreateShipment::class);
});

/*
|--------------------------------------------------------------------------
| Request schema conformance
|--------------------------------------------------------------------------
|
| Every CreateShipment closure above validates the whole body against our
| hand-written schema in tests/Fixtures/Schemas/fedexShip.json. These two
| exercise the branches nothing above reaches at ship level: the sub-pound
| SmartPost indicia, and a ZPL One Rate label with Saturday delivery.
|
*/

function fakeFedexShipEndpoints(): void
{
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        CreateShipment::class => MockResponse::make([
            'output' => [
                'transactionShipments' => [
                    [
                        'masterTrackingNumber' => '794644790138',
                        'completedShipmentDetail' => [
                            'shipmentRating' => ['shipmentRateDetails' => [['totalNetCharge' => 12.75]]],
                        ],
                        'pieceResponses' => [
                            [
                                'trackingNumber' => '794644790138',
                                'packageDocuments' => [['encodedLabel' => 'JVBERi0xLjQKYmFzZTY0bGFiZWxkYXRh']],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);
}

it('builds a sub-pound SmartPost ship request that conforms to our FedEx schema', function (): void {
    $location = Location::factory()->create(['fedex_hub_id' => '5983']);

    fakeFedexShipEndpoints();

    $request = new ShipRequest(
        fromAddress: new AddressData(
            firstName: 'Shipping',
            lastName: 'Center',
            streetAddress: '123 Warehouse St',
            city: 'Seattle',
            stateOrProvince: 'WA',
            postalCode: '98072',
            phone: '5551234567',
        ),
        toAddress: new AddressData(
            firstName: 'John',
            lastName: 'Doe',
            streetAddress: '456 Main St',
            city: 'Los Angeles',
            stateOrProvince: 'CA',
            postalCode: '90210',
            phone: '5559876543',
        ),
        packageData: new PackageData(weight: 0.6, length: 10, width: 8, height: 2),
        selectedRate: new RateResponse(
            carrier: 'FedEx',
            serviceCode: 'SMART_POST',
            serviceName: 'FedEx Ground Economy',
            price: 8.10,
            metadata: ['serviceType' => 'SMART_POST'],
        ),
        locationId: $location->id,
    );

    expect($this->adapter->createShipment($request)->success)->toBeTrue();

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof CreateShipment) {
            return false;
        }

        $body = $request->body()->all();

        assertMatchesFedexSchema($body, 'CreateShipmentRequest');

        return ($body['requestedShipment']['smartPostInfoDetail'] ?? null) === [
            'hubId' => '5983',
            'indicia' => 'PRESORTED_STANDARD',
            'ancillaryEndorsement' => 'ADDRESS_CORRECTION',
        ];
    });
});

it('builds a ZPL One Rate ship request with Saturday delivery that conforms to our FedEx schema', function (): void {
    fakeFedexShipEndpoints();

    $request = new ShipRequest(
        fromAddress: new AddressData(
            firstName: 'Shipping',
            lastName: 'Center',
            streetAddress: '123 Warehouse St',
            streetAddress2: 'Dock 4',
            city: 'Seattle',
            stateOrProvince: 'WA',
            postalCode: '98072',
            company: 'Test Company',
            phone: '5551234567',
        ),
        toAddress: new AddressData(
            firstName: '',
            lastName: '',
            streetAddress: '456 Main St',
            city: 'Los Angeles',
            stateOrProvince: 'CA',
            postalCode: '90210',
            company: 'Acme Corp',
            phone: '5559876543',
            phoneExtension: '12',
        ),
        packageData: new PackageData(weight: 2.0, length: 12, width: 10, height: 8, fedexPackageType: FedexPackageType::FEDEX_SMALL_BOX),
        selectedRate: new RateResponse(
            carrier: 'FedEx',
            serviceCode: 'PRIORITY_OVERNIGHT',
            serviceName: 'FedEx Priority Overnight',
            price: 42.10,
            metadata: [
                'serviceType' => 'PRIORITY_OVERNIGHT',
                'isOneRate' => true,
                'fedexPackageType' => 'FEDEX_SMALL_BOX',
            ],
        ),
        labelFormat: 'zpl',
        labelDpi: 300,
        specialServiceCodes: ['saturday_delivery'],
        shipDate: CarbonImmutable::parse('2026-09-11'),
        references: ['ORD-10042'],
    );

    $response = $this->adapter->createShipment($request);

    expect($response->success)->toBeTrue()
        ->and($response->appliedServices)->toBe(['saturday_delivery']);

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof CreateShipment) {
            return false;
        }

        $body = $request->body()->all();

        assertMatchesFedexSchema($body, 'CreateShipmentRequest');

        $shipment = $body['requestedShipment'];

        // A recipient with no person name is sent by company alone; the
        // shipper's second address line rides in streetLines.
        return $shipment['shipDateStamp'] === '2026-09-11'
            && $shipment['packagingType'] === 'FEDEX_SMALL_BOX'
            && $shipment['labelSpecification'] === [
                'labelFormatType' => 'COMMON2D',
                'imageType' => 'ZPLII',
                'labelStockType' => 'STOCK_4X6',
                'resolution' => 300,
            ]
            && $shipment['shipmentSpecialServices'] === ['specialServiceTypes' => ['FEDEX_ONE_RATE', 'SATURDAY_DELIVERY']]
            && $shipment['shipper']['address']['streetLines'] === ['123 Warehouse St', 'Dock 4']
            && ! isset($shipment['recipients'][0]['contact']['personName'])
            && $shipment['recipients'][0]['contact']['companyName'] === 'Acme Corp'
            && $shipment['recipients'][0]['contact']['phoneExtension'] === '12'
            && $shipment['requestedPackageLineItems'][0]['customerReferences'] === [
                ['customerReferenceType' => 'CUSTOMER_REFERENCE', 'value' => 'ORD-10042'],
            ];
    });
});
