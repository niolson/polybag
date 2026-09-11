<?php

use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\CustomsItem;
use App\DataTransferObjects\Shipping\PackageData;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\Enums\BoxSizeType;
use App\Enums\TrackingStatus;
use App\Http\Integrations\USPS\Requests\CancelInternationalLabel;
use App\Http\Integrations\USPS\Requests\CancelLabel;
use App\Http\Integrations\USPS\Requests\InternationalLabel;
use App\Http\Integrations\USPS\Requests\Label;
use App\Http\Integrations\USPS\Requests\PaymentAuthorization;
use App\Http\Integrations\USPS\Requests\ShippingOptions;
use App\Http\Integrations\USPS\Requests\TrackShipment;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\Package;
use App\Models\Shipment;
use App\Services\Carriers\UspsAdapter;
use Saloon\Exceptions\Request\Statuses\InternalServerErrorException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;
use Saloon\Laravel\Facades\Saloon;

beforeEach(function (): void {
    $this->adapter = new UspsAdapter;
    createUspsAccount();
});

it('returns USPS as carrier name', function (): void {
    expect($this->adapter->getCarrierName())->toBe('USPS');
});

it('does not support multi-package shipments', function (): void {
    expect($this->adapter->supportsMultiPackage())->toBeFalse();
});

it('checks if adapter is configured', function (): void {
    // createUspsAccount() in beforeEach provides an active USPS CarrierAccount.
    expect($this->adapter->isConfigured())->toBeTrue();
});

it('returns false when not configured', function (): void {
    CarrierAccount::query()->delete();

    expect($this->adapter->isConfigured())->toBeFalse();
});

