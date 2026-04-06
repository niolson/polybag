<?php

use App\DataTransferObjects\Shipping\PackageData;
use App\DataTransferObjects\Shipping\RateRequest;
use App\Exceptions\NoActiveCarrierServicesException;
use App\Http\Integrations\Fedex\Requests\Rates as FedexRates;
use App\Http\Integrations\USPS\Requests\ShippingOptions;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\Package;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Services\Carriers\UspsAdapter;
use App\Services\SettingsService;
use App\Services\ShippingRateService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

beforeEach(function (): void {
    $this->uspsCarrier = Carrier::factory()->usps()->create();
    $this->fedexCarrier = Carrier::factory()->fedex()->create();
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
    Cache::forget('usps_pricing_type');

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

    expect(Cache::get('usps_pricing_type'))->toBe('RETAIL');
});
