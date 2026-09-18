<?php

use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\CustomsItem;
use App\DataTransferObjects\Shipping\PackageData;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\Enums\BoxSizeType;
use App\Enums\CarrierPackaging;
use App\Enums\TrackingStatus;
use App\Exceptions\Carriers\UnclassifiablePackagingException;
use App\Http\Integrations\USPS\Requests\CancelInternationalLabel;
use App\Http\Integrations\USPS\Requests\CancelLabel;
use App\Http\Integrations\USPS\Requests\InternationalLabel;
use App\Http\Integrations\USPS\Requests\InternationalLabelReprint;
use App\Http\Integrations\USPS\Requests\Label;
use App\Http\Integrations\USPS\Requests\LabelReprint;
use App\Http\Integrations\USPS\Requests\PaymentAuthorization;
use App\Http\Integrations\USPS\Requests\ShippingOptions;
use App\Http\Integrations\USPS\Requests\TrackShipment;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\ShippingOffer;
use App\Services\Carriers\UspsAdapter;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\ServerException;
use Saloon\Exceptions\Request\Statuses\InternalServerErrorException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
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
                                            // PA is Priority Mail Express's single-piece indicator; USPS never returns it on Ground Advantage.
                                            'mailClass' => 'PRIORITY_MAIL_EXPRESS',
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

/**
 * A `search` response in the shape USPS returns it, one rate per (mailClass,
 * rateIndicator, processingCategory) triple — the pairs are the ones the
 * sandbox returned for a small box (packaging-form-and-carrier-identity/05).
 *
 * @param  list<array{0: string, 1: string, 2: string, 3?: float}>  $rates
 */
function fakeUspsSearch(array $rates): void
{
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ShippingOptions::class => MockResponse::make([
            'pricingOptions' => [[
                'shippingOptions' => [[
                    'rateOptions' => array_map(fn (array $rate): array => [
                        'totalBasePrice' => $rate[3] ?? 10.00,
                        'rates' => [[
                            'mailClass' => $rate[0],
                            'rateIndicator' => $rate[1],
                            'processingCategory' => $rate[2],
                            'destinationEntryFacilityType' => 'NONE',
                            'description' => "{$rate[0]} {$rate[1]}",
                        ]],
                    ], $rates),
                ]],
            ]],
        ]),
    ]);
}

/**
 * The flat-rate mix a small box gets quoted, plus the two shipper-packaging
 * indicators and a soft-pack tier.
 *
 * @return list<array{0: string, 1: string, 2: string, 3?: float}>
 */
function uspsSmallBoxSearch(): array
{
    return [
        ['PRIORITY_MAIL', 'SP', 'MACHINABLE', 15.22],
        ['PRIORITY_MAIL', 'CP', 'MACHINABLE', 15.51],
        ['PRIORITY_MAIL', 'P5', 'MACHINABLE', 15.51],
        ['PRIORITY_MAIL', 'FE', 'FLATS', 11.12],
        ['PRIORITY_MAIL', 'FA', 'FLATS', 11.66],
        ['PRIORITY_MAIL', 'FP', 'FLATS', 11.99],
        ['PRIORITY_MAIL', 'FS', 'MACHINABLE', 12.10],
        ['PRIORITY_MAIL', 'FB', 'MACHINABLE', 21.17],
        ['PRIORITY_MAIL', 'PL', 'MACHINABLE', 31.00],
        ['PRIORITY_MAIL', 'PM', 'MACHINABLE', 29.59],
        ['PRIORITY_MAIL_EXPRESS', 'PA', 'MACHINABLE', 55.79],
        ['PRIORITY_MAIL_EXPRESS', 'E4', 'FLATS', 31.11],
        ['PRIORITY_MAIL_EXPRESS', 'E6', 'FLATS', 31.43],
        ['PRIORITY_MAIL_EXPRESS', 'E7', 'FLATS', 31.43],
        ['PRIORITY_MAIL_EXPRESS', 'FP', 'FLATS', 31.70],
    ];
}