it('returns false when only an empty active account exists', function (): void {
    CarrierAccount::query()->delete();
    CarrierAccount::factory()->usps()->create([
        'carrier_id' => Carrier::where('name', 'USPS')->value('id'),
        'credentials' => null,
        'secret_credentials' => null,
    ]);

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

it('does not record a delivery date for an out-for-delivery scan', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        TrackShipment::class => MockResponse::make([
            [
                'trackingNumber' => '9400111899223456789012',
                'status' => 'Out for Delivery',
                'statusCategory' => 'Out for Delivery',
                'statusSummary' => 'Out for Delivery',
                'trackingEvents' => [
                    [
                        'eventType' => 'Out for Delivery',
                        'eventCode' => '59',
                        'actionCode' => 'ON_ROUTE',
                        'eventCity' => 'Los Angeles',
                        'eventState' => 'CA',
                        'eventCountry' => 'US',
                        'GMTTimestamp' => '2026-04-09T14:00:00Z',
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

    // "OUT FOR DELIVERY" contains the substring "DELIVER"; it must not be
    // mistaken for a delivery scan.
    expect($response->success)->toBeTrue()
        ->and($response->status)->toBe(TrackingStatus::OutForDelivery)
        ->and($response->deliveredAt)->toBeNull();
});

it('records a delivery date for a delivered-to-agent scan', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        TrackShipment::class => MockResponse::make([
            [
                'trackingNumber' => '9400111899223456789012',
                'status' => 'Delivered to Agent',
                'statusCategory' => 'Delivered to Agent',
                'statusSummary' => 'Delivered to Agent for Final Delivery',
                'trackingEvents' => [
                    [
                        'eventType' => 'Delivered to Agent for Final Delivery',
                        'eventCode' => '60',
                        'actionCode' => 'DELIVERED_TO_AGENT',
                        'eventCity' => 'Los Angeles',
                        'eventState' => 'CA',
                        'eventCountry' => 'US',
                        'GMTTimestamp' => '2026-04-09T18:15:00Z',
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
        ->and($response->deliveredAt?->toIso8601String())->toBe('2026-04-09T18:15:00+00:00');
});

it('treats a picked-up scan as delivered even without delivered status text', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        TrackShipment::class => MockResponse::make([
            [
                'trackingNumber' => '9400111899223456789012',
                // No "Delivered" text anywhere; only the event code 43 marks delivery.
                'status' => 'Picked Up',
                'statusCategory' => 'Picked Up',
                'statusSummary' => 'Your item was picked up at a postal facility',
                'trackingEvents' => [
                    [
                        'eventType' => 'Picked Up',
                        'eventCode' => '43',
                        'actionCode' => 'PICKED_UP',
                        'eventCity' => 'Los Angeles',
                        'eventState' => 'CA',
                        'eventCountry' => 'US',
                        'GMTTimestamp' => '2026-04-09T16:45:00Z',
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
        ->and($response->deliveredAt?->toIso8601String())->toBe('2026-04-09T16:45:00+00:00');
});

it('reports delivered status without a date when the delivered scan has no timestamp', function (): void {
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
                        // No GMTTimestamp or eventTimestamp.
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
        ->and($response->deliveredAt)->toBeNull();
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

it('allows all rate indicators when the package has no box type', function (): void {
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
    Saloon::assertSent(function (ShippingOptions $req): bool {
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

function uspsSpecialServiceShipRequest(array $codes, array $config = [], array $references = []): ShipRequest
{
    return new ShipRequest(
        fromAddress: new AddressData(
            firstName: 'Shipping',
            lastName: 'Center',
            streetAddress: '123 Warehouse St',
            city: 'Seattle',
            stateOrProvince: 'WA',
            postalCode: '98072',
        ),
        toAddress: new AddressData(
            firstName: 'John',
            lastName: 'Doe',
            streetAddress: '456 Main St',
            city: 'Los Angeles',
            stateOrProvince: 'CA',
            postalCode: '90210',
        ),
        packageData: new PackageData(weight: 2.0, length: 10, width: 8, height: 4),
        selectedRate: new RateResponse(
            carrier: 'USPS',
            serviceCode: 'USPS_GROUND_ADVANTAGE',
            serviceName: 'USPS Ground Advantage',
            price: 12.75,
            metadata: [
                'mailClass' => 'USPS_GROUND_ADVANTAGE',
                'processingCategory' => 'MACHINABLE',
                'rateIndicator' => 'SP',
                'destinationEntryFacilityType' => 'NONE',
            ],
        ),
        specialServiceCodes: $codes,
        specialServiceConfig: $config,
        references: $references,
    );
}

function fakeUspsLabelEndpoints(): void
{
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        PaymentAuthorization::class => MockResponse::make(['paymentAuthorizationToken' => 'test_payment_token']),
        Label::class => MockResponse::make(
            body: "--boundary\r\nContent-Type: application/json\r\n\r\n{\"trackingNumber\":\"9400111899223456789012\",\"postage\":8.50}\r\n--boundary\r\nContent-Type: application/pdf\r\n\r\nJVBERi0xLjQKYmFzZTY0bGFiZWxkYXRh\r\n--boundary--",
            headers: ['Content-Type' => 'multipart/mixed; boundary=boundary']
        ),
    ]);
}

it('asks USPS to print the label reference', function (): void {
    fakeUspsLabelEndpoints();

    expect($this->adapter->createShipment(uspsSpecialServiceShipRequest([], [], ['ORD-10042']))->success)->toBeTrue();

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof Label) {
            return false;
        }

        assertMatchesUspsSchema($request->body()->all(), 'LabelRequest');

        return ($request->body()->all()['packageDescription']['customerReference'] ?? null) === [
            ['referenceNumber' => 'ORD-10042', 'printReferenceNumber' => true],
        ];
    });
});

it('sends no customer reference when the client prints none', function (): void {
    fakeUspsLabelEndpoints();

    expect($this->adapter->createShipment(uspsSpecialServiceShipRequest([]))->success)->toBeTrue();

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof Label) {
            return false;
        }

        return ! array_key_exists('customerReference', $request->body()->all()['packageDescription'] ?? []);
    });
});

it('cuts the label reference down to what USPS will print', function (): void {
    fakeUspsLabelEndpoints();

    $reference = str_repeat('A', 40);

    expect($this->adapter->createShipment(uspsSpecialServiceShipRequest([], [], [$reference]))->success)->toBeTrue();

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof Label) {
            return false;
        }

        $printed = $request->body()->all()['packageDescription']['customerReference'][0]['referenceNumber'] ?? null;

        return $printed === str_repeat('A', 30);
    });
});

it('builds a ZPL label request for a business address that conforms to our USPS schema', function (): void {
    fakeUspsLabelEndpoints();

    $request = new ShipRequest(
        fromAddress: new AddressData(
            firstName: 'Shipping',
            lastName: 'Center',
            streetAddress: '123 Warehouse St',
            streetAddress2: 'Dock 4',
            city: 'Seattle',
            stateOrProvince: 'WA',
            postalCode: '98072-1234',
            company: 'PolyBag Fulfillment',
        ),
        toAddress: new AddressData(
            firstName: '',
            lastName: 'Receiving',
            streetAddress: '456 Main St',
            city: 'Los Angeles',
            stateOrProvince: 'CA',
            postalCode: '90210',
        ),
        packageData: new PackageData(weight: 2.0, length: 10, width: 8, height: 4),
        selectedRate: new RateResponse(
            carrier: 'USPS',
            serviceCode: 'PRIORITY_MAIL',
            serviceName: 'Priority Mail',
            price: 12.75,
            metadata: [
                'mailClass' => 'PRIORITY_MAIL',
                'processingCategory' => 'MACHINABLE',
                'rateIndicator' => 'SP',
                'destinationEntryFacilityType' => 'NONE',
            ],
        ),
        labelFormat: 'zpl',
        labelDpi: 300,
    );

    expect($this->adapter->createShipment($request)->success)->toBeTrue();

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof Label) {
            return false;
        }

        $body = $request->body()->all();

        assertMatchesUspsSchema($body, 'LabelRequest');

        // A lone last name is sent as the firm; the ZIP+4 is cut to five digits.
        return ($body['toAddress']['firm'] ?? null) === 'Receiving'
            && ! isset($body['toAddress']['lastName'])
            && $body['fromAddress']['ZIPCode'] === '98072'
            && $body['fromAddress']['firm'] === 'PolyBag Fulfillment'
            && $body['imageInfo']['imageType'] === 'ZPL300DPI';
    });
});

it('maps signature and declared value into the domestic label request', function (): void {
    fakeUspsLabelEndpoints();

    $response = $this->adapter->createShipment(uspsSpecialServiceShipRequest(
        ['adult_signature_required', 'declared_value'],
        ['declared_value' => ['amount' => 750.00, 'currency' => 'USD']],
    ));

    expect($response->success)->toBeTrue()
        ->and($response->appliedServices)->toBe(['adult_signature_required', 'declared_value']);

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof Label) {
            return false;
        }

        assertMatchesUspsSchema($request->body()->all(), 'LabelRequest');

        $description = $request->body()->all()['packageDescription'] ?? [];
        $options = $description['packageOptions'] ?? [];

        // $750 declared value crosses the $500 threshold: 931, not 930.
        // packageValue/physicalSignatureRequired live in packageOptions
        // (sandbox-verified — the API silently ignores them elsewhere).
        return ($description['extraServices'] ?? null) === [922, 931]
            && ($options['packageValue'] ?? null) === 750.00
            && ($options['physicalSignatureRequired'] ?? null) === false;
    });
});

it('uses insurance code 930 with packageValue for declared values at or below the threshold', function (): void {
    fakeUspsLabelEndpoints();

    $this->adapter->createShipment(uspsSpecialServiceShipRequest(
        ['declared_value'],
        ['declared_value' => ['amount' => 200.00, 'currency' => 'USD']],
    ));

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof Label) {
            return false;
        }

        $options = $request->body()->all()['packageDescription']['packageOptions'] ?? [];

        // 930 needs packageValue but not physicalSignatureRequired
        return ($request->body()->all()['packageDescription']['extraServices'] ?? null) === [930]
            && ($options['packageValue'] ?? null) === 200.00
            && ! array_key_exists('physicalSignatureRequired', $options);
    });
});

