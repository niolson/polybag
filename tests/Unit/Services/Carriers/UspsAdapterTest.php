<?php

use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\PackageData;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\Enums\BoxSizeType;
use App\Enums\TrackingStatus;
use App\Http\Integrations\USPS\Requests\CancelInternationalLabel;
use App\Http\Integrations\USPS\Requests\CancelLabel;
use App\Http\Integrations\USPS\Requests\Label;
use App\Http\Integrations\USPS\Requests\PaymentAuthorization;
use App\Http\Integrations\USPS\Requests\ShippingOptions;
use App\Http\Integrations\USPS\Requests\TrackShipment;
use App\Models\Package;
use App\Models\Setting;
use App\Models\Shipment;
use App\Services\Carriers\UspsAdapter;
use App\Services\SettingsService;
use Saloon\Exceptions\Request\Statuses\InternalServerErrorException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

beforeEach(function (): void {
    $this->adapter = new UspsAdapter;
});

it('returns USPS as carrier name', function (): void {
    expect($this->adapter->getCarrierName())->toBe('USPS');
});

it('does not support multi-package shipments', function (): void {
    expect($this->adapter->supportsMultiPackage())->toBeFalse();
});

it('checks if adapter is configured', function (): void {
    Setting::updateOrCreate(['key' => 'usps.client_id'], ['value' => 'test_client_id', 'type' => 'string']);
    Setting::updateOrCreate(['key' => 'usps.client_secret'], ['value' => 'test_client_secret', 'type' => 'string']);
    Setting::updateOrCreate(['key' => 'usps.crid'], ['value' => 'test_crid', 'type' => 'string']);
    app(SettingsService::class)->clearCache();

    expect($this->adapter->isConfigured())->toBeTrue();
});

it('returns false when not configured', function (): void {
    Setting::whereIn('key', ['usps.client_id', 'usps.client_secret', 'usps.crid'])->delete();
    app(SettingsService::class)->clearCache();

    expect($this->adapter->isConfigured())->toBeFalse();
});

it('supports tracking', function (): void {
    expect($this->adapter->supportsTracking())->toBeTrue();
});

it('fetches rates from USPS API', function (): void {
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

    $request = new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: '90210',
        packages: [new PackageData(weight: 2.5, length: 10, width: 8, height: 6)],
    );

    $rates = $this->adapter->getRates($request, ['USPS_GROUND_ADVANTAGE']);

    expect($rates)->toHaveCount(1);

    $rate = $rates->first();
    expect($rate)->toBeInstanceOf(RateResponse::class)
        ->and($rate->carrier)->toBe('USPS')
        ->and($rate->serviceCode)->toBe('USPS_GROUND_ADVANTAGE')
        ->and($rate->serviceName)->toBe('USPS Ground Advantage')
        ->and($rate->price)->toBe(8.50)
        ->and($rate->deliveryCommitment)->toBe('2-5 Business Days');

    Saloon::assertSent(ShippingOptions::class);
});