/**
 * @return array<string, PackagingRequirement> keyed "MAIL_CLASS/INDICATOR"
 */
function uspsRequirementsByPair(Collection $rates): array
{
    return $rates
        ->mapWithKeys(fn (RateResponse $rate): array => [
            "{$rate->metadata['mailClass']}/{$rate->metadata['rateIndicator']}" => $rate->packagingRequirement,
        ])
        ->all();
}

it('keeps the flat-rate indicators for a box as exactly the packaging USPS priced them for', function (): void {
    fakeUspsSearch(uspsSmallBoxSearch());

    $request = new RateRequest(
        originPostalCode: '90210',
        destinationPostalCode: '10001',
        packages: [new PackageData(weight: 1.0, length: 8, width: 5, height: 1.5, boxType: BoxSizeType::BOX)],
    );

    $byPair = uspsRequirementsByPair($this->adapter->getRates($request, []));

    expect(array_keys($byPair))->toBe([
        'PRIORITY_MAIL/SP', 'PRIORITY_MAIL/CP',
        'PRIORITY_MAIL/FE', 'PRIORITY_MAIL/FA', 'PRIORITY_MAIL/FP', 'PRIORITY_MAIL/FS', 'PRIORITY_MAIL/FB', 'PRIORITY_MAIL/PL',
        'PRIORITY_MAIL_EXPRESS/PA', 'PRIORITY_MAIL_EXPRESS/E4', 'PRIORITY_MAIL_EXPRESS/E6', 'PRIORITY_MAIL_EXPRESS/FP',
    ]);

    expect($byPair['PRIORITY_MAIL/SP']->isShipperPackaging())->toBeTrue()
        ->and($byPair['PRIORITY_MAIL/CP']->isShipperPackaging())->toBeTrue()
        ->and($byPair['PRIORITY_MAIL_EXPRESS/PA']->isShipperPackaging())->toBeTrue();

    expect($byPair['PRIORITY_MAIL/FE'])->toEqual(PackagingRequirement::exactly(CarrierPackaging::UspsFlatRateEnvelope))
        ->and($byPair['PRIORITY_MAIL/FA'])->toEqual(PackagingRequirement::exactly(CarrierPackaging::UspsLegalFlatRateEnvelope))
        ->and($byPair['PRIORITY_MAIL/FP'])->toEqual(PackagingRequirement::exactly(CarrierPackaging::UspsPaddedFlatRateEnvelope))
        ->and($byPair['PRIORITY_MAIL/FS'])->toEqual(PackagingRequirement::exactly(CarrierPackaging::UspsSmallFlatRateBox))
        ->and($byPair['PRIORITY_MAIL/FB'])->toEqual(PackagingRequirement::exactly(CarrierPackaging::UspsMediumFlatRateBox))
        ->and($byPair['PRIORITY_MAIL/PL'])->toEqual(PackagingRequirement::exactly(CarrierPackaging::UspsLargeFlatRateBox));

    // FP is the padded envelope under both classes; the class tells them apart.
    expect($byPair['PRIORITY_MAIL_EXPRESS/E4'])->toEqual(PackagingRequirement::exactly(CarrierPackaging::UspsExpressFlatRateEnvelope))
        ->and($byPair['PRIORITY_MAIL_EXPRESS/E6'])->toEqual(PackagingRequirement::exactly(CarrierPackaging::UspsExpressLegalFlatRateEnvelope))
        ->and($byPair['PRIORITY_MAIL_EXPRESS/FP'])->toEqual(PackagingRequirement::exactly(CarrierPackaging::UspsExpressPaddedFlatRateEnvelope));
});