it('maps battery codes with hazmat content type into the domestic label request', function (): void {
    fakeUspsLabelEndpoints();

    $response = $this->adapter->createShipment(uspsSpecialServiceShipRequest(['lithium_battery_standalone']));

    expect($response->success)->toBeTrue()
        ->and($response->appliedServices)->toBe(['lithium_battery_standalone']);

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof Label) {
            return false;
        }

        assertMatchesUspsSchema($request->body()->all(), 'LabelRequest');

        $description = $request->body()->all()['packageDescription'] ?? [];

        return ($description['extraServices'] ?? null) === [820]
            && ($description['contentType'] ?? null) === 'HAZMAT'
            && ! array_key_exists('packageOptions', $description);
    });
});

it('includes mapped extra services in the rating request so quotes carry surcharges', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ShippingOptions::class => MockResponse::make(['pricingOptions' => []]),
    ]);

    $request = new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: '90210',
        packages: [new PackageData(weight: 2.0, length: 10, width: 8, height: 4)],
        specialServiceCodes: ['signature_required', 'declared_value'],
        specialServiceConfig: ['declared_value' => ['amount' => 100.00, 'currency' => 'USD']],
    );

    $this->adapter->getRates($request, ['USPS_GROUND_ADVANTAGE']);

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof ShippingOptions) {
            return false;
        }

        $description = $request->body()->all()['packageDescription'] ?? [];

        return ($description['extraServices'] ?? null) === [921, 930]
            && ($description['packageValue'] ?? null) === 100.00;
    });
});