it('filters rates by service codes', function (): void {
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
                                    'rates' => [
                                        [
                                            'mailClass' => 'USPS_GROUND_ADVANTAGE',
                                            'processingCategory' => 'MACHINABLE',
                                            'rateIndicator' => 'SP',
                                            'destinationEntryFacilityType' => 'NONE',
                                        ],
                                    ],
                                ],
                                [
                                    'totalBasePrice' => 15.00,
                                    'rates' => [
                                        [
                                            'mailClass' => 'PRIORITY_MAIL',
                                            'processingCategory' => 'MACHINABLE',
                                            'rateIndicator' => 'SP',
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

    $request = new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: '90210',
        packages: [new PackageData(weight: 2.5, length: 10, width: 8, height: 6)],
    );

    // Only request PRIORITY_MAIL
    $rates = $this->adapter->getRates($request, ['PRIORITY_MAIL']);

    expect($rates)->toHaveCount(1)
        ->and($rates->first()->serviceCode)->toBe('PRIORITY_MAIL');
});

it('filters out invalid processing categories', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ShippingOptions::class => MockResponse::make([
            'pricingOptions' => [
                [
                    'shippingOptions' => [
                        [
                            'rateOptions' => [
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
                                [
                                    'totalBasePrice' => 8.50,
                                    'rates' => [
                                        [
                                            'mailClass' => 'USPS_GROUND_ADVANTAGE',
                                            'processingCategory' => 'MACHINABLE',
                                            'rateIndicator' => 'SP',
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

    $request = new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: '90210',
        packages: [new PackageData(weight: 2.5, length: 10, width: 8, height: 6)],
    );

    $rates = $this->adapter->getRates($request, ['USPS_GROUND_ADVANTAGE']);

    // LETTERS should be filtered out
    expect($rates)->toHaveCount(1)
        ->and($rates->first()->price)->toBe(8.50);
});

it('cancels a domestic label', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        PaymentAuthorization::class => MockResponse::make(['paymentAuthorizationToken' => 'test_payment_token']),
        CancelLabel::class => MockResponse::make([], 200),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);
    $package = Package::factory()->shipped()->for($shipment)->create([
        'carrier' => 'USPS',
        'tracking_number' => '9400111899223456789012',
    ]);

    $response = $this->adapter->cancelShipment('9400111899223456789012', $package);

    expect($response->success)->toBeTrue()
        ->and($response->message)->toBe('Label voided successfully.');

    Saloon::assertSent(CancelLabel::class);
});

it('cancels an international label', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        PaymentAuthorization::class => MockResponse::make(['paymentAuthorizationToken' => 'test_payment_token']),
        CancelInternationalLabel::class => MockResponse::make([], 200),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'CA']);
    $package = Package::factory()->shipped()->for($shipment)->create([
        'carrier' => 'USPS',
        'tracking_number' => 'LZ999999999US',
    ]);

    $response = $this->adapter->cancelShipment('LZ999999999US', $package);

    expect($response->success)->toBeTrue()
        ->and($response->message)->toBe('Label voided successfully.');

    Saloon::assertSent(CancelInternationalLabel::class);
});

it('returns failure when cancel API errors', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        PaymentAuthorization::class => MockResponse::make(['paymentAuthorizationToken' => 'test_payment_token']),
        CancelLabel::class => MockResponse::make(['error' => 'Not found'], 404),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);
    $package = Package::factory()->shipped()->for($shipment)->create([
        'carrier' => 'USPS',
        'tracking_number' => '9400111899223456789012',
    ]);

    $response = $this->adapter->cancelShipment('9400111899223456789012', $package);

    expect($response->success)->toBeFalse()
        ->and($response->message)->toContain('404');
});

it('maps a USPS tracking response into normalized tracking data', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        TrackShipment::class => MockResponse::make([
            [
                'trackingNumber' => '9400111899223456789012',
                'status' => 'In Transit',
                'statusCategory' => 'Moving Through Network',
                'statusSummary' => 'In Transit to Next Facility',
                'deliveryDateExpectation' => [
                    'predictedDeliveryDate' => '2026-04-10',
                    'predictedDeliveryWindowEndTime' => '18:00:00',
                ],
                'trackingEvents' => [
                    [
                        'eventType' => 'Departed USPS Regional Facility',
                        'eventCode' => '18',
                        'actionCode' => 'IN_TRANSIT',
                        'eventCity' => 'Seattle',
                        'eventState' => 'WA',
                        'eventCountry' => 'US',
                        'GMTTimestamp' => '2026-04-08T12:00:00Z',
                    ],
                ],
            ],
        ]),
    ]);

    $package = Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'tracking_number' => '9400111899223456789012',
    ]);

    $response = $this->adapter->trackShipment($package);

    expect($response->success)->toBeTrue()
        ->and($response->status)->toBe(TrackingStatus::InTransit)
        ->and($response->statusLabel)->toBe('In Transit to Next Facility')
        ->and($response->estimatedDeliveryAt?->format('Y-m-d H:i:s'))->toBe('2026-04-10 18:00:00')
        ->and($response->events)->toHaveCount(1)
        ->and($response->events[0]->description)->toBe('Departed USPS Regional Facility')
        ->and($response->events[0]->location)->toBe('Seattle, WA, US');
});

it('maps USPS delivered responses into delivered tracking status', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        TrackShipment::class => MockResponse::make([
            [
                'trackingNumber' => '9400111899223456789012',
                'status' => 'Delivered',
                'statusCategory' => 'Delivered',
                'statusSummary' => 'Delivered, In/At Mailbox',
                'trackingEvents' => [
                    [
                        'eventType' => 'Delivered, In/At Mailbox',
                        'eventCode' => '01',
                        'actionCode' => 'DELIVERED',
                        'eventCity' => 'Los Angeles',
                        'eventState' => 'CA',
                        'eventCountry' => 'US',
                        'GMTTimestamp' => '2026-04-09T20:30:00Z',
                    ],
                ],
            ],
        ]),
    ]);

    $package = Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'tracking_number' => '9400111899223456789012',
    ]);

    $response = $this->adapter->trackShipment($package);

    expect($response->success)->toBeTrue()
        ->and($response->status)->toBe(TrackingStatus::Delivered)
        ->and($response->deliveredAt?->toIso8601String())->toBe('2026-04-09T20:30:00+00:00');
});

