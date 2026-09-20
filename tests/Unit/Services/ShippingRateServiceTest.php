<?php

use App\Contracts\AsyncRateQuoting;
use App\Contracts\CarrierAdapterInterface;
use App\Contracts\DirectCarrierAdapter;
use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\PackageData;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\PreparedRateRequest;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\CarrierPackaging;
use App\Enums\CustomsDocumentDelivery;
use App\Enums\ServiceCapability;
use App\Exceptions\Carriers\CarrierRateFetchException;
use App\Exceptions\InvalidPackageDimensionsException;
use App\Exceptions\MissingDeclaredValueException;
use App\Exceptions\NoActiveCarrierServicesException;
use App\Http\Integrations\Fedex\Requests\Rates as FedexRates;
use App\Http\Integrations\Ups\Requests\Rate as UpsRate;
use App\Http\Integrations\USPS\Requests\ShippingOptions;
use App\Models\BoxSize;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\CarrierService;
use App\Models\Package;
use App\Models\PackageItem;
use App\Models\Product;
use App\Models\RateQuote;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Models\SpecialService;
use App\Services\Carriers\CarrierRegistry;
use App\Services\Carriers\UspsAdapter;
use App\Services\SettingsService;
use App\Services\ShippingRateService;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Saloon\Enums\Method;
use Saloon\Http\Connector;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\Http\Senders\GuzzleSender;
use Saloon\Laravel\Facades\Saloon;

beforeEach(function (): void {
    $this->uspsCarrier = Carrier::factory()->usps()->create();
    $this->fedexCarrier = Carrier::factory()->fedex()->create();

    // Credentials live on CarrierAccount now; configure both carriers so the
    // adapters resolve credentials and report isConfigured().
    createUspsAccount();
    createFedexAccount();
});

afterEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