/**
 * Build an extra USPS account with auth credentials but without the global scope
 * that createUspsAccount() adds (a second global scope would violate the unique
 * constraint). detectPricingType() takes the account directly, so no scope is needed.
 */
function makeUspsPricingAccount(): CarrierAccount
{
    return CarrierAccount::factory()->usps()->create([
        'carrier_id' => Carrier::firstOrCreate(['name' => 'USPS'])->id,
        'secret_credentials' => ['client_id' => 'test_client_id', 'client_secret' => 'test_client_secret'],
    ]);
}

it('detects CONTRACT pricing when the account has EPS contract access', function (): void {
    $account = makeUspsPricingAccount();

    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ShippingOptions::class => MockResponse::make(['pricingOptions' => [[]]]),
    ]);

    expect($this->adapter->detectPricingType($account))->toBe('CONTRACT')
        ->and($this->adapter->cachedPricingType($account))->toBe('CONTRACT');

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof ShippingOptions) {
            return false;
        }

        return ($request->body()->all()['pricingOptions'][0]['priceType'] ?? null) === 'CONTRACT';
    });
});

it('falls back to RETAIL pricing when the account lacks EPS contract access', function (): void {
    $account = makeUspsPricingAccount();

    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ShippingOptions::class => MockResponse::make(['error' => 'forbidden'], 403),
    ]);

    expect($this->adapter->detectPricingType($account))->toBe('RETAIL')
        ->and($this->adapter->cachedPricingType($account))->toBe('RETAIL');
});

it('scopes the detected pricing tier per account', function (): void {
    $contractAccount = makeUspsPricingAccount();
    $retailAccount = makeUspsPricingAccount();

    expect($this->adapter->cachedPricingType($contractAccount))->toBeNull()
        ->and($this->adapter->cachedPricingType($retailAccount))->toBeNull();

    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ShippingOptions::class => MockResponse::make(['error' => 'forbidden'], 403),
    ]);

    $this->adapter->detectPricingType($retailAccount);

    // The RETAIL fallback on one account must not poison the other account's tier.
    expect($this->adapter->cachedPricingType($retailAccount))->toBe('RETAIL')
        ->and($this->adapter->cachedPricingType($contractAccount))->toBeNull();
});

it('attaches a customs form to domestic military destinations', function (): void {
    // USPS rejects an APO/FPO/DPO label without customs data ("Customs form data
    // required for toAddress.ZIPCode"), even though the country is US. These stay
    // on the domestic label API at domestic prices.
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        PaymentAuthorization::class => MockResponse::make(['paymentAuthorizationToken' => 'test_payment_token']),
        Label::class => MockResponse::make(
            body: "--boundary\r\nContent-Type: application/json\r\n\r\n{\"trackingNumber\":\"9400111899223456789012\",\"postage\":8.50}\r\n--boundary\r\nContent-Type: application/pdf\r\n\r\nJVBERi0xLjQKYmFzZTY0bGFiZWxkYXRh\r\n--boundary--",
            headers: ['Content-Type' => 'multipart/mixed; boundary=boundary']
        ),
    ]);

    $request = new ShipRequest(
        fromAddress: new AddressData(
            firstName: 'Shipping',
            lastName: 'Center',
            streetAddress: '123 Warehouse St',
            city: 'Seattle',
            stateOrProvince: 'WA',
            postalCode: '98072',
        ),
        toAddress: new AddressData(
            firstName: 'John',
            lastName: 'Doe',
            streetAddress: 'PSC 402 BOX 301',
            city: 'FPO',
            stateOrProvince: 'AE',
            postalCode: '09532',
        ),
        packageData: new PackageData(weight: 2.5, length: 10, width: 8, height: 6),
        selectedRate: new RateResponse(
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
        ),
        customsItems: [new CustomsItem(
            description: 'Blue Widget',
            quantity: 2,
            unitValue: 19.99,
            weight: 0.5,
        )],
    );

    expect($this->adapter->createShipment($request)->success)->toBeTrue();

    Saloon::assertSent(function (Request $request): bool {
        if (! $request instanceof Label) {
            return false;
        }

        $body = $request->body()->all();

        assertMatchesUspsSchema($body, 'LabelRequest');

        return isset($body['customsForm'])
            && $body['customsForm']['contents'][0]['itemDescription'] === 'Blue Widget'
            && $body['customsForm']['contents'][0]['itemTotalValue'] === 39.98;
    });
});