it('drops the APO large box and holiday envelope prices rather than offer a box twice', function (): void {
    // PM is the large flat rate box at the APO/FPO/DPO price and comes back
    // for any destination, cheaper than PL; E7 is the Express legal envelope
    // at the holiday-delivery price. Neither is a packaging of its own.
    fakeUspsSearch(uspsSmallBoxSearch());

    $request = new RateRequest(
        originPostalCode: '90210',
        destinationPostalCode: '10001',
        packages: [new PackageData(weight: 1.0, length: 8, width: 5, height: 1.5)],
    );

    $indicators = $this->adapter->getRates($request, [])->pluck('metadata.rateIndicator')->all();

    expect($indicators)->toContain('PL', 'E6')
        ->and(array_intersect($indicators, ['PM', 'E7']))->toBe([]);
});

it('keeps the flat-rate indicators for a polybag too, alongside its soft-pack tiers', function (): void {
    // The form filter still chooses cubic tiers by box type; the flat-rate
    // indicators pass every form and the shared filter decides from the
    // Package's declared packaging.
    fakeUspsSearch(uspsSmallBoxSearch());

    $request = new RateRequest(
        originPostalCode: '90210',
        destinationPostalCode: '10001',
        packages: [new PackageData(weight: 1.0, length: 8, width: 5, height: 1.5, boxType: BoxSizeType::POLYBAG)],
    );

    $indicators = $this->adapter->getRates($request, [])->pluck('metadata.rateIndicator')->all();

    expect($indicators)->toContain('P5', 'FE', 'FB')
        ->and(in_array('CP', $indicators, true))->toBeFalse();
});

it('still drops real flats that are not flat-rate envelopes', function (): void {
    // Priority Mail International quotes a "Single-piece Large Envelope" as
    // SP/FLATS — a flat, not a parcel, and not a flat-rate product.
    fakeUspsSearch([
        ['PRIORITY_MAIL_INTERNATIONAL', 'SP', 'FLATS'],
        ['PRIORITY_MAIL_INTERNATIONAL', 'SP', 'MACHINABLE'],
        ['PRIORITY_MAIL_INTERNATIONAL', 'FB', 'MACHINABLE'],
    ]);

    $request = new RateRequest(
        originPostalCode: '90210',
        destinationPostalCode: 'M5V 3L9',
        destinationCountry: 'CA',
        packages: [new PackageData(weight: 1.0, length: 8, width: 5, height: 1.5)],
    );

    $rates = $this->adapter->getRates($request, []);

    expect($rates->map(fn (RateResponse $rate): string => "{$rate->metadata['rateIndicator']}/{$rate->metadata['processingCategory']}")->all())
        ->toBe(['SP/MACHINABLE', 'FB/MACHINABLE'])
        ->and($rates[1]->packagingRequirement)->toEqual(PackagingRequirement::exactly(CarrierPackaging::UspsMediumFlatRateBox));
});

it('classifies a purchase from the same pair the label sends', function (): void {
    $rate = fn (string $mailClass, string $indicator): RateResponse => new RateResponse(
        carrier: 'USPS',
        serviceCode: $mailClass,
        serviceName: $mailClass,
        price: 10.0,
        metadata: ['mailClass' => $mailClass, 'rateIndicator' => $indicator, 'processingCategory' => 'MACHINABLE'],
        // The browser may restate anything here; the adapter does not read it.
        packagingRequirement: PackagingRequirement::shipperPackaging(),
    );

    expect($this->adapter->packagingRequirementFor($rate('PRIORITY_MAIL', 'FB')))
        ->toEqual(PackagingRequirement::exactly(CarrierPackaging::UspsMediumFlatRateBox))
        ->and($this->adapter->packagingRequirementFor($rate('PRIORITY_MAIL_EXPRESS', 'FP')))
        ->toEqual(PackagingRequirement::exactly(CarrierPackaging::UspsExpressPaddedFlatRateEnvelope))
        ->and($this->adapter->packagingRequirementFor($rate('USPS_GROUND_ADVANTAGE', 'CP'))->isShipperPackaging())
        ->toBeTrue();
});