it('maps USPS hold and pickup responses into exception tracking status', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        TrackShipment::class => MockResponse::make([
            [
                'trackingNumber' => '9400111899223456789012',
                'status' => 'Available for Pickup',
                'statusCategory' => 'Hold at Post Office',
                'statusSummary' => 'Available for Pickup',
                'trackingEvents' => [],
            ],
        ]),
    ]);

    $package = Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'tracking_number' => '9400111899223456789012',
    ]);

    $response = $this->adapter->trackShipment($package);

    expect($response->success)->toBeTrue()
        ->and($response->status)->toBe(TrackingStatus::Exception);
});

it('returns failure when USPS tracking API errors', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        TrackShipment::class => MockResponse::make([
            'error' => [
                'message' => 'Tracking number not found',
            ],
        ], 404),
    ]);

    $package = Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'tracking_number' => '9400111899223456789012',
    ]);

    $response = $this->adapter->trackShipment($package);

    expect($response->success)->toBeFalse()
        ->and($response->message)->toBe('Tracking number not found');
});

it('handles non-json USPS tracking errors without crashing', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        TrackShipment::class => MockResponse::make(
            body: '<html><body>Application Error</body></html>',
            status: 500,
            headers: ['Content-Type' => 'text/html']
        ),
    ]);

    $package = Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'tracking_number' => '9400111899223456789012',
    ]);

    $response = $this->adapter->trackShipment($package);

    expect($response->success)->toBeFalse()
        ->and($response->message)->toContain('Response')
        ->and(data_get($response->details, 'raw.body'))->toContain('Application Error');
});

it('returns empty collection when API returns no rates', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ShippingOptions::class => MockResponse::make(['pricingOptions' => []]),
    ]);

    $request = new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: '90210',
        packages: [new PackageData(weight: 2.5, length: 10, width: 8, height: 6)],
    );

    $rates = $this->adapter->getRates($request, ['USPS_GROUND_ADVANTAGE']);

    expect($rates)->toHaveCount(0);
});