it('repeats the label reference as the customs form invoice number, which is what prints', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        PaymentAuthorization::class => MockResponse::make(['paymentAuthorizationToken' => 'test_payment_token']),
        InternationalLabel::class => MockResponse::make(
            body: "--boundary\r\nContent-Type: application/json\r\n\r\n{\"internationalTrackingNumber\":\"LN123456789US\",\"postage\":42.50}\r\n--boundary\r\nContent-Type: application/pdf\r\n\r\nJVBERi0xLjQKYmFzZTY0bGFiZWxkYXRh\r\n--boundary--",
            headers: ['Content-Type' => 'multipart/mixed; boundary=boundary']
        ),
    ]);

    $request = new ShipRequest(
        fromAddress: new AddressData(
            firstName: 'Shipping',
            lastName: 'Center',
            streetAddress: '123 Warehouse St',
            city: 'Seattle',
            stateOrProvince: 'WA',
            postalCode: '98072',
        ),
        toAddress: new AddressData(
            firstName: 'Kenji',
            lastName: 'Sato',
            streetAddress: '4 Chome-2-8 Shibakoen',
            city: 'Minato City',
            stateOrProvince: 'TOKYO',
            postalCode: '105-0011',
            country: 'JP',
        ),
        packageData: new PackageData(weight: 2.5, length: 10, width: 8, height: 6),
        selectedRate: new RateResponse(
            carrier: 'USPS',
            serviceCode: 'PRIORITY_MAIL_INTERNATIONAL',
            serviceName: 'Priority Mail International',
            price: 42.50,
            metadata: [
                'mailClass' => 'PRIORITY_MAIL_INTERNATIONAL',
                'processingCategory' => 'MACHINABLE',
                'rateIndicator' => 'SP',
            ],
        ),
        customsItems: [new CustomsItem(
            description: 'Blue Widget',
            quantity: 2,
            unitValue: 19.99,
            weight: 0.5,
        )],
        references: ['ORD-10042'],
    );

    expect($this->adapter->createShipment($request)->success)->toBeTrue();

    Saloon::assertSent(function (Request $request): bool {
        if (! $request instanceof InternationalLabel) {
            return false;
        }

        $body = $request->body()->all();

        assertMatchesUspsSchema($body, 'InternationalLabelRequest');

        // Still sent as a customer reference too — USPS files that in the
        // Shipping Services File even though it never reaches the label.
        return ($body['customsForm']['invoiceNumber'] ?? null) === 'ORD-10042'
            && ($body['packageDescription']['customerReference'][0]['referenceNumber'] ?? null) === 'ORD-10042';
    });
});