it('refuses to classify an indicator it does not sell instead of defaulting it', function (string $mailClass, string $indicator): void {
    $rate = new RateResponse(
        carrier: 'USPS',
        serviceCode: $mailClass,
        serviceName: $mailClass,
        price: 10.0,
        metadata: ['mailClass' => $mailClass, 'rateIndicator' => $indicator, 'processingCategory' => 'MACHINABLE'],
    );

    expect(fn () => $this->adapter->packagingRequirementFor($rate))
        ->toThrow(UnclassifiablePackagingException::class);
})->with([
    'APO large box price' => ['PRIORITY_MAIL', 'PM'],
    'holiday envelope price' => ['PRIORITY_MAIL_EXPRESS', 'E7'],
    'a Priority Mail envelope under Ground Advantage' => ['USPS_GROUND_ADVANTAGE', 'FE'],
    'single-piece on a class rate shopping excludes' => ['MEDIA_MAIL', 'SP'],
    'single-piece on a presort class' => ['BOUND_PRINTED_MATTER', 'SP'],
    'a class USPS does not have' => ['PRIORITY_MAIL_CUBIC', 'CP'],
    'the Express single-piece indicator on Ground Advantage' => ['USPS_GROUND_ADVANTAGE', 'PA'],
    'a cubic tier on Express, which has none' => ['PRIORITY_MAIL_EXPRESS', 'CP'],
    'a cubic tier on an international class' => ['PRIORITY_MAIL_INTERNATIONAL', 'P5'],
    'the domestic single-piece indicator on Express International' => ['PRIORITY_MAIL_EXPRESS_INTERNATIONAL', 'SP'],
    'nothing at all' => ['PRIORITY_MAIL', ''],
]);

it('drops a rate whose class and indicator are each sold, but not together', function (): void {
    // The filter reads the same per-class table as the classifier, so a pair
    // the classifier would refuse never reaches a quote — and the classifier
    // never throws at rate shopping on something the filter kept.
    fakeUspsSearch([
        ['USPS_GROUND_ADVANTAGE', 'SP', 'MACHINABLE'],
        ['USPS_GROUND_ADVANTAGE', 'PA', 'MACHINABLE'],
        ['PRIORITY_MAIL_EXPRESS', 'PA', 'MACHINABLE'],
        ['PRIORITY_MAIL_EXPRESS', 'CP', 'MACHINABLE'],
        ['PRIORITY_MAIL_EXPRESS', 'SP', 'MACHINABLE'],
    ]);

    $request = new RateRequest(
        originPostalCode: '90210',
        destinationPostalCode: '10001',
        packages: [new PackageData(weight: 1.0, length: 8, width: 5, height: 1.5)],
    );

    expect(uspsRequirementsByPair($this->adapter->getRates($request, [])))
        ->toHaveKeys(['USPS_GROUND_ADVANTAGE/SP', 'PRIORITY_MAIL_EXPRESS/PA'])
        ->toHaveCount(2);
});

it('builds a flat-rate label from the rate metadata alone and it conforms to our USPS schema', function (): void {
    fakeUspsLabelEndpoints();

    $request = new ShipRequest(
        fromAddress: new AddressData(
            firstName: 'Shipping',
            lastName: 'Center',
            streetAddress: '123 Warehouse St',
            city: 'Seattle',
            stateOrProvince: 'WA',
            postalCode: '98072',
            company: 'PolyBag Fulfillment',
        ),
        toAddress: new AddressData(
            firstName: 'Jane',
            lastName: 'Receiving',
            streetAddress: '456 Main St',
            city: 'Los Angeles',
            stateOrProvince: 'CA',
            postalCode: '90210',
        ),
        packageData: new PackageData(weight: 2.0, length: 11, width: 8.5, height: 5.5, boxType: BoxSizeType::BOX, carrierPackaging: CarrierPackaging::UspsMediumFlatRateBox),
        selectedRate: new RateResponse(
            carrier: 'USPS',
            serviceCode: 'PRIORITY_MAIL',
            serviceName: 'Priority Mail Machinable Medium Flat Rate Box',
            price: 21.17,
            metadata: [
                'mailClass' => 'PRIORITY_MAIL',
                'processingCategory' => 'MACHINABLE',
                'rateIndicator' => 'FB',
                'destinationEntryFacilityType' => 'NONE',
            ],
            packagingRequirement: PackagingRequirement::exactly(CarrierPackaging::UspsMediumFlatRateBox),
        ),
    );

    expect($this->adapter->createShipment($request)->success)->toBeTrue();

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof Label) {
            return false;
        }

        $body = $request->body()->all();

        assertMatchesUspsSchema($body, 'LabelRequest');

        return $body['packageDescription']['mailClass'] === 'PRIORITY_MAIL'
            && $body['packageDescription']['rateIndicator'] === 'FB'
            && $body['packageDescription']['processingCategory'] === 'MACHINABLE';
    });
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
    // A gateway or WAF between us and USPS can answer with an HTML error page,
    // and decoding it must not throw out of the catch.
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        PaymentAuthorization::class => MockResponse::make(['paymentAuthorizationToken' => 'test_payment_token']),
        Label::class => MockResponse::make(
            body: '<html><head><title>403 Forbidden</title></head><body>403 Forbidden</body></html>',
            status: 403,
            headers: ['Content-Type' => 'text/html'],
        ),
    ]);

    $response = $this->adapter->createShipment(uspsSpecialServiceShipRequest([]));

    expect($response->success)->toBeFalse()
        ->and($response->errorMessage)->toBe('USPS rejected the label request.');
});