it('creates shipment and returns tracking info', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        PaymentAuthorization::class => MockResponse::make(['paymentAuthorizationToken' => 'test_payment_token']),
        Label::class => MockResponse::make(
            body: "--boundary\r\nContent-Type: application/json\r\n\r\n{\"trackingNumber\":\"9400111899223456789012\",\"postage\":8.50}\r\n--boundary\r\nContent-Type: application/pdf\r\n\r\nJVBERi0xLjQKYmFzZTY0bGFiZWxkYXRh\r\n--boundary--",
            headers: ['Content-Type' => 'multipart/mixed; boundary=boundary']
        ),
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

    $packageData = new PackageData(weight: 2.5, length: 10, width: 8, height: 6);

    $selectedRate = new RateResponse(
        carrier: 'USPS',
        serviceCode: 'USPS_GROUND_ADVANTAGE',
        serviceName: 'USPS Ground Advantage',
        price: 8.50,
        metadata: [
            'mailClass' => 'USPS_GROUND_ADVANTAGE',
            'processingCategory' => 'MACHINABLE',
            'rateIndicator' => 'SP',
            'destinationEntryFacilityType' => 'NONE',
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
        ->and($response->trackingNumber)->toBe('9400111899223456789012')
        ->and($response->cost)->toBe(8.50)
        ->and($response->carrier)->toBe('USPS')
        ->and($response->service)->toBe('USPS Ground Advantage')
        ->and($response->labelData)->not->toBeNull();

    Saloon::assertSent(Label::class);
});

it('filters rate indicators for box type to include CP but exclude soft pack indicators', function (): void {
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
                                    'rates' => [
                                        [
                                            'mailClass' => 'USPS_GROUND_ADVANTAGE',
                                            'processingCategory' => 'MACHINABLE',
                                            'rateIndicator' => 'SP',
                                            'destinationEntryFacilityType' => 'NONE',
                                        ],
                                    ],
                                ],
                                [
                                    'totalBasePrice' => 7.00,
                                    'rates' => [
                                        [
                                            'mailClass' => 'USPS_GROUND_ADVANTAGE',
                                            'processingCategory' => 'MACHINABLE',
                                            'rateIndicator' => 'CP',
                                            'destinationEntryFacilityType' => 'NONE',
                                        ],
                                    ],
                                ],
                                [
                                    'totalBasePrice' => 6.00,
                                    'rates' => [
                                        [
                                            'mailClass' => 'USPS_GROUND_ADVANTAGE',
                                            'processingCategory' => 'MACHINABLE',
                                            'rateIndicator' => 'P5',
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

    $request = new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: '90210',
        packages: [new PackageData(weight: 2.5, length: 10, width: 8, height: 6, boxType: BoxSizeType::BOX)],
    );

    $rates = $this->adapter->getRates($request, []);

    // BOX should get SP (universal) and CP (box), but not P5 (soft pack)
    expect($rates)->toHaveCount(2);
    $rateIndicators = $rates->pluck('metadata.rateIndicator')->toArray();
    expect($rateIndicators)->toContain('SP')
        ->toContain('CP')
        ->not->toContain('P5');
});

it('filters rate indicators for polybag to include soft pack indicators but exclude CP', function (): void {
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
                                    'rates' => [
                                        [
                                            'mailClass' => 'USPS_GROUND_ADVANTAGE',
                                            'processingCategory' => 'MACHINABLE',
                                            'rateIndicator' => 'SP',
                                            'destinationEntryFacilityType' => 'NONE',
                                        ],
                                    ],
                                ],
                                [
                                    'totalBasePrice' => 7.00,
                                    'rates' => [
                                        [
                                            'mailClass' => 'USPS_GROUND_ADVANTAGE',
                                            'processingCategory' => 'MACHINABLE',
                                            'rateIndicator' => 'CP',
                                            'destinationEntryFacilityType' => 'NONE',
                                        ],
                                    ],
                                ],
                                [
                                    'totalBasePrice' => 6.00,
                                    'rates' => [
                                        [
                                            'mailClass' => 'USPS_GROUND_ADVANTAGE',
                                            'processingCategory' => 'MACHINABLE',
                                            'rateIndicator' => 'P5',
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

    $request = new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: '90210',
        packages: [new PackageData(weight: 2.5, length: 10, width: 8, height: 6, boxType: BoxSizeType::POLYBAG)],
    );

    $rates = $this->adapter->getRates($request, []);

    // POLYBAG should get SP (universal) and P5 (soft pack), but not CP (box)
    expect($rates)->toHaveCount(2);
    $rateIndicators = $rates->pluck('metadata.rateIndicator')->toArray();
    expect($rateIndicators)->toContain('SP')
        ->toContain('P5')
        ->not->toContain('CP');
});

it('filters rate indicators for padded mailer same as polybag', function (): void {
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
                                    'rates' => [
                                        [
                                            'mailClass' => 'USPS_GROUND_ADVANTAGE',
                                            'processingCategory' => 'MACHINABLE',
                                            'rateIndicator' => 'PA',
                                            'destinationEntryFacilityType' => 'NONE',
                                        ],
                                    ],
                                ],
                                [
                                    'totalBasePrice' => 7.00,
                                    'rates' => [
                                        [
                                            'mailClass' => 'USPS_GROUND_ADVANTAGE',
                                            'processingCategory' => 'MACHINABLE',
                                            'rateIndicator' => 'CP',
                                            'destinationEntryFacilityType' => 'NONE',
                                        ],
                                    ],
                                ],
                                [
                                    'totalBasePrice' => 5.50,
                                    'rates' => [
                                        [
                                            'mailClass' => 'USPS_GROUND_ADVANTAGE',
                                            'processingCategory' => 'MACHINABLE',
                                            'rateIndicator' => 'Q6',
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

    $request = new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: '90210',
        packages: [new PackageData(weight: 2.5, length: 10, width: 8, height: 6, boxType: BoxSizeType::PADDED_MAILER)],
    );

    $rates = $this->adapter->getRates($request, []);

    // PADDED_MAILER should get PA (universal) and Q6 (soft pack), but not CP (box)
    expect($rates)->toHaveCount(2);
    $rateIndicators = $rates->pluck('metadata.rateIndicator')->toArray();
    expect($rateIndicators)->toContain('PA')
        ->toContain('Q6')
        ->not->toContain('CP');
});

it('allows all rate indicators when box type is null for backwards compatibility', function (): void {
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
                                    'rates' => [
                                        [
                                            'mailClass' => 'USPS_GROUND_ADVANTAGE',
                                            'processingCategory' => 'MACHINABLE',
                                            'rateIndicator' => 'SP',
                                            'destinationEntryFacilityType' => 'NONE',
                                        ],
                                    ],
                                ],
                                [
                                    'totalBasePrice' => 7.00,
                                    'rates' => [
                                        [
                                            'mailClass' => 'USPS_GROUND_ADVANTAGE',
                                            'processingCategory' => 'MACHINABLE',
                                            'rateIndicator' => 'CP',
                                            'destinationEntryFacilityType' => 'NONE',
                                        ],
                                    ],
                                ],
                                [
                                    'totalBasePrice' => 6.00,
                                    'rates' => [
                                        [
                                            'mailClass' => 'USPS_GROUND_ADVANTAGE',
                                            'processingCategory' => 'MACHINABLE',
                                            'rateIndicator' => 'P5',
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

    $request = new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: '90210',
        packages: [new PackageData(weight: 2.5, length: 10, width: 8, height: 6, boxType: null)],
    );

    $rates = $this->adapter->getRates($request, []);

    // When box type is null, all valid rate indicators should be included
    expect($rates)->toHaveCount(3);
    $rateIndicators = $rates->pluck('metadata.rateIndicator')->toArray();
    expect($rateIndicators)->toContain('SP')
        ->toContain('CP')
        ->toContain('P5');
});

it('handles API server error by throwing exception', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ShippingOptions::class => MockResponse::make(['error' => 'Internal Server Error'], 500),
    ]);

    $request = new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: '90210',
        packages: [new PackageData(weight: 2.5, length: 10, width: 8, height: 6)],
    );

    // The adapter will throw on 500 errors (retry exhausted)
    expect(fn () => $this->adapter->getRates($request, ['USPS_GROUND_ADVANTAGE']))
        ->toThrow(InternalServerErrorException::class);
});

it('handles malformed API response gracefully', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ShippingOptions::class => MockResponse::make(['unexpectedField' => 'unexpectedValue']),
    ]);

    $request = new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: '90210',
        packages: [new PackageData(weight: 2.5, length: 10, width: 8, height: 6)],
    );

    $rates = $this->adapter->getRates($request, ['USPS_GROUND_ADVANTAGE']);

    // Should return empty collection when response structure is unexpected
    expect($rates)->toHaveCount(0);
});