it('leaves the invoice number off the customs form when no reference is printed', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        PaymentAuthorization::class => MockResponse::make(['paymentAuthorizationToken' => 'test_payment_token']),
        InternationalLabel::class => MockResponse::make(
            body: "--boundary\r\nContent-Type: application/json\r\n\r\n{\"internationalTrackingNumber\":\"LN123456789US\",\"postage\":42.50}\r\n--boundary\r\nContent-Type: application/pdf\r\n\r\nJVBERi0xLjQKYmFzZTY0bGFiZWxkYXRh\r\n--boundary--",
            headers: ['Content-Type' => 'multipart/mixed; boundary=boundary']
        ),
    ]);

    $request = new ShipRequest(
        fromAddress: new AddressData(
            firstName: 'Shipping',
            lastName: 'Center',
            streetAddress: '123 Warehouse St',
            city: 'Seattle',
            stateOrProvince: 'WA',
            postalCode: '98072',
        ),
        toAddress: new AddressData(
            firstName: 'Kenji',
            lastName: 'Sato',
            streetAddress: '4 Chome-2-8 Shibakoen',
            city: 'Minato City',
            stateOrProvince: 'TOKYO',
            postalCode: '105-0011',
            country: 'JP',
        ),
        packageData: new PackageData(weight: 2.5, length: 10, width: 8, height: 6),
        selectedRate: new RateResponse(
            carrier: 'USPS',
            serviceCode: 'PRIORITY_MAIL_INTERNATIONAL',
            serviceName: 'Priority Mail International',
            price: 42.50,
            metadata: [
                'mailClass' => 'PRIORITY_MAIL_INTERNATIONAL',
                'processingCategory' => 'MACHINABLE',
                'rateIndicator' => 'SP',
            ],
        ),
        customsItems: [new CustomsItem(
            description: 'Blue Widget',
            quantity: 2,
            unitValue: 19.99,
            weight: 0.5,
        )],
    );

    expect($this->adapter->createShipment($request)->success)->toBeTrue();

    Saloon::assertSent(function (Request $request): bool {
        if (! $request instanceof InternationalLabel) {
            return false;
        }

        assertMatchesUspsSchema($request->body()->all(), 'InternationalLabelRequest');

        return ! array_key_exists('invoiceNumber', $request->body()->all()['customsForm']);
    });
});

it('omits the customs form for ordinary domestic destinations', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        PaymentAuthorization::class => MockResponse::make(['paymentAuthorizationToken' => 'test_payment_token']),
        Label::class => MockResponse::make(
            body: "--boundary\r\nContent-Type: application/json\r\n\r\n{\"trackingNumber\":\"9400111899223456789012\",\"postage\":8.50}\r\n--boundary\r\nContent-Type: application/pdf\r\n\r\nJVBERi0xLjQKYmFzZTY0bGFiZWxkYXRh\r\n--boundary--",
            headers: ['Content-Type' => 'multipart/mixed; boundary=boundary']
        ),
    ]);

    $request = new ShipRequest(
        fromAddress: new AddressData(
            firstName: 'Shipping',
            lastName: 'Center',
            streetAddress: '123 Warehouse St',
            city: 'Seattle',
            stateOrProvince: 'WA',
            postalCode: '98072',
        ),
        toAddress: new AddressData(
            firstName: 'John',
            lastName: 'Doe',
            streetAddress: '456 Main St',
            city: 'Los Angeles',
            stateOrProvince: 'CA',
            postalCode: '90210',
        ),
        packageData: new PackageData(weight: 2.5, length: 10, width: 8, height: 6),
        selectedRate: new RateResponse(
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
        ),
        customsItems: [new CustomsItem(
            description: 'Blue Widget',
            quantity: 2,
            unitValue: 19.99,
            weight: 0.5,
        )],
    );

    expect($this->adapter->createShipment($request)->success)->toBeTrue();

    Saloon::assertSent(function (Request $request): bool {
        return $request instanceof Label
            && ! isset($request->body()->all()['customsForm']);
    });
});