it('lets a 5xx on the label request escape rather than settle it as a decline', function (): void {
    // USPS may have created the label before the server error, so a 5xx is
    // no answer at all: the offer stays unresolved and the reprint decides.
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        PaymentAuthorization::class => MockResponse::make(['paymentAuthorizationToken' => 'test_payment_token']),
        Label::class => MockResponse::make(
            body: '<html><head><title>502 Bad Gateway</title></head><body>502 Bad Gateway</body></html>',
            status: 502,
            headers: ['Content-Type' => 'text/html'],
        ),
    ]);

    expect(fn () => $this->adapter->createShipment(uspsSpecialServiceShipRequest([])))
        ->toThrow(ServerException::class);
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

// --- Recovery by X-Idempotency-Key — postage-source-split/18 ---------------

/**
 * A domestic ship request from an offer, the way the workflow builds one.
 */
function uspsOfferShipRequest(?ShippingOffer $offer, string $country = 'US', string $labelFormat = 'pdf', ?int $labelDpi = null): ShipRequest
{
    $international = $country !== 'US';

    return new ShipRequest(
        fromAddress: new AddressData(firstName: 'Shipping', lastName: 'Center', streetAddress: '123 Warehouse St', city: 'Seattle', stateOrProvince: 'WA', postalCode: '98072'),
        toAddress: $international
            ? new AddressData(firstName: 'Jean', lastName: 'Tremblay', streetAddress: '100 Queen St W', city: 'Toronto', stateOrProvince: 'ON', postalCode: 'M5H 2N2', country: 'CA')
            : new AddressData(firstName: 'John', lastName: 'Doe', streetAddress: '456 Main St', city: 'Los Angeles', stateOrProvince: 'CA', postalCode: '90210'),
        packageData: new PackageData(weight: 2.5, length: 10, width: 8, height: 6),
        selectedRate: new RateResponse(
            carrier: 'USPS',
            serviceCode: $international ? 'PRIORITY_MAIL_INTERNATIONAL' : 'USPS_GROUND_ADVANTAGE',
            serviceName: $international ? 'Priority Mail International' : 'USPS Ground Advantage',
            price: 8.50,
            metadata: [
                'mailClass' => $international ? 'PRIORITY_MAIL_INTERNATIONAL' : 'USPS_GROUND_ADVANTAGE',
                'processingCategory' => 'MACHINABLE',
                'rateIndicator' => 'SP',
                'destinationEntryFacilityType' => $international ? 'INTERNATIONAL_SERVICE_CENTER' : 'NONE',
            ],
        ),
        customsItems: $international ? [new CustomsItem(description: 'Blue Widget', quantity: 1, unitValue: 19.99, weight: 0.5)] : [],
        labelFormat: $labelFormat,
        labelDpi: $labelDpi,
        offer: $offer,
    );
}