it('handles international rate request', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ShippingOptions::class => MockResponse::make([
            'pricingOptions' => [
                [
                    'shippingOptions' => [
                        [
                            'rateOptions' => [
                                [
                                    'totalBasePrice' => 45.00,
                                    'rates' => [
                                        [
                                            'mailClass' => 'PRIORITY_MAIL_INTERNATIONAL',
                                            'processingCategory' => 'MACHINABLE',
                                            'rateIndicator' => 'SP',
                                            'destinationEntryFacilityType' => 'NONE',
                                            'description' => 'Priority Mail International',
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

    $request = new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: 'V6B 1A1',
        destinationCountry: 'CA',
        destinationCity: 'Vancouver',
        destinationStateOrProvince: 'BC',
        packages: [new PackageData(weight: 2.5, length: 10, width: 8, height: 6)],
    );

    $rates = $this->adapter->getRates($request, []);

    // International requests should return rates
    expect($rates)->toHaveCount(1)
        ->and($rates->first()->serviceCode)->toBe('PRIORITY_MAIL_INTERNATIONAL');

    // Verify the request was sent with international destination
    Saloon::assertSent(function (ShippingOptions $req) {
        $body = $req->body()->all();

        return isset($body['destinationCountryCode']) && $body['destinationCountryCode'] === 'CA';
    });
});

it('handles residential vs commercial addresses', function (): void {
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
                                    'rates' => [
                                        [
                                            'mailClass' => 'USPS_GROUND_ADVANTAGE',
                                            'processingCategory' => 'MACHINABLE',
                                            'rateIndicator' => 'SP',
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

    // Residential request
    $residentialRequest = new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: '90210',
        residential: true,
        packages: [new PackageData(weight: 2.5, length: 10, width: 8, height: 6)],
    );

    $rates = $this->adapter->getRates($residentialRequest, []);

    expect($rates)->toHaveCount(1);
});