it('translates USPS label error codes into actionable messages', function (array $body, string $expected): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        PaymentAuthorization::class => MockResponse::make(['paymentAuthorizationToken' => 'test_payment_token']),
        Label::class => MockResponse::make($body, 400),
    ]);

    $request = new ShipRequest(
        fromAddress: new AddressData(
            firstName: 'Shipping',
            lastName: 'Center',
            streetAddress: '123 Warehouse St',
            city: 'Seattle',
            stateOrProvince: 'WA',
            postalCode: '98072',
        ),
        toAddress: new AddressData(
            firstName: 'John',
            lastName: 'Doe',
            streetAddress: 'UNIT 100254 BOX 800',
            city: 'FPO',
            stateOrProvince: 'AE',
            postalCode: '09592',
        ),
        packageData: new PackageData(weight: 0.7, length: 10, width: 8, height: 6),
        selectedRate: new RateResponse(
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
        ),
        customsItems: [new CustomsItem(description: 'Blue Widget', quantity: 2, unitValue: 19.99, weight: 0.5)],
    );

    $response = $this->adapter->createShipment($request);

    expect($response->success)->toBeFalse()
        ->and($response->errorMessage)->toBe($expected);
})->with([
    'inactive ZIP code' => [
        [
            'apiVersion' => '/labels/v3/',
            'error' => [
                'code' => '400',
                'message' => 'Bad Request',
                'errors' => [[
                    'title' => 'Bad Request',
                    'detail' => 'cannot be generated for inactive toAddress.ZIPCode 095875400',
                    'code' => '160138',
                ]],
            ],
        ],
        'USPS reports this destination ZIP Code is no longer in service. Check the address with the customer.',
    ],
    'customs weight mismatch' => [
        [
            'apiVersion' => '/labels/v3/',
            'error' => [
                'code' => '400',
                'message' => 'Bad Request',
                'errors' => [[
                    'detail' => 'total weight of all of the content items: 1.90 cannot be more than the total weight: 0.7 of the package',
                    'code' => '160021',
                ]],
            ],
        ],
        'The customs item weights add up to more than the package weight. Re-weigh the package, or confirm the customs weight override.',
    ],
    'unmapped code falls back to the USPS detail' => [
        [
            'apiVersion' => '/labels/v3/',
            'error' => [
                'code' => '400',
                'message' => 'Bad Request',
                'errors' => [[
                    'detail' => 'mailClass is not eligible for this destination',
                    'code' => '999999',
                ]],
            ],
        ],
        'mailClass is not eligible for this destination',
    ],
    'bare Bad Request is not echoed back' => [
        ['apiVersion' => '/labels/v3/', 'error' => ['code' => '400', 'message' => 'Bad Request', 'errors' => []]],
        'USPS rejected the label request.',
    ],
]);

it('fails gracefully when the label endpoint answers with a non-JSON error page', function (): void {
    // A gateway between us and USPS can answer with an HTML error page. The 5xx
    // is retried, then thrown, and decoding it must not throw out of the catch.
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        PaymentAuthorization::class => MockResponse::make(['paymentAuthorizationToken' => 'test_payment_token']),
        Label::class => MockResponse::make(
            body: '<html><head><title>502 Bad Gateway</title></head><body>502 Bad Gateway</body></html>',
            status: 502,
            headers: ['Content-Type' => 'text/html'],
        ),
    ]);

    $response = $this->adapter->createShipment(uspsSpecialServiceShipRequest([]));

    expect($response->success)->toBeFalse()
        ->and($response->errorMessage)->toBe('USPS rejected the label request.');
});

it('does not surface a schema validation dump to the packer', function (): void {
    // USPS answers a malformed field with a multi-line OpenAPI validation trace
    // that is longer than the panel can show and means nothing at the bench.
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        PaymentAuthorization::class => MockResponse::make(['paymentAuthorizationToken' => 'test_payment_token']),
        Label::class => MockResponse::make([
            'error' => [
                'code' => '400',
                'message' => "OASValidation OpenAPI-Spec-Validation-Labels with resource oas://labels-v3.yaml: failed with reason: [ERROR - [Path '/toAddress'] Instance failed to match all required schemas",
                'errors' => [],
            ],
        ], 400),
    ]);

    $request = new ShipRequest(
        fromAddress: new AddressData(
            firstName: 'Shipping',
            lastName: 'Center',
            streetAddress: '123 Warehouse St',
            city: 'Seattle',
            stateOrProvince: 'WA',
            postalCode: '98072',
        ),
        toAddress: new AddressData(
            firstName: 'John',
            lastName: 'Doe',
            streetAddress: '456 Main St',
            city: 'Los Angeles',
            stateOrProvince: 'CA',
            postalCode: '90210',
        ),
        packageData: new PackageData(weight: 2.0, length: 10, width: 8, height: 6),
        selectedRate: new RateResponse(
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
        ),
    );

    expect($this->adapter->createShipment($request)->errorMessage)
        ->toBe('USPS rejected the label request.');
});