/**
 * The multipart body both the label and the reprint endpoints answer with;
 * a reprint adds the third part.
 */
function uspsLabelMultipart(string $trackingNumber, bool $reprint = false): MockResponse
{
    $parts = "--boundary\r\nContent-Type: application/json\r\n\r\n{\"trackingNumber\":\"{$trackingNumber}\",\"postage\":8.40}"
        ."\r\n--boundary\r\nContent-Type: application/pdf\r\n\r\nJVBERi0xLjQKYmFzZTY0bGFiZWxkYXRh";

    if ($reprint) {
        $parts .= "\r\n--boundary\r\nContent-Type: application/json\r\n\r\n{\"reprintNumber\":1,\"reprintLimit\":3}";
    }

    return MockResponse::make(body: $parts."\r\n--boundary--", headers: ['Content-Type' => 'multipart/form-data; boundary=boundary']);
}

/**
 * A USPS label-API error body, as captured in production on 2026-09-18.
 */
function uspsLabelError(string $code, string $detail): MockResponse
{
    return MockResponse::make([
        'apiVersion' => '/labels/v3/',
        'error' => [
            'code' => '400',
            'message' => 'Bad Request',
            'errors' => [['title' => 'Bad Request', 'detail' => $detail, 'code' => $code, 'source' => ['parameter' => 'Header: X-Idempotency-Key']]],
        ],
    ], 400);
}

function fakeUspsAuth(): array
{
    return [
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        PaymentAuthorization::class => MockResponse::make(['paymentAuthorizationToken' => 'test_payment_token']),
    ];
}

it('stores a fresh idempotency key on the offer and sends it with the label request', function (string $country, string $labelClass): void {
    Saloon::fake([...fakeUspsAuth(), $labelClass => uspsLabelMultipart('9200190414219000000011')]);
    $offer = ShippingOffer::factory()->direct()->create();

    $response = $this->adapter->createShipment(uspsOfferShipRequest($offer, $country));

    $key = $offer->fresh()->purchase_context[UspsAdapter::PURCHASE_CONTEXT_KEY] ?? null;

    expect($response->success)->toBeTrue()
        ->and($key)->toBeString()
        ->and(Str::isUuid($key))->toBeTrue()
        // The key is the only thing the context holds — nothing that could
        // spend money, nothing that reaches the browser.
        ->and($offer->fresh()->purchase_context)->toBe([UspsAdapter::PURCHASE_CONTEXT_KEY => $key]);

    Saloon::assertSent(function (Request $request) use ($labelClass, $key, $country): bool {
        if (! $request instanceof $labelClass || ! ($request instanceof Label || $request instanceof InternationalLabel)) {
            return false;
        }

        // The schema guard describes the body; the key travels as a header
        // and must not have leaked into it.
        assertMatchesApiSchema($request->body()->all(), $country === 'US' ? 'LabelRequest' : 'InternationalLabelRequest', 'uspsLabel');

        return $request->headers()->get('X-Idempotency-Key') === $key;
    });
})->with([
    'domestic' => ['US', Label::class],
    'international' => ['CA', InternationalLabel::class],
]);

it('still sends an idempotency key when the purchase has no offer to store it on', function (): void {
    Saloon::fake([...fakeUspsAuth(), Label::class => uspsLabelMultipart('9200190414219000000011')]);

    expect($this->adapter->createShipment(uspsOfferShipRequest(null))->success)->toBeTrue();

    Saloon::assertSent(fn (Request $request): bool => $request instanceof Label
        && Str::isUuid((string) $request->headers()->get('X-Idempotency-Key')));
});