it('fetches USPS rates for a package', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ShippingOptions::class => MockResponse::make([
            'pricingOptions' => [
                [
                    'shippingOptions' => [
                        [
                            'rateOptions' => [
                                [
                                    'totalBasePrice' => 8.50,
                                    'commitment' => [
                                        'name' => '2-5 Business Days',
                                        'scheduleDeliveryDate' => '2025-01-15',
                                    ],
                                    'rates' => [
                                        [
                                            'mailClass' => 'USPS_GROUND_ADVANTAGE',
                                            'processingCategory' => 'MACHINABLE',
                                            'rateIndicator' => 'SP',
                                            'destinationEntryFacilityType' => 'NONE',
                                            'description' => 'USPS Ground Advantage',
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

    $shippingMethod = ShippingMethod::factory()->create();
    $uspsService = CarrierService::factory()
        ->uspsGroundAdvantage()
        ->for($this->uspsCarrier)
        ->create();
    $shippingMethod->carrierServices()->attach($uspsService);

    $shipment = Shipment::factory()
        ->for($shippingMethod)
        ->create(['postal_code' => '90210']);
    $package = Package::factory()
        ->for($shipment)
        ->create([
            'weight' => 2.5,
            'height' => 6,
            'width' => 8,
            'length' => 10,
        ]);

    $rates = app(ShippingRateService::class)->getShippingRates($package->id);

    expect($rates)->toBeInstanceOf(Collection::class)
        ->and($rates)->toHaveCount(1)
        ->and($rates[0]->carrier)->toBe('USPS')
        ->and($rates[0]->price)->toBe(8.50)
        ->and($rates[0]->metadata['mailClass'])->toBe('USPS_GROUND_ADVANTAGE');

    Saloon::assertSent(ShippingOptions::class);
});

it('fetches FedEx rates for a package', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        FedexRates::class => MockResponse::make([
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

    $shippingMethod = ShippingMethod::factory()->create();
    $fedexService = CarrierService::factory()
        ->fedexGround()
        ->for($this->fedexCarrier)
        ->create();
    $shippingMethod->carrierServices()->attach($fedexService);

    $shipment = Shipment::factory()
        ->for($shippingMethod)
        ->create(['postal_code' => '90210']);
    $package = Package::factory()
        ->for($shipment)
        ->create([
            'weight' => 5.0,
            'height' => 8,
            'width' => 10,
            'length' => 12,
        ]);

    $rates = app(ShippingRateService::class)->getShippingRates($package->id);

    expect($rates)->toBeInstanceOf(Collection::class)
        ->and($rates)->toHaveCount(1)
        ->and($rates[0]->carrier)->toBe('FedEx')
        ->and($rates[0]->price)->toBe(12.75)
        ->and($rates[0]->metadata['serviceType'])->toBe('FEDEX_GROUND');

    Saloon::assertSent(FedexRates::class);
});

it('fetches rates from multiple carriers', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ShippingOptions::class => MockResponse::make([
            'pricingOptions' => [
                [
                    'shippingOptions' => [
                        [
                            'rateOptions' => [
                                [
                                    'totalBasePrice' => 7.25,
                                    'commitment' => ['name' => '2-5 Business Days'],
                                    'rates' => [
                                        [
                                            'mailClass' => 'USPS_GROUND_ADVANTAGE',
                                            'processingCategory' => 'MACHINABLE',
                                            'rateIndicator' => 'SP',
                                            'destinationEntryFacilityType' => 'NONE',
                                            'description' => 'USPS Ground Advantage',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
        FedexRates::class => MockResponse::make([
            'output' => [
                'rateReplyDetails' => [
                    [
                        'serviceType' => 'FEDEX_GROUND',
                        'serviceName' => 'FedEx Ground',
                        'ratedShipmentDetails' => [['totalNetCharge' => 11.50]],
                        'commit' => ['transitDays' => 'THREE_DAYS'],
                    ],
                ],
            ],
        ]),
    ]);

    $shippingMethod = ShippingMethod::factory()->create();
    $uspsService = CarrierService::factory()
        ->uspsGroundAdvantage()
        ->for($this->uspsCarrier)
        ->create();
    $fedexService = CarrierService::factory()
        ->fedexGround()
        ->for($this->fedexCarrier)
        ->create();
    $shippingMethod->carrierServices()->attach([$uspsService->id, $fedexService->id]);

    $shipment = Shipment::factory()
        ->for($shippingMethod)
        ->create(['postal_code' => '90210']);
    $package = Package::factory()
        ->for($shipment)
        ->create();

    $rates = app(ShippingRateService::class)->getShippingRates($package->id);

    expect($rates)->toBeInstanceOf(Collection::class)
        ->and($rates)->toHaveCount(2);

    $carriers = $rates->pluck('carrier')->toArray();
    expect($carriers)->toContain('USPS')
        ->and($carriers)->toContain('FedEx');

    Saloon::assertSent(ShippingOptions::class);
    Saloon::assertSent(FedexRates::class);
});

it('throws exception when no carrier services configured', function (): void {
    $shippingMethod = ShippingMethod::factory()->create(['name' => 'No Services Method']);

    $shipment = Shipment::factory()
        ->for($shippingMethod)
        ->create();
    $package = Package::factory()
        ->for($shipment)
        ->create();

    expect(fn () => app(ShippingRateService::class)->getShippingRates($package->id))
        ->toThrow(NoActiveCarrierServicesException::class, "No active carrier services available for shipping method 'No Services Method'");
});

it('filters out non-applicable USPS rate options', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ShippingOptions::class => MockResponse::make([
            'pricingOptions' => [
                [
                    'shippingOptions' => [
                        [
                            'rateOptions' => [
                                // Should be included - valid machinable parcel
                                [
                                    'totalBasePrice' => 8.50,
                                    'commitment' => ['name' => '2-5 Days'],
                                    'rates' => [
                                        [
                                            'mailClass' => 'USPS_GROUND_ADVANTAGE',
                                            'processingCategory' => 'MACHINABLE',
                                            'rateIndicator' => 'SP',
                                            'destinationEntryFacilityType' => 'NONE',
                                            'description' => 'Ground Advantage',
                                        ],
                                    ],
                                ],
                                // Should be filtered - LETTERS processing category
                                [
                                    'totalBasePrice' => 2.00,
                                    'rates' => [
                                        [
                                            'mailClass' => 'USPS_GROUND_ADVANTAGE',
                                            'processingCategory' => 'LETTERS',
                                            'rateIndicator' => 'SP',
                                            'destinationEntryFacilityType' => 'NONE',
                                        ],
                                    ],
                                ],
                                // Should be filtered - MEDIA_MAIL class
                                [
                                    'totalBasePrice' => 3.00,
                                    'rates' => [
                                        [
                                            'mailClass' => 'MEDIA_MAIL',
                                            'processingCategory' => 'MACHINABLE',
                                            'rateIndicator' => 'SP',
                                            'destinationEntryFacilityType' => 'NONE',
                                        ],
                                    ],
                                ],
                                // Should be filtered - wrong rate indicator
                                [
                                    'totalBasePrice' => 4.00,
                                    'rates' => [
                                        [
                                            'mailClass' => 'USPS_GROUND_ADVANTAGE',
                                            'processingCategory' => 'MACHINABLE',
                                            'rateIndicator' => 'DN',
                                            'destinationEntryFacilityType' => 'NONE',
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

    $shippingMethod = ShippingMethod::factory()->create();
    $uspsService = CarrierService::factory()
        ->uspsGroundAdvantage()
        ->for($this->uspsCarrier)
        ->create();
    $shippingMethod->carrierServices()->attach($uspsService);

    $shipment = Shipment::factory()
        ->for($shippingMethod)
        ->create();
    $package = Package::factory()
        ->for($shipment)
        ->create();

    $rates = app(ShippingRateService::class)->getShippingRates($package->id);

    expect($rates)->toHaveCount(1)
        ->and($rates[0]->price)->toBe(8.50);
});

it('falls back to all configured carriers when shipment has no shipping method', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ShippingOptions::class => MockResponse::make([
            'pricingOptions' => [
                [
                    'shippingOptions' => [
                        [
                            'rateOptions' => [
                                [
                                    'totalBasePrice' => 8.50,
                                    'commitment' => ['name' => '2-5 Business Days'],
                                    'rates' => [
                                        [
                                            'mailClass' => 'USPS_GROUND_ADVANTAGE',
                                            'processingCategory' => 'MACHINABLE',
                                            'rateIndicator' => 'SP',
                                            'destinationEntryFacilityType' => 'NONE',
                                            'description' => 'USPS Ground Advantage',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
        FedexRates::class => MockResponse::make([
            'output' => [
                'rateReplyDetails' => [
                    [
                        'serviceType' => 'FEDEX_GROUND',
                        'serviceName' => 'FedEx Ground',
                        'ratedShipmentDetails' => [['totalNetCharge' => 11.50]],
                        'commit' => ['transitDays' => 'THREE_DAYS'],
                    ],
                ],
            ],
        ]),
    ]);

    Setting::updateOrCreate(['key' => 'usps.client_id'], ['value' => 'test', 'type' => 'string']);
    Setting::updateOrCreate(['key' => 'usps.client_secret'], ['value' => 'test', 'type' => 'string']);
    Setting::updateOrCreate(['key' => 'usps.crid'], ['value' => 'test', 'type' => 'string']);
    Setting::updateOrCreate(['key' => 'fedex.api_key'], ['value' => 'test', 'type' => 'string']);
    Setting::updateOrCreate(['key' => 'fedex.api_secret'], ['value' => 'test', 'type' => 'string']);
    Setting::updateOrCreate(['key' => 'fedex.account_number'], ['value' => 'test', 'type' => 'string']);
    app(SettingsService::class)->clearCache();

    // Create shipment with NO shipping method
    $shipment = Shipment::factory()->create([
        'shipping_method_id' => null,
        'postal_code' => '90210',
    ]);
    $package = Package::factory()
        ->for($shipment)
        ->create([
            'weight' => 2.5,
            'height' => 6,
            'width' => 8,
            'length' => 10,
        ]);

    $rates = app(ShippingRateService::class)->getShippingRates($package->id);

    expect($rates)->toBeInstanceOf(Collection::class)
        ->and($rates)->toHaveCount(2);

    $carriers = $rates->pluck('carrier')->toArray();
    expect($carriers)->toContain('USPS')
        ->and($carriers)->toContain('FedEx');
});

it('only returns rates for configured service codes', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        FedexRates::class => MockResponse::make([
            'output' => [
                'rateReplyDetails' => [
                    [
                        'serviceType' => 'FEDEX_GROUND',
                        'serviceName' => 'FedEx Ground',
                        'ratedShipmentDetails' => [['totalNetCharge' => 10.00]],
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

    $shippingMethod = ShippingMethod::factory()->create();
    // Only configure FEDEX_GROUND - other services should be filtered
    $fedexService = CarrierService::factory()
        ->fedexGround()
        ->for($this->fedexCarrier)
        ->create();
    $shippingMethod->carrierServices()->attach($fedexService);

    $shipment = Shipment::factory()
        ->for($shippingMethod)
        ->create();
    $package = Package::factory()
        ->for($shipment)
        ->create();

    $rates = app(ShippingRateService::class)->getShippingRates($package->id);

    expect($rates)->toHaveCount(1)
        ->and($rates[0]->metadata['serviceType'])->toBe('FEDEX_GROUND')
        ->and($rates[0]->price)->toBe(10.00);
});

it('excludes inactive carrier services from rate shopping', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ShippingOptions::class => MockResponse::make([
            'pricingOptions' => [
                [
                    'shippingOptions' => [
                        [
                            'rateOptions' => [
                                [
                                    'totalBasePrice' => 8.50,
                                    'commitment' => ['name' => '2-5 Business Days'],
                                    'rates' => [
                                        [
                                            'mailClass' => 'USPS_GROUND_ADVANTAGE',
                                            'processingCategory' => 'MACHINABLE',
                                            'rateIndicator' => 'SP',
                                            'destinationEntryFacilityType' => 'NONE',
                                            'description' => 'USPS Ground Advantage',
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

    $shippingMethod = ShippingMethod::factory()->create();

    // Create active USPS service
    $activeService = CarrierService::factory()
        ->uspsGroundAdvantage()
        ->for($this->uspsCarrier)
        ->create(['active' => true]);

    // Create inactive Priority Mail service
    $inactiveService = CarrierService::factory()
        ->uspsPriority()
        ->for($this->uspsCarrier)
        ->create(['active' => false]);

    $shippingMethod->carrierServices()->attach([$activeService->id, $inactiveService->id]);

    $shipment = Shipment::factory()
        ->for($shippingMethod)
        ->create(['postal_code' => '90210']);
    $package = Package::factory()
        ->for($shipment)
        ->create();

    $rates = app(ShippingRateService::class)->getShippingRates($package->id);

    // Only the active service should be queried
    expect($rates)->toHaveCount(1)
        ->and($rates[0]->metadata['mailClass'])->toBe('USPS_GROUND_ADVANTAGE');
});

it('excludes carrier services with inactive carriers from rate shopping', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        FedexRates::class => MockResponse::make([
            'output' => [
                'rateReplyDetails' => [
                    [
                        'serviceType' => 'FEDEX_GROUND',
                        'serviceName' => 'FedEx Ground',
                        'ratedShipmentDetails' => [['totalNetCharge' => 12.75]],
                        'commit' => ['transitDays' => 'THREE_DAYS'],
                    ],
                ],
            ],
        ]),
    ]);

    // Make USPS carrier inactive
    $this->uspsCarrier->update(['active' => false]);

    $shippingMethod = ShippingMethod::factory()->create();

    // Create services for both carriers (USPS is inactive, FedEx is active)
    $uspsService = CarrierService::factory()
        ->uspsGroundAdvantage()
        ->for($this->uspsCarrier)
        ->create(['active' => true]); // Service is active but carrier is not

    $fedexService = CarrierService::factory()
        ->fedexGround()
        ->for($this->fedexCarrier)
        ->create(['active' => true]);

    $shippingMethod->carrierServices()->attach([$uspsService->id, $fedexService->id]);

    $shipment = Shipment::factory()
        ->for($shippingMethod)
        ->create(['postal_code' => '90210']);
    $package = Package::factory()
        ->for($shipment)
        ->create();

    $rates = app(ShippingRateService::class)->getShippingRates($package->id);

    // Only FedEx should be queried (USPS carrier is inactive)
    expect($rates)->toHaveCount(1)
        ->and($rates[0]->carrier)->toBe('FedEx');

    Saloon::assertSent(FedexRates::class);
    Saloon::assertNotSent(ShippingOptions::class);
});

it('throws exception when no active carrier services are available', function (): void {
    $shippingMethod = ShippingMethod::factory()->create(['name' => 'Test Method']);

    // Create an inactive carrier service
    $inactiveService = CarrierService::factory()
        ->uspsGroundAdvantage()
        ->for($this->uspsCarrier)
        ->create(['active' => false]);

    $shippingMethod->carrierServices()->attach($inactiveService);

    $shipment = Shipment::factory()
        ->for($shippingMethod)
        ->create();
    $package = Package::factory()
        ->for($shipment)
        ->create();

    expect(fn () => app(ShippingRateService::class)->getShippingRates($package->id))
        ->toThrow(NoActiveCarrierServicesException::class, "No active carrier services available for shipping method 'Test Method'");
});

it('throws exception when all carriers are inactive', function (): void {
    // Make both carriers inactive
    $this->uspsCarrier->update(['active' => false]);
    $this->fedexCarrier->update(['active' => false]);

    $shippingMethod = ShippingMethod::factory()->create(['name' => 'Ground Shipping']);

    // Create active services but for inactive carriers
    $uspsService = CarrierService::factory()
        ->uspsGroundAdvantage()
        ->for($this->uspsCarrier)
        ->create(['active' => true]);

    $fedexService = CarrierService::factory()
        ->fedexGround()
        ->for($this->fedexCarrier)
        ->create(['active' => true]);

    $shippingMethod->carrierServices()->attach([$uspsService->id, $fedexService->id]);

    $shipment = Shipment::factory()
        ->for($shippingMethod)
        ->create();
    $package = Package::factory()
        ->for($shipment)
        ->create();

    expect(fn () => app(ShippingRateService::class)->getShippingRates($package->id))
        ->toThrow(NoActiveCarrierServicesException::class);
});

it('falls back to RETAIL pricing when CONTRACT returns 403', function (): void {
    $account = CarrierAccount::where('carrier_id', $this->uspsCarrier->id)->firstOrFail();
    Cache::forget("usps_pricing_type:{$account->id}");

    $uspsRateResponse = [
        'pricingOptions' => [[
            'shippingOptions' => [[
                'rateOptions' => [[
                    'totalBasePrice' => 9.00,
                    'commitment' => ['name' => '2-5 Business Days'],
                    'rates' => [[
                        'mailClass' => 'USPS_GROUND_ADVANTAGE',
                        'processingCategory' => 'MACHINABLE',
                        'rateIndicator' => 'SP',
                        'destinationEntryFacilityType' => 'NONE',
                        'description' => 'USPS Ground Advantage',
                    ]],
                ]],
            ]],
        ]],
    ];

    // Use a stateful callable — first send returns 403, retry returns success.
    // Tested directly on UspsAdapter to avoid ShippingRateService's Phase 1 prepareRateRequest
    // consuming a mock call before getRates is invoked.
    $callCount = 0;
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ShippingOptions::class => function () use (&$callCount, $uspsRateResponse): MockResponse {
            $callCount++;

            return $callCount === 1
                ? MockResponse::make(['error' => ['code' => '403', 'message' => 'Not authorized']], 403)
                : MockResponse::make($uspsRateResponse);
        },
    ]);

    $rateRequest = new RateRequest(
        originPostalCode: '90210',
        destinationPostalCode: '10001',
        packages: [new PackageData(weight: 2.5, length: 10, width: 8, height: 6)],
    );

    $rates = app(UspsAdapter::class)->getRates($rateRequest, []);

    expect($rates)->toHaveCount(1)
        ->and($rates[0]->price)->toBe(9.00);

    expect(Cache::get("usps_pricing_type:{$account->id}"))->toBe('RETAIL');
});

it('returns rates from healthy carriers when one carrier rate fetch fails', function (): void {
    $upsCarrier = Carrier::factory()->ups()->create();

    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ShippingOptions::class => MockResponse::make([
            'pricingOptions' => [[
                'shippingOptions' => [[
                    'rateOptions' => [[
                        'totalBasePrice' => 8.50,
                        'commitment' => ['name' => '2-5 Business Days'],
                        'rates' => [[
                            'mailClass' => 'USPS_GROUND_ADVANTAGE',
                            'processingCategory' => 'MACHINABLE',
                            'rateIndicator' => 'SP',
                            'destinationEntryFacilityType' => 'NONE',
                            'description' => 'USPS Ground Advantage',
                        ]],
                    ]],
                ]],
            ]],
        ]),
        UpsRate::class => MockResponse::make(['errors' => [['message' => 'Service Unavailable']]], 503),
    ]);

    Setting::updateOrCreate(['key' => 'ups.client_id'], ['value' => 'test', 'type' => 'string']);
    Setting::updateOrCreate(['key' => 'ups.client_secret'], ['value' => 'test', 'type' => 'string']);
    Setting::updateOrCreate(['key' => 'ups.account_number'], ['value' => 'test', 'type' => 'string']);
    app(SettingsService::class)->clearCache();

    $shippingMethod = ShippingMethod::factory()->create();
    $uspsService = CarrierService::factory()->uspsGroundAdvantage()->for($this->uspsCarrier)->create();
    $upsService = CarrierService::factory()->upsGround()->for($upsCarrier)->create();
    $shippingMethod->carrierServices()->attach([$uspsService->id, $upsService->id]);

    $shipment = Shipment::factory()->for($shippingMethod)->create(['postal_code' => '90210']);
    $package = Package::factory()->for($shipment)->create([
        'weight' => 2.5, 'height' => 6, 'width' => 8, 'length' => 10,
    ]);

    $rates = app(ShippingRateService::class)->getShippingRates($package->id);

    // USPS rates should be present even though UPS threw CarrierRateFetchException
    expect($rates)->toHaveCount(1)
        ->and($rates[0]->carrier)->toBe('USPS')
        ->and($rates[0]->price)->toBe(8.50);
});

it('continues when carrier rate fetch exception has no previous exception', function (): void {
    app(CarrierRegistry::class)->reset();

    $upsCarrier = Carrier::factory()->ups()->create();
    $adapter = Mockery::mock(DirectCarrierAdapter::class);
    $adapter->shouldReceive('isConfigured')->once()->andReturnTrue();
    $adapter->shouldReceive('prepareRateRequest')->once()->andReturnNull();
    $adapter->shouldReceive('getRates')->once()->andThrow(new CarrierRateFetchException('UPS'));

    app(CarrierRegistry::class)->registerInstance('UPS', $adapter);

    $shippingMethod = ShippingMethod::factory()->create();
    $upsService = CarrierService::factory()->upsGround()->for($upsCarrier)->create();
    $shippingMethod->carrierServices()->attach($upsService->id);

    $shipment = Shipment::factory()->for($shippingMethod)->create(['postal_code' => '90210']);
    $package = Package::factory()->for($shipment)->create([
        'weight' => 2.5,
        'height' => 6,
        'width' => 8,
        'length' => 10,
    ]);

    $rates = app(ShippingRateService::class)->getShippingRates($package->id);

    expect($rates)->toHaveCount(0);
});

it('explains when a carrier cannot rate an unmeasured package', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
    ]);

    $upsAccount = createUpsAccount();
    $shippingMethod = ShippingMethod::factory()->create();
    $upsService = CarrierService::factory()->upsGround()->create([
        'carrier_id' => $upsAccount->carrier_id,
    ]);
    $shippingMethod->carrierServices()->attach($upsService);

    $shipment = Shipment::factory()->for($shippingMethod)->create(['postal_code' => '90210']);
    $package = Package::factory()->for($shipment)->create([
        'box_size_id' => null,
        'weight' => 2.5,
        'height' => null,
        'width' => null,
        'length' => null,
    ]);

    expect(fn (): array => PackageData::fromPackage($package)->dimensionsInWholeInches())
        ->toThrow(InvalidPackageDimensionsException::class);

    $service = app(ShippingRateService::class);
    $rates = $service->getShippingRates($package->id);

    expect($rates)->toBeEmpty()
        ->and($service->getExclusions())->toBe([[
            'carrier' => 'UPS',
            'reason' => 'UPS requires valid package dimensions before rates can be requested.',
        ]]);
});

it('drops rates whose packaging requirement the package does not meet, before they are logged as quotes', function (): void {
    // ADR-0005 decision 4: the shared filter runs on every collection of rates
    // an adapter returns, and a dropped rate was never offered — so it must
    // not appear in the quote log either.
    app(CarrierRegistry::class)->reset();

    $upsCarrier = Carrier::factory()->ups()->create();
    $adapter = Mockery::mock(DirectCarrierAdapter::class);
    $adapter->shouldReceive('isConfigured')->once()->andReturnTrue();
    $adapter->shouldReceive('prepareRateRequest')->once()->andReturnNull();
    $adapter->shouldReceive('getRates')->once()->andReturn(collect([
        new RateResponse(
            carrier: 'UPS',
            serviceCode: 'GROUND',
            serviceName: 'UPS Ground',
            price: 9.10,
            packagingRequirement: PackagingRequirement::shipperPackaging(),
        ),
        new RateResponse(
            carrier: 'UPS',
            serviceCode: 'EXPRESS_BOX',
            serviceName: 'UPS Express (Express Box)',
            price: 24.50,
            packagingRequirement: PackagingRequirement::exactly(CarrierPackaging::UpsExpressBox),
        ),
    ]));

    app(CarrierRegistry::class)->registerInstance('UPS', $adapter);

    $shippingMethod = ShippingMethod::factory()->create();
    $upsService = CarrierService::factory()->upsGround()->for($upsCarrier)->create();
    $shippingMethod->carrierServices()->attach($upsService->id);

    $shipment = Shipment::factory()->for($shippingMethod)->create(['postal_code' => '90210']);
    // A plain package: no box size, so the packer's own packaging.
    $package = Package::factory()->for($shipment)->create([
        'box_size_id' => null,
        'weight' => 2.5,
        'height' => 6,
        'width' => 8,
        'length' => 10,
    ]);

    $rates = app(ShippingRateService::class)->getShippingRates($package->id);

    expect($rates)->toHaveCount(1)
        ->and($rates[0]->serviceCode)->toBe('GROUND')
        ->and(RateQuote::where('package_id', $package->id)->pluck('service_code')->all())->toBe(['GROUND']);
});

it('keeps both the weight-based and One Rate FedEx rates for a Package in a FedEx Pak', function (): void {
    // ADR-0005 decision 3 as amended: FedEx stamps the packaging it sent, so a
    // weight-based rate quoted for a Pak is `exactly(FedexPak)` just like its
    // One Rate variant, and the shared filter keeps both for a Package in one.
    $expressSaver = [
        'serviceType' => 'EXPRESS_SAVER',
        'serviceName' => 'FedEx Express Saver',
        'ratedShipmentDetails' => [['totalNetCharge' => 21.40]],
    ];

    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        FedexRates::class => function (PendingRequest $pendingRequest) use ($expressSaver): MockResponse {
            $isOneRate = in_array('FEDEX_ONE_RATE', data_get($pendingRequest->body()->all(), 'requestedShipment.shipmentSpecialServices.specialServiceTypes', []), true);

            return MockResponse::make(['output' => ['rateReplyDetails' => [
                $isOneRate ? ['ratedShipmentDetails' => [['totalNetCharge' => 14.95]]] + $expressSaver : $expressSaver,
            ]]]);
        },
    ]);

    $shippingMethod = ShippingMethod::factory()->create();
    $expressSaverService = CarrierService::factory()
        ->for($this->fedexCarrier)
        ->create(['name' => 'FedEx Express Saver', 'service_code' => 'EXPRESS_SAVER']);
    $shippingMethod->carrierServices()->attach($expressSaverService);

    $shipment = Shipment::factory()->for($shippingMethod)->create(['postal_code' => '90210']);
    $package = Package::factory()->for($shipment)->create([
        'box_size_id' => BoxSize::factory()->carrierPackaging(CarrierPackaging::FedexPak)->create()->id,
        'weight' => 2.0,
        'height' => 1,
        'width' => 12,
        'length' => 15,
    ]);

    $rates = app(ShippingRateService::class)->getShippingRates($package->id);

    expect($rates->pluck('serviceCode')->all())->toBe(['EXPRESS_SAVER', 'EXPRESS_SAVER'])
        ->and($rates->pluck('price')->all())->toBe([21.40, 14.95])
        ->and($rates->map(fn ($rate): bool => (bool) ($rate->metadata['isOneRate'] ?? false))->all())->toBe([false, true]);

    foreach ($rates as $rate) {
        expect($rate->packagingRequirement->accepts(CarrierPackaging::FedexPak))->toBeTrue();
    }
});

it('offers only the medium-flat-rate-box rate for a Package in a USPS Medium Flat Rate Box', function (): void {
    // ADR-0005 decision 3, the USPS half: the adapter now keeps the flat-rate
    // indicators as exactly(…) requirements, and the shared filter — not the
    // adapter — matches them to the Package's declared packaging.
    $rate = fn (string $indicator, string $category, float $price, string $description): array => [
        'totalBasePrice' => $price,
        'commitment' => ['name' => '1-3 Business Days', 'scheduleDeliveryDate' => '2025-01-15'],
        'rates' => [[
            'mailClass' => 'PRIORITY_MAIL',
            'processingCategory' => $category,
            'rateIndicator' => $indicator,
            'destinationEntryFacilityType' => 'NONE',
            'description' => $description,
        ]],
    ];

    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ShippingOptions::class => MockResponse::make([
            'pricingOptions' => [[
                'shippingOptions' => [[
                    'rateOptions' => [
                        $rate('SP', 'MACHINABLE', 15.22, 'Priority Mail Machinable Single-piece'),
                        $rate('CP', 'MACHINABLE', 15.51, 'Priority Mail Machinable Cubic Non-Soft Pack Tier 1'),
                        $rate('FE', 'FLATS', 11.12, 'Priority Mail Flat Rate Envelope'),
                        $rate('FB', 'MACHINABLE', 21.17, 'Priority Mail Machinable Medium Flat Rate Box'),
                        $rate('PL', 'MACHINABLE', 31.00, 'Priority Mail Machinable Large Flat Rate Box'),
                    ],
                ]],
            ]],
        ]),
    ]);

    $shippingMethod = ShippingMethod::factory()->create();
    $priority = CarrierService::factory()->uspsPriority()->for($this->uspsCarrier)->create();
    $shippingMethod->carrierServices()->attach($priority);

    $shipment = Shipment::factory()->for($shippingMethod)->create(['postal_code' => '10001']);
    $package = Package::factory()->for($shipment)->create([
        'box_size_id' => BoxSize::factory()->carrierPackaging(CarrierPackaging::UspsMediumFlatRateBox)->create()->id,
        'weight' => 2.0,
        'height' => 5.5,
        'width' => 8.5,
        'length' => 11,
    ]);

    $rates = app(ShippingRateService::class)->getShippingRates($package->id);

    expect($rates)->toHaveCount(1)
        ->and($rates[0]->metadata['rateIndicator'])->toBe('FB')
        ->and($rates[0]->price)->toBe(21.17)
        ->and($rates[0]->packagingRequirement->accepts(CarrierPackaging::UspsMediumFlatRateBox))->toBeTrue()
        ->and(RateQuote::where('package_id', $package->id)->count())->toBe(1);
});

it('offers only UPS rates for a Package in a UPS Pak', function (): void {
    // ADR-0005 decision 3, the UPS half: the adapter sends the Pak's code on
    // the rate request and stamps every rate `exactly(UpsPak)`; USPS and FedEx
    // rate the same parcel as the shipper's packaging, which the shared filter
    // drops for a Package that says it is in UPS packaging.
    $upsCarrier = Carrier::factory()->ups()->create();
    createUpsAccount();

    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ShippingOptions::class => fakeUspsGroundAdvantageRate(8.50),
        FedexRates::class => MockResponse::make(['output' => ['rateReplyDetails' => [[
            'serviceType' => 'FEDEX_GROUND',
            'serviceName' => 'FedEx Ground',
            'ratedShipmentDetails' => [['totalNetCharge' => 11.50]],
        ]]]]),
        UpsRate::class => MockResponse::make(['RateResponse' => ['RatedShipment' => [
            ['Service' => ['Code' => '03'], 'TotalCharges' => ['MonetaryValue' => '12.40']],
            ['Service' => ['Code' => '01'], 'TotalCharges' => ['MonetaryValue' => '48.10']],
        ]]]),
    ]);

    $shippingMethod = ShippingMethod::factory()->create();
    $shippingMethod->carrierServices()->attach([
        CarrierService::factory()->uspsGroundAdvantage()->for($this->uspsCarrier)->create()->id,
        CarrierService::factory()->fedexGround()->for($this->fedexCarrier)->create()->id,
        CarrierService::factory()->upsGround()->for($upsCarrier)->create()->id,
        CarrierService::factory()->upsNextDay()->for($upsCarrier)->create()->id,
    ]);

    $shipment = Shipment::factory()->for($shippingMethod)->create(['postal_code' => '90210']);
    $package = Package::factory()->for($shipment)->create([
        'box_size_id' => BoxSize::factory()->carrierPackaging(CarrierPackaging::UpsPak)->create()->id,
        'weight' => 2.0,
        'height' => 1,
        'width' => 12,
        'length' => 15,
    ]);

    $rates = app(ShippingRateService::class)->getShippingRates($package->id);

    expect($rates->pluck('carrier')->unique()->all())->toBe(['UPS'])
        ->and($rates->pluck('serviceCode')->sort()->values()->all())->toBe(['01', '03'])
        ->and($rates->pluck('metadata.packagingCode')->unique()->all())->toBe(['04'])
        ->and($rates->every(fn (RateResponse $rate): bool => $rate->packagingRequirement->accepts(CarrierPackaging::UpsPak)))->toBeTrue()
        ->and(RateQuote::where('package_id', $package->id)->pluck('carrier')->unique()->all())->toBe(['UPS']);

    Saloon::assertSent(function ($request): bool {
        return $request instanceof UpsRate
            && $request->body()->all()['RateRequest']['Shipment']['Package']['PackagingType']['Code'] === '04';
    });
    Saloon::assertSent(ShippingOptions::class);
    Saloon::assertSent(FedexRates::class);
});

/*
|--------------------------------------------------------------------------
| Special service capability + carrier-service scope filtering
|--------------------------------------------------------------------------
*/

function createScopedSpecialService(string $code, string $name, bool $active = true): SpecialService
{
    return SpecialService::create([
        'code' => $code,
        'name' => $name,
        'scope' => 'shipment',
        'category' => 'delivery',
        'requires_value' => false,
        'active' => $active,
    ]);
}

function fakeUspsGroundAdvantageRate(float $price = 8.50): MockResponse
{
    return MockResponse::make([
        'pricingOptions' => [
            [
                'shippingOptions' => [
                    [
                        'rateOptions' => [
                            [
                                'totalBasePrice' => $price,
                                'commitment' => ['name' => '2-5 Business Days'],
                                'rates' => [
                                    [
                                        'mailClass' => 'USPS_GROUND_ADVANTAGE',
                                        'processingCategory' => 'MACHINABLE',
                                        'rateIndicator' => 'SP',
                                        'destinationEntryFacilityType' => 'NONE',
                                        'description' => 'USPS Ground Advantage',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);
}

it('drops carrier services not scoped for a required special service', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        FedexRates::class => MockResponse::make([
            'output' => [
                'rateReplyDetails' => [
                    [
                        'serviceType' => 'FEDEX_GROUND',
                        'serviceName' => 'FedEx Ground',
                        'ratedShipmentDetails' => [['totalNetCharge' => 11.50]],
                        'commit' => ['transitDays' => 'THREE_DAYS'],
                    ],
                    [
                        'serviceType' => 'PRIORITY_OVERNIGHT',
                        'serviceName' => 'FedEx Priority Overnight',
                        'ratedShipmentDetails' => [['totalNetCharge' => 42.00]],
                        'commit' => ['transitDays' => 'ONE_DAY'],
                    ],
                ],
            ],
        ]),
    ]);

    $saturday = createScopedSpecialService('saturday_delivery', 'Saturday Delivery');

    $shippingMethod = ShippingMethod::factory()->create();
    $shippingMethod->specialServices()->attach($saturday->id, ['mode' => 'required']);

    $ground = CarrierService::factory()->fedexGround()->for($this->fedexCarrier)->create();
    $overnight = CarrierService::factory()->for($this->fedexCarrier)->create([
        'name' => 'FedEx Priority Overnight',
        'service_code' => 'PRIORITY_OVERNIGHT',
    ]);
    $shippingMethod->carrierServices()->attach([$ground->id, $overnight->id]);

    // Only Priority Overnight is scoped as Saturday-capable
    $saturday->carrierServices()->attach($overnight->id);

    $shipment = Shipment::factory()->for($shippingMethod)->create(['postal_code' => '90210']);
    $package = Package::factory()->for($shipment)->create();

    $service = app(ShippingRateService::class);
    $rates = $service->getShippingRates($package->id);

    expect($rates)->toHaveCount(1)
        ->and($rates[0]->metadata['serviceType'])->toBe('PRIORITY_OVERNIGHT')
        ->and($service->getExclusions())->toBeEmpty();
});

it('excludes a carrier when no services survive a required special service scope', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ShippingOptions::class => fakeUspsGroundAdvantageRate(),
    ]);

    $saturday = createScopedSpecialService('saturday_delivery', 'Saturday Delivery');

    $shippingMethod = ShippingMethod::factory()->create();
    $shippingMethod->specialServices()->attach($saturday->id, ['mode' => 'required']);

    $fedexGround = CarrierService::factory()->fedexGround()->for($this->fedexCarrier)->create();
    $uspsService = CarrierService::factory()->uspsGroundAdvantage()->for($this->uspsCarrier)->create();
    $shippingMethod->carrierServices()->attach([$fedexGround->id, $uspsService->id]);

    // Saturday is scoped for FedEx, but only on a service this method doesn't use
    $overnight = CarrierService::factory()->for($this->fedexCarrier)->create([
        'name' => 'FedEx Priority Overnight',
        'service_code' => 'PRIORITY_OVERNIGHT',
    ]);
    $saturday->carrierServices()->attach($overnight->id);

    $shipment = Shipment::factory()->for($shippingMethod)->create(['postal_code' => '90210']);
    $package = Package::factory()->for($shipment)->create();

    $service = app(ShippingRateService::class);
    $rates = $service->getShippingRates($package->id);

    // USPS is unscoped for Saturday (no rows for any USPS service) and unaffected
    expect($rates)->toHaveCount(1)
        ->and($rates[0]->carrier)->toBe('USPS')
        ->and($service->getExclusions())->toHaveCount(1)
        ->and($service->getExclusions()[0]['carrier'])->toBe('FedEx')
        ->and($service->getExclusions()[0]['reason'])->toContain('Saturday Delivery');
});

it('strips a default-mode special service instead of excluding the carrier', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        FedexRates::class => MockResponse::make([
            'output' => [
                'rateReplyDetails' => [
                    [
                        'serviceType' => 'FEDEX_GROUND',
                        'serviceName' => 'FedEx Ground',
                        'ratedShipmentDetails' => [['totalNetCharge' => 11.50]],
                        'commit' => ['transitDays' => 'THREE_DAYS'],
                    ],
                ],
            ],
        ]),
    ]);

    $saturday = createScopedSpecialService('saturday_delivery', 'Saturday Delivery');

    $shippingMethod = ShippingMethod::factory()->create();
    $shippingMethod->specialServices()->attach($saturday->id, ['mode' => 'default']);

    $fedexGround = CarrierService::factory()->fedexGround()->for($this->fedexCarrier)->create();
    $shippingMethod->carrierServices()->attach($fedexGround->id);

    // Saturday scoped for FedEx but not on Ground — default mode strips the
    // code rather than dropping the carrier service
    $overnight = CarrierService::factory()->for($this->fedexCarrier)->create([
        'name' => 'FedEx Priority Overnight',
        'service_code' => 'PRIORITY_OVERNIGHT',
    ]);
    $saturday->carrierServices()->attach($overnight->id);

    $shipment = Shipment::factory()->for($shippingMethod)->create(['postal_code' => '90210']);
    $package = Package::factory()->for($shipment)->create();

    $service = app(ShippingRateService::class);
    $rates = $service->getShippingRates($package->id);

    expect($rates)->toHaveCount(1)
        ->and($rates[0]->carrier)->toBe('FedEx')
        ->and($service->getExclusions())->toBeEmpty();
});

it('excludes a carrier that has not implemented a required special service', function (): void {
    $holdAtLocation = createScopedSpecialService('hold_at_location', 'Hold at Location');

    $shippingMethod = ShippingMethod::factory()->create();
    $shippingMethod->specialServices()->attach($holdAtLocation->id, ['mode' => 'required']);

    $fedexGround = CarrierService::factory()->fedexGround()->for($this->fedexCarrier)->create();
    $shippingMethod->carrierServices()->attach($fedexGround->id);

    $shipment = Shipment::factory()->for($shippingMethod)->create(['postal_code' => '90210']);
    $package = Package::factory()->for($shipment)->create();

    $service = app(ShippingRateService::class);
    $rates = $service->getShippingRates($package->id);

    expect($rates)->toHaveCount(0)
        ->and($service->getExclusions())->toHaveCount(1)
        ->and($service->getExclusions()[0]['carrier'])->toBe('FedEx')
        ->and($service->getExclusions()[0]['reason'])->toContain('Hold at Location');
});

it('respects restricted_countries on a carrier service scope', function (): void {
    $saturday = createScopedSpecialService('saturday_delivery', 'Saturday Delivery');

    $shippingMethod = ShippingMethod::factory()->create();
    $shippingMethod->specialServices()->attach($saturday->id, ['mode' => 'required']);

    $overnight = CarrierService::factory()->for($this->fedexCarrier)->create([
        'name' => 'FedEx Priority Overnight',
        'service_code' => 'PRIORITY_OVERNIGHT',
    ]);
    $shippingMethod->carrierServices()->attach($overnight->id);

    // Scoped, but only for Canadian destinations — US shipment must exclude FedEx
    $saturday->carrierServices()->attach($overnight->id, ['restricted_countries' => ['CA']]);

    $shipment = Shipment::factory()->for($shippingMethod)->create(['postal_code' => '90210']);
    $package = Package::factory()->for($shipment)->create();

    $service = app(ShippingRateService::class);
    $rates = $service->getShippingRates($package->id);

    expect($rates)->toHaveCount(0)
        ->and($service->getExclusions())->toHaveCount(1)
        ->and($service->getExclusions()[0]['reason'])->toContain('for this destination');
});

it('excludes carriers that cannot carry an active product compliance service', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        FedexRates::class => MockResponse::make(['output' => ['rateReplyDetails' => []]]),
    ]);

    createScopedSpecialService('alcohol', 'Alcohol');

    $shippingMethod = ShippingMethod::factory()->create();
    $uspsService = CarrierService::factory()->uspsGroundAdvantage()->for($this->uspsCarrier)->create();
    $fedexGround = CarrierService::factory()->fedexGround()->for($this->fedexCarrier)->create();
    $shippingMethod->carrierServices()->attach([$uspsService->id, $fedexGround->id]);

    $shipment = Shipment::factory()->for($shippingMethod)->create(['postal_code' => '90210']);
    $package = Package::factory()->for($shipment)->create();
    $product = Product::factory()->create(['contains_alcohol' => true]);
    PackageItem::factory()->for($package)->create(['product_id' => $product->id]);

    $service = app(ShippingRateService::class);
    $rates = $service->getShippingRates($package->id);

    // USPS prohibits alcohol outright; FedEx supports it and proceeds to rate
    // shopping (the fake returns no rates)
    expect($rates)->toHaveCount(0)
        ->and($service->getExclusions())->toHaveCount(1)
        ->and($service->getExclusions()[0]['carrier'])->toBe('USPS');
});

it('excludes a carrier when the declared value exceeds its cap', function (): void {
    $declaredValue = createScopedSpecialService('declared_value', 'Declared Value');

    $shippingMethod = ShippingMethod::factory()->create();
    $shippingMethod->specialServices()->attach($declaredValue->id, ['mode' => 'required']);
    $fedexGround = CarrierService::factory()->fedexGround()->for($this->fedexCarrier)->create();
    $shippingMethod->carrierServices()->attach($fedexGround->id);

    $shipment = Shipment::factory()->for($shippingMethod)->create([
        'postal_code' => '90210',
        'value' => 60000.00,
    ]);
    $package = Package::factory()->for($shipment)->create();

    $service = app(ShippingRateService::class);
    $rates = $service->getShippingRates($package->id);

    // FedEx caps declared value at $50,000 — excluded visibly, never clamped
    expect($rates)->toHaveCount(0)
        ->and($service->getExclusions())->toHaveCount(1)
        ->and($service->getExclusions()[0]['carrier'])->toBe('FedEx')
        ->and($service->getExclusions()[0]['reason'])->toContain('maximum is $50,000');
});

it('throws when a required declared value cannot be derived', function (): void {
    $declaredValue = createScopedSpecialService('declared_value', 'Declared Value');

    $shippingMethod = ShippingMethod::factory()->create();
    $shippingMethod->specialServices()->attach($declaredValue->id, ['mode' => 'required']);
    $fedexGround = CarrierService::factory()->fedexGround()->for($this->fedexCarrier)->create();
    $shippingMethod->carrierServices()->attach($fedexGround->id);

    $shipment = Shipment::factory()->for($shippingMethod)->create([
        'postal_code' => '90210',
        'value' => null,
    ]);
    $package = Package::factory()->for($shipment)->create();

    app(ShippingRateService::class)->getShippingRates($package->id);
})->throws(MissingDeclaredValueException::class);

it('drops signature_required when adult signature is also required instead of excluding the carrier', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ShippingOptions::class => fakeUspsGroundAdvantageRate(),
    ]);

    $signature = createScopedSpecialService('signature_required', 'Signature Required');
    $adult = createScopedSpecialService('adult_signature_required', 'Adult Signature Required');

    $shippingMethod = ShippingMethod::factory()->create();
    $shippingMethod->specialServices()->attach($signature->id, ['mode' => 'required']);
    $shippingMethod->specialServices()->attach($adult->id, ['mode' => 'required']);

    $uspsService = CarrierService::factory()->uspsGroundAdvantage()->for($this->uspsCarrier)->create();
    $shippingMethod->carrierServices()->attach($uspsService->id);

    // signature_required is scoped away from USPS entirely (rows exist only for
    // a FedEx service) — without the supersede rule USPS would be excluded even
    // though adult signature covers the requirement.
    $fedexGround = CarrierService::factory()->fedexGround()->for($this->fedexCarrier)->create();
    $signature->carrierServices()->attach($fedexGround->id);

    $shipment = Shipment::factory()->for($shippingMethod)->create(['postal_code' => '90210']);
    $package = Package::factory()->for($shipment)->create();

    $service = app(ShippingRateService::class);
    $rates = $service->getShippingRates($package->id);

    expect($rates)->toHaveCount(1)
        ->and($rates[0]->carrier)->toBe('USPS')
        ->and($service->getExclusions())->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| PO Box / military address carrier-service filtering
|--------------------------------------------------------------------------
*/

it('excludes carrier services that cannot ship to PO Boxes', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ShippingOptions::class => fakeUspsGroundAdvantageRate(),
    ]);

    $shippingMethod = ShippingMethod::factory()->create();

    $uspsService = CarrierService::factory()
        ->uspsGroundAdvantage()
        ->for($this->uspsCarrier)
        ->create(['can_ship_to_po_boxes' => true]);

    $fedexService = CarrierService::factory()
        ->fedexGround()
        ->for($this->fedexCarrier)
        ->create(['can_ship_to_po_boxes' => false]);

    $shippingMethod->carrierServices()->attach([$uspsService->id, $fedexService->id]);

    $shipment = Shipment::factory()
        ->for($shippingMethod)
        ->create(['address1' => 'PO Box 456', 'postal_code' => '90210']);
    $package = Package::factory()->for($shipment)->create();

    $rates = app(ShippingRateService::class)->getShippingRates($package->id);

    expect($rates)->toHaveCount(1)
        ->and($rates[0]->carrier)->toBe('USPS');

    Saloon::assertNotSent(FedexRates::class);
});

it('excludes carrier services that cannot ship to military addresses', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ShippingOptions::class => fakeUspsGroundAdvantageRate(),
    ]);

    $shippingMethod = ShippingMethod::factory()->create();

    $uspsService = CarrierService::factory()
        ->uspsGroundAdvantage()
        ->for($this->uspsCarrier)
        ->create(['can_ship_to_military_addresses' => true]);

    $fedexService = CarrierService::factory()
        ->fedexGround()
        ->for($this->fedexCarrier)
        ->create(['can_ship_to_military_addresses' => false]);

    $shippingMethod->carrierServices()->attach([$uspsService->id, $fedexService->id]);

    $shipment = Shipment::factory()
        ->for($shippingMethod)
        ->create(['city' => 'APO', 'state_or_province' => 'AE', 'postal_code' => '09143']);
    $package = Package::factory()->for($shipment)->create();

    $rates = app(ShippingRateService::class)->getShippingRates($package->id);

    expect($rates)->toHaveCount(1)
        ->and($rates[0]->carrier)->toBe('USPS');

    Saloon::assertNotSent(FedexRates::class);
});

it('throws when no carrier services can ship to a PO Box destination', function (): void {
    $shippingMethod = ShippingMethod::factory()->create(['name' => 'Ground Only']);

    $fedexService = CarrierService::factory()
        ->fedexGround()
        ->for($this->fedexCarrier)
        ->create(['can_ship_to_po_boxes' => false]);

    $shippingMethod->carrierServices()->attach($fedexService->id);

    $shipment = Shipment::factory()
        ->for($shippingMethod)
        ->create(['address1' => 'PO Box 789']);
    $package = Package::factory()->for($shipment)->create();

    expect(fn () => app(ShippingRateService::class)->getShippingRates($package->id))
        ->toThrow(NoActiveCarrierServicesException::class, "No active carrier services available for shipping method 'Ground Only'");
});

it('excludes carrier services that cannot ship to PO Boxes when no shipping method is assigned', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ShippingOptions::class => fakeUspsGroundAdvantageRate(),
        FedexRates::class => MockResponse::make([
            'output' => [
                'rateReplyDetails' => [
                    [
                        'serviceType' => 'FEDEX_GROUND',
                        'serviceName' => 'FedEx Ground',
                        'ratedShipmentDetails' => [['totalNetCharge' => 11.50]],
                        'commit' => ['transitDays' => 'THREE_DAYS'],
                    ],
                ],
            ],
        ]),
    ]);

    Setting::updateOrCreate(['key' => 'usps.client_id'], ['value' => 'test', 'type' => 'string']);
    Setting::updateOrCreate(['key' => 'usps.client_secret'], ['value' => 'test', 'type' => 'string']);
    Setting::updateOrCreate(['key' => 'usps.crid'], ['value' => 'test', 'type' => 'string']);
    Setting::updateOrCreate(['key' => 'fedex.api_key'], ['value' => 'test', 'type' => 'string']);
    Setting::updateOrCreate(['key' => 'fedex.api_secret'], ['value' => 'test', 'type' => 'string']);
    Setting::updateOrCreate(['key' => 'fedex.account_number'], ['value' => 'test', 'type' => 'string']);
    app(SettingsService::class)->clearCache();

    // Cataloged, but not attached to any shipping method -- the fallback path
    // looks these up by carrier name, not via a ShippingMethod relation.
    CarrierService::factory()->uspsGroundAdvantage()->for($this->uspsCarrier)->create(['can_ship_to_po_boxes' => true]);
    CarrierService::factory()->fedexGround()->for($this->fedexCarrier)->create(['can_ship_to_po_boxes' => false]);

    $shipment = Shipment::factory()->create([
        'shipping_method_id' => null,
        'address1' => 'PO Box 456',
        'postal_code' => '90210',
    ]);
    $package = Package::factory()->for($shipment)->create();

    $rates = app(ShippingRateService::class)->getShippingRates($package->id);

    expect($rates)->toHaveCount(1)
        ->and($rates[0]->carrier)->toBe('USPS');

    Saloon::assertNotSent(FedexRates::class);
});

it('excludes carrier services that cannot ship to military addresses when no shipping method is assigned', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ShippingOptions::class => fakeUspsGroundAdvantageRate(),
        FedexRates::class => MockResponse::make([
            'output' => [
                'rateReplyDetails' => [
                    [
                        'serviceType' => 'FEDEX_GROUND',
                        'serviceName' => 'FedEx Ground',
                        'ratedShipmentDetails' => [['totalNetCharge' => 11.50]],
                        'commit' => ['transitDays' => 'THREE_DAYS'],
                    ],
                ],
            ],
        ]),
    ]);

    Setting::updateOrCreate(['key' => 'usps.client_id'], ['value' => 'test', 'type' => 'string']);
    Setting::updateOrCreate(['key' => 'usps.client_secret'], ['value' => 'test', 'type' => 'string']);
    Setting::updateOrCreate(['key' => 'usps.crid'], ['value' => 'test', 'type' => 'string']);
    Setting::updateOrCreate(['key' => 'fedex.api_key'], ['value' => 'test', 'type' => 'string']);
    Setting::updateOrCreate(['key' => 'fedex.api_secret'], ['value' => 'test', 'type' => 'string']);
    Setting::updateOrCreate(['key' => 'fedex.account_number'], ['value' => 'test', 'type' => 'string']);
    app(SettingsService::class)->clearCache();

    CarrierService::factory()->uspsGroundAdvantage()->for($this->uspsCarrier)->create(['can_ship_to_military_addresses' => true]);
    CarrierService::factory()->fedexGround()->for($this->fedexCarrier)->create(['can_ship_to_military_addresses' => false]);

    // This is the exact real-world failure that motivated this fix: UPS (and
    // FedEx Ground) reject a military "AE" state outright rather than simply
    // returning no rate.
    $shipment = Shipment::factory()->create([
        'shipping_method_id' => null,
        'city' => 'APO',
        'state_or_province' => 'AE',
        'postal_code' => '09143',
    ]);
    $package = Package::factory()->for($shipment)->create();

    $rates = app(ShippingRateService::class)->getShippingRates($package->id);

    expect($rates)->toHaveCount(1)
        ->and($rates[0]->carrier)->toBe('USPS');

    Saloon::assertNotSent(FedexRates::class);
});

it('skips a carrier with no cataloged service at all for a PO Box destination in the fallback path', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ShippingOptions::class => fakeUspsGroundAdvantageRate(),
    ]);

    Setting::updateOrCreate(['key' => 'usps.client_id'], ['value' => 'test', 'type' => 'string']);
    Setting::updateOrCreate(['key' => 'usps.client_secret'], ['value' => 'test', 'type' => 'string']);
    Setting::updateOrCreate(['key' => 'usps.crid'], ['value' => 'test', 'type' => 'string']);
    Setting::updateOrCreate(['key' => 'fedex.api_key'], ['value' => 'test', 'type' => 'string']);
    Setting::updateOrCreate(['key' => 'fedex.api_secret'], ['value' => 'test', 'type' => 'string']);
    Setting::updateOrCreate(['key' => 'fedex.account_number'], ['value' => 'test', 'type' => 'string']);
    app(SettingsService::class)->clearCache();

    // USPS is cataloged and capable; FedEx has no catalog rows at all, so
    // there's nothing to confirm it can reach this destination.
    CarrierService::factory()->uspsGroundAdvantage()->for($this->uspsCarrier)->create(['can_ship_to_po_boxes' => true]);

    $shipment = Shipment::factory()->create([
        'shipping_method_id' => null,
        'address1' => 'PO Box 789',
        'postal_code' => '90210',
    ]);
    $package = Package::factory()->for($shipment)->create();

    $rates = app(ShippingRateService::class)->getShippingRates($package->id);

    expect($rates)->toHaveCount(1)
        ->and($rates[0]->carrier)->toBe('USPS');

    Saloon::assertNotSent(FedexRates::class);
});

it('does not restrict carrier services for an ordinary street address', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ShippingOptions::class => fakeUspsGroundAdvantageRate(),
        FedexRates::class => MockResponse::make([
            'output' => [
                'rateReplyDetails' => [
                    [
                        'serviceType' => 'FEDEX_GROUND',
                        'serviceName' => 'FedEx Ground',
                        'ratedShipmentDetails' => [['totalNetCharge' => 11.50]],
                        'commit' => ['transitDays' => 'THREE_DAYS'],
                    ],
                ],
            ],
        ]),
    ]);

    $shippingMethod = ShippingMethod::factory()->create();

    $uspsService = CarrierService::factory()->uspsGroundAdvantage()->for($this->uspsCarrier)->create();
    $fedexService = CarrierService::factory()->fedexGround()->for($this->fedexCarrier)
        ->create(['can_ship_to_po_boxes' => false, 'can_ship_to_military_addresses' => false]);

    $shippingMethod->carrierServices()->attach([$uspsService->id, $fedexService->id]);

    $shipment = Shipment::factory()
        ->for($shippingMethod)
        ->create(['address1' => '742 Evergreen Terrace', 'postal_code' => '90210']);
    $package = Package::factory()->for($shipment)->create();

    $rates = app(ShippingRateService::class)->getShippingRates($package->id);

    expect($rates)->toHaveCount(2);
});

it('ignores product compliance flags while their special service is inactive', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ShippingOptions::class => fakeUspsGroundAdvantageRate(),
    ]);

    createScopedSpecialService('alcohol', 'Alcohol', active: false);

    $shippingMethod = ShippingMethod::factory()->create();
    $uspsService = CarrierService::factory()->uspsGroundAdvantage()->for($this->uspsCarrier)->create();
    $shippingMethod->carrierServices()->attach($uspsService->id);

    $shipment = Shipment::factory()->for($shippingMethod)->create(['postal_code' => '90210']);
    $package = Package::factory()->for($shipment)->create();
    $product = Product::factory()->create(['contains_alcohol' => true]);
    PackageItem::factory()->for($package)->create(['product_id' => $product->id]);

    $service = app(ShippingRateService::class);
    $rates = $service->getShippingRates($package->id);

    // Inactive compliance service = not enforced yet; package ships as before
    expect($rates)->toHaveCount(1)
        ->and($rates[0]->carrier)->toBe('USPS')
        ->and($service->getExclusions())->toBeEmpty();
});

/**
 * ADR-0002 decision 8: hard-required special services are judged at the offer,
 * not purely as carrier policy. Shopify is the case that forces the move — it
 * picks the carrier and the rate after the purchase, so there is no carrier to
 * ask at quote time and no promise it can keep.
 */
it('excludes a Shopify offer, visibly, when the shipment hard-requires a special service', function (): void {
    createShopifyDataSource([], ['oauth_access_token' => 'shpat_test_token']);

    $signature = createScopedSpecialService('signature_required', 'Signature Required');

    $shippingMethod = ShippingMethod::factory()->create();
    $shippingMethod->specialServices()->attach($signature->id, ['mode' => 'required']);

    $shopifyCarrier = Carrier::factory()->shopify()->create();
    $shopifyService = CarrierService::factory()->for($shopifyCarrier)->create([
        'name' => 'USPS Ground Advantage',
        'service_code' => 'usps:usps_ground_advantage',
    ]);
    $shippingMethod->carrierServices()->attach($shopifyService->id);

    $shipment = Shipment::factory()->for($shippingMethod)->create([
        'postal_code' => '90210',
        'metadata' => ['shopify_fulfillment_order_id' => 'gid://shopify/FulfillmentOrder/12345'],
    ]);
    $package = Package::factory()->for($shipment)->create();

    $service = app(ShippingRateService::class);
    $rates = $service->getShippingRates($package->id);

    // "Does not support" would send an operator looking for a carrier setting to
    // change. The reason has to say that nobody has picked a carrier yet.
    expect($rates)->toBeEmpty()
        ->and($service->getExclusions())->toHaveCount(1)
        ->and($service->getExclusions()[0]['carrier'])->toBe('Shopify')
        ->and($service->getExclusions()[0]['reason'])->toContain('cannot guarantee Signature Required')
        ->and($service->getExclusions()[0]['reason'])->toContain('after the label is bought');
});

it('keeps a Shopify offer when the special service is only a default', function (): void {
    $source = createShopifyDataSource([], ['oauth_access_token' => 'shpat_test_token']);
    allowBlindPurchase();

    $signature = createScopedSpecialService('signature_required', 'Signature Required');

    $shippingMethod = ShippingMethod::factory()->create();
    $shippingMethod->specialServices()->attach($signature->id, ['mode' => 'default']);

    $shopifyCarrier = Carrier::factory()->shopify()->create();
    $shopifyService = CarrierService::factory()->for($shopifyCarrier)->create([
        'name' => 'USPS Ground Advantage',
        'service_code' => 'usps:usps_ground_advantage',
    ]);
    $shippingMethod->carrierServices()->attach($shopifyService->id);

    // Shopify only offers on a shipment it can buy against: its own data source
    // plus the fulfillment order the purchase is keyed to.
    $shipment = Shipment::factory()->for($shippingMethod)->create([
        'postal_code' => '90210',
        'data_source_id' => $source->id,
        'metadata' => ['shopify_fulfillment_order_id' => 'gid://shopify/FulfillmentOrder/12345'],
    ]);
    $package = Package::factory()->for($shipment)->create();

    $service = app(ShippingRateService::class);
    $rates = $service->getShippingRates($package->id);

    // A default is a preference. An offer that cannot express it goes without,
    // the same as a code we simply have not wired up. It comes back as a blind
    // purchase and never as a rate, so the rate list stays empty.
    expect($rates)->toBeEmpty()
        ->and($service->getBlindPurchaseOffers())->toHaveCount(1)
        ->and($service->getBlindPurchaseOffers()[0]->source)->toBe('Shopify')
        ->and($service->getBlindPurchaseOffers()[0]->serviceCode)->toBe('usps:usps_ground_advantage')
        ->and($service->getExclusions())->toBeEmpty();
});

it('answers the offer seam from carrier policy for a carrier we buy from directly', function (): void {
    // The direct-carrier view is the one that holds carrier policy at all —
    // ->get() hands back the offer seam, which by design cannot answer this.
    $adapter = app(CarrierRegistry::class)->directAdapterFor('USPS');

    expect($adapter)->not->toBeNull()
        ->and($adapter->offerCapability('saturday_delivery'))
        ->toBe($adapter->serviceCapability('saturday_delivery'))
        ->and($adapter->offerDeclaredValueCap())->toBe($adapter->declaredValueCap());
});

/**
 * A postage source with a real rate API that is not a carrier — the shape
 * Amazon Buy Shipping will take. It quotes asynchronously like USPS or FedEx,
 * but answers nothing about carriers, so it implements `AsyncRateQuoting` and
 * the offer seam and deliberately not `DirectCarrierAdapter`.
 *
 * It exists to hold the concurrent path open for that shape. Gating quoting on
 * `DirectCarrierAdapter` — as an earlier cut of the split did — would send this
 * source down the synchronous branch and silently drop the response nobody
 * parsed, which is invisible in a suite where every async adapter is a carrier.
 */
class AsyncOfferSourceStub implements AsyncRateQuoting, CarrierAdapterInterface
{
    public function __construct(
        private readonly string $carrierName,
        private readonly bool $configured = true,
    ) {}

    public function getCarrierName(): string
    {
        return $this->carrierName;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function offerCapability(string $serviceCode): ServiceCapability
    {
        return ServiceCapability::Supported;
    }

    public function offerDeclaredValueCap(): ?float
    {
        return null;
    }

    /** The marker for the synchronous branch: this rate means the async path was skipped. */
    public function getRates(RateRequest $request, array $serviceCodes): Collection
    {
        return collect([new RateResponse(
            carrier: $this->carrierName,
            serviceCode: 'SYNC_FALLBACK',
            serviceName: 'Synchronous fallback',
            price: 1.00,
        )]);
    }

    public function prepareRateRequest(RateRequest $request, array $serviceCodes): ?PreparedRateRequest
    {
        return new PreparedRateRequest(
            (new StubRateConnector)->createPendingRequest(new StubRateRequest),
            $this->carrierName,
        );
    }

    /** The marker for the async path: only reachable by parsing a sent response. */
    public function parseRateResponse(Response $response, RateRequest $request, array $serviceCodes): Collection
    {
        return collect([new RateResponse(
            carrier: $this->carrierName,
            serviceCode: 'ASYNC_PARSED',
            serviceName: 'Parsed from the concurrent send',
            price: (float) ($response->json('price') ?? 0),
        )]);
    }

    public function createShipment(ShipRequest $request): ShipResponse
    {
        return ShipResponse::failure('Not part of this test');
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
}

class StubRateConnector extends Connector
{
    public function resolveBaseUrl(): string
    {
        return 'https://rates.example.test';
    }
}

class StubRateRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/rates';
    }
}

/**
 * Answers every concurrent send with the same canned body. Saloon's own faking
 * cannot be used here: `fetchRatesConcurrently()` treats a faked request as a
 * reason to fall back to synchronous sends, which is the branch under test.
 */
class CannedGuzzleSender extends GuzzleSender
{
    public function sendAsync(PendingRequest $pendingRequest): PromiseInterface
    {
        return Create::promiseFor(Response::fromPsrResponse(
            new Psr7Response(200, ['Content-Type' => 'application/json'], json_encode(['price' => 12.75])),
            $pendingRequest,
            $pendingRequest->createPsrRequest(),
        ));
    }
}

it('parses concurrent rate responses from an async source that is not a carrier', function (): void {
    $registry = app(CarrierRegistry::class);

    // Two configured sources, so the service takes the concurrent path rather
    // than the single-request shortcut. Neither is a DirectCarrierAdapter.
    $registry->registerInstance('USPS', new AsyncOfferSourceStub('USPS'));
    $registry->registerInstance('FedEx', new AsyncOfferSourceStub('FedEx'));
    $registry->registerInstance('UPS', new AsyncOfferSourceStub('UPS', configured: false));
    $registry->registerInstance('Shopify', new AsyncOfferSourceStub('Shopify', configured: false));

    app()->instance(GuzzleSender::class, new CannedGuzzleSender);

    $shipment = Shipment::factory()->create(['shipping_method_id' => null, 'postal_code' => '90210']);
    $package = Package::factory()->for($shipment)->create();

    $rates = app(ShippingRateService::class)->getShippingRates($package->id);

    // Both came back through parseRateResponse. A SYNC_FALLBACK here would mean
    // the source was refused the async path for not being a carrier.
    expect($rates)->toHaveCount(2)
        ->and($rates->pluck('serviceCode')->all())->each->toBe('ASYNC_PARSED')
        ->and($rates->pluck('carrier')->sort()->values()->all())->toBe(['FedEx', 'USPS'])
        ->and($rates[0]->price)->toBe(12.75);
});