it('lets a label request that got no answer escape, with the key already on the offer', function (): void {
    $attempts = 0;
    Saloon::fake([
        ...fakeUspsAuth(),
        Label::class => function (PendingRequest $pending) use (&$attempts): MockResponse {
            $attempts++;

            return MockResponse::make()->throw(new FatalRequestException(new RuntimeException('Connection timed out'), $pending));
        },
    ]);
    $offer = ShippingOffer::factory()->direct()->create();

    expect(fn () => $this->adapter->createShipment(uspsOfferShipRequest($offer)))
        ->toThrow(FatalRequestException::class);

    expect($offer->fresh()->purchase_context[UspsAdapter::PURCHASE_CONTEXT_KEY] ?? null)->toBeString()
        // Sent exactly once: the connector retries connection failures, and
        // a retry under the same key is a second label.
        ->and($attempts)->toBe(1);
});

it('still answers a refusal as a failed response, not an exception', function (): void {
    Saloon::fake([...fakeUspsAuth(), Label::class => uspsLabelError('160138', 'ZIP Code not in service')]);

    $response = $this->adapter->createShipment(uspsOfferShipRequest(ShippingOffer::factory()->direct()->create()));

    expect($response->success)->toBeFalse()
        ->and($response->errorMessage)->toContain('no longer in service');
});

it('recovers the label by the stored key instead of buying again', function (string $country, string $reprintClass): void {
    Saloon::fake([...fakeUspsAuth(), $reprintClass => uspsLabelMultipart('9200190414219000000011', reprint: true)]);
    $offer = ShippingOffer::factory()->direct()->awaitingConfirmation()->create([
        'purchase_context' => [UspsAdapter::PURCHASE_CONTEXT_KEY => '3a2befe8-4475-48c7-a327-fe53439b355b'],
    ]);

    $response = $this->adapter->recoverPurchase(uspsOfferShipRequest($offer, $country, 'zpl', 300));

    expect($response)->not->toBeNull()
        ->and($response->success)->toBeTrue()
        ->and($response->trackingNumber)->toBe('9200190414219000000011')
        ->and($response->cost)->toBe(8.40)
        ->and($response->labelData)->toBe('JVBERi0xLjQKYmFzZTY0bGFiZWxkYXRh')
        ->and($response->labelFormat)->toBe('zpl')
        ->and($response->labelDpi)->toBe(300)
        // The orientation the purchase path records for each API.
        ->and($response->labelOrientation)->toBe($country === 'US' ? 'portrait' : 'landscape');

    Saloon::assertSent(fn (Request $request): bool => $request instanceof $reprintClass
        && ($request instanceof LabelReprint || $request instanceof InternationalLabelReprint)
        && $request->headers()->get('X-Idempotency-Key') === '3a2befe8-4475-48c7-a327-fe53439b355b'
        && $request->headers()->get('X-Payment-Authorization-Token') === 'test_payment_token'
        && $request->body()->all() === ['imageInfo' => ['imageType' => 'ZPL300DPI', 'labelType' => '4X6LABEL']]);
    Saloon::assertNotSent(Label::class);
    Saloon::assertNotSent(InternationalLabel::class);
})->with([
    'domestic' => ['US', LabelReprint::class],
    'international' => ['CA', InternationalLabelReprint::class],
]);

it('settles the offer when USPS is certain nothing was bought, or the label was cancelled', function (string $code, string $detail, string $expected): void {
    Saloon::fake([...fakeUspsAuth(), LabelReprint::class => uspsLabelError($code, $detail)]);
    $offer = ShippingOffer::factory()->direct()->awaitingConfirmation()->create([
        'purchase_context' => [UspsAdapter::PURCHASE_CONTEXT_KEY => (string) Str::uuid()],
    ]);

    $response = $this->adapter->recoverPurchase(uspsOfferShipRequest($offer));

    expect($response)->not->toBeNull()
        ->and($response->success)->toBeFalse()
        ->and($response->errorMessage)->toContain($expected);
})->with([
    'key never used' => ['160412', 'Idempotency-Key not found for a mailing date within the last 7 days', 'nothing was bought'],
    'label cancelled' => ['160979', 'Canceled labels are unavailable for reprint', 'has since been cancelled'],
]);

it('leaves the question open on any other reprint answer', function (MockResponse $reprint): void {
    Saloon::fake([...fakeUspsAuth(), LabelReprint::class => $reprint]);
    $offer = ShippingOffer::factory()->direct()->awaitingConfirmation()->create([
        'purchase_context' => [UspsAdapter::PURCHASE_CONTEXT_KEY => (string) Str::uuid()],
    ]);

    expect($this->adapter->recoverPurchase(uspsOfferShipRequest($offer)))->toBeNull();
})->with([
    'a different 400' => fn () => uspsLabelError('160999', 'Something else'),
    'a 503' => fn () => MockResponse::make(['error' => ['message' => 'Service Unavailable']], 503),
    'no answer' => fn () => MockResponse::make()->throw(fn (PendingRequest $pending) => new FatalRequestException(new RuntimeException('Connection timed out'), $pending)),
]);

it('settles an offer spent before any key was recorded, since USPS cannot be asked about it', function (): void {
    Saloon::fake(fakeUspsAuth());
    $offer = ShippingOffer::factory()->direct()->awaitingConfirmation()->create(['purchase_context' => null]);

    $response = $this->adapter->recoverPurchase(uspsOfferShipRequest($offer));

    expect($response)->not->toBeNull()
        ->and($response->success)->toBeFalse()
        ->and($response->errorMessage)->toContain('cannot be asked');
    Saloon::assertNothingSent();
});

it('asks USPS on the account the offer was bought on, not the one scopes prefer now', function (): void {
    // Keys are per CRID. A second account is now the location default; the
    // offer records the first, so the first is asked and its payment token
    // is minted.
    $original = CarrierAccount::query()->firstOrFail();
    // Scopes edited since the quote: the global default now points at a
    // second account, and the original keeps no scope at all.
    $original->scopes()->delete();
    $preferred = createUspsAccount(['client_id' => 'other_client'], ['crid' => 'other_crid', 'mid' => 'other_mid']);
    expect($preferred->id)->not->toBe($original->id);

    $minted = [];
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        PaymentAuthorization::class => function (PendingRequest $pending) use (&$minted): MockResponse {
            $minted[] = $pending->body()->all()['roles'][0]['CRID'];

            return MockResponse::make(['paymentAuthorizationToken' => 'test_payment_token']);
        },
        LabelReprint::class => uspsLabelMultipart('9200190414219000000011', reprint: true),
    ]);
    $offer = ShippingOffer::factory()->direct()->awaitingConfirmation()->create([
        'carrier_account_id' => $original->id,
        'carrier_account_fingerprint' => $original->fingerprint(),
        'purchase_context' => [UspsAdapter::PURCHASE_CONTEXT_KEY => (string) Str::uuid()],
    ]);

    $response = $this->adapter->recoverPurchase(uspsOfferShipRequest($offer));

    expect($response?->success)->toBeTrue()
        ->and($response->carrierAccountId)->toBe($original->id)
        ->and($minted)->toBe(['test_crid']);
});

it('leaves the question open when the account the offer was bought on is gone or bills someone else', function (string $change): void {
    Saloon::fake(fakeUspsAuth());
    $account = CarrierAccount::query()->firstOrFail();
    $offer = ShippingOffer::factory()->direct()->awaitingConfirmation()->create([
        'carrier_account_id' => $account->id,
        'carrier_account_fingerprint' => $account->fingerprint(),
        'purchase_context' => [UspsAdapter::PURCHASE_CONTEXT_KEY => (string) Str::uuid()],
    ]);

    match ($change) {
        'deleted' => $account->delete(),
        'rebilled' => $account->update(['credentials' => ['crid' => 'someone_else', 'mid' => 'test_mid']]),
        default => throw new InvalidArgumentException($change),
    };

    // Another CRID's "not found" would be read as "nothing was bought" while
    // the original account owns a label, so nobody is asked.
    expect($this->adapter->recoverPurchase(uspsOfferShipRequest($offer->fresh())))->toBeNull();
    Saloon::assertNothingSent();
})->with(['deleted', 'rebilled']);
