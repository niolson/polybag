<?php

use App\Enums\TrackingStatus;
use App\Http\Integrations\Ups\Requests\TrackShipment;
use App\Models\Package;
use App\Services\Carriers\UpsAdapter;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

beforeEach(function (): void {
    $this->adapter = new UpsAdapter;
});

it('supports tracking', function (): void {
    expect($this->adapter->supportsTracking())->toBeTrue();
});

it('maps a UPS tracking response into normalized tracking data', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        TrackShipment::class => MockResponse::make([
            'trackResponse' => [
                'shipment' => [
                    [
                        'package' => [
                            [
                                'trackingNumber' => '1Z999AA10123456784',
                                'currentStatus' => [
                                    'description' => 'On the Way',
                                    'simplifiedTextDescription' => 'In Transit',
                                    'statusCode' => '005',
                                    'type' => 'I',
                                ],
                                'deliveryDate' => [
                                    [
                                        'type' => 'SDD',
                                        'date' => '20260415',
                                    ],
                                ],
                                'deliveryTime' => [
                                    'type' => 'CMT',
                                    'endTime' => '200000',
                                ],
                                'activity' => [
                                    [
                                        'date' => '20260413',
                                        'time' => '091500',
                                        'gmtDate' => '20260413',
                                        'gmtTime' => '161500',
                                        'gmtOffset' => '-07:00',
                                        'location' => [
                                            'address' => [
                                                'city' => 'Seattle',
                                                'stateProvince' => 'WA',
                                                'countryCode' => 'US',
                                            ],
                                        ],
                                        'status' => [
                                            'description' => 'Departed from Facility',
                                            'simplifiedTextDescription' => 'In Transit',
                                            'statusCode' => 'DP',
                                            'type' => 'I',
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

    $package = Package::factory()->shipped()->create([
        'carrier' => 'UPS',
        'tracking_number' => '1Z999AA10123456784',
    ]);

    $response = $this->adapter->trackShipment($package);

    expect($response->success)->toBeTrue()
        ->and($response->status)->toBe(TrackingStatus::InTransit)
        ->and($response->statusLabel)->toBe('On the Way')
        ->and($response->estimatedDeliveryAt?->format('Y-m-d H:i:s'))->toBe('2026-04-15 20:00:00')
        ->and($response->events)->toHaveCount(1)
        ->and($response->events[0]->description)->toBe('Departed from Facility')
        ->and($response->events[0]->location)->toBe('Seattle, WA, US');
});

it('maps UPS delivered responses into delivered tracking status', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        TrackShipment::class => MockResponse::make([
            'trackResponse' => [
                'shipment' => [
                    [
                        'package' => [
                            [
                                'currentStatus' => [
                                    'description' => 'Delivered',
                                    'simplifiedTextDescription' => 'Delivered',
                                    'statusCode' => '003',
                                    'type' => 'D',
                                ],
                                'deliveryDate' => [
                                    [
                                        'type' => 'DEL',
                                        'date' => '20260414',
                                    ],
                                ],
                                'deliveryTime' => [
                                    'type' => 'DEL',
                                    'endTime' => '134500',
                                ],
                                'activity' => [
                                    [
                                        'date' => '20260414',
                                        'time' => '134500',
                                        'location' => [
                                            'address' => [
                                                'city' => 'Los Angeles',
                                                'stateProvince' => 'CA',
                                                'countryCode' => 'US',
                                            ],
                                        ],
                                        'status' => [
                                            'description' => 'Delivered',
                                            'simplifiedTextDescription' => 'Delivered',
                                            'statusCode' => 'DEL',
                                            'type' => 'D',
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

    $package = Package::factory()->shipped()->create([
        'carrier' => 'UPS',
        'tracking_number' => '1Z999AA10123456784',
    ]);

    $response = $this->adapter->trackShipment($package);

    expect($response->success)->toBeTrue()
        ->and($response->status)->toBe(TrackingStatus::Delivered)
        ->and($response->deliveredAt?->format('Y-m-d H:i:s'))->toBe('2026-04-14 13:45:00');
});

it('maps UPS exception responses into exception tracking status', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        TrackShipment::class => MockResponse::make([
            'trackResponse' => [
                'shipment' => [
                    [
                        'package' => [
                            [
                                'currentStatus' => [
                                    'description' => 'Held for Pickup',
                                    'simplifiedTextDescription' => 'Held for Pickup',
                                    'statusCode' => 'HLD',
                                    'type' => 'X',
                                ],
                                'activity' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $package = Package::factory()->shipped()->create([
        'carrier' => 'UPS',
        'tracking_number' => '1Z999AA10123456784',
    ]);

    $response = $this->adapter->trackShipment($package);

    expect($response->success)->toBeTrue()
        ->and($response->status)->toBe(TrackingStatus::Exception);
});

it('returns failure when UPS tracking API errors', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        TrackShipment::class => MockResponse::make([
            'response' => [
                'errors' => [
                    [
                        'message' => 'Tracking number not found',
                    ],
                ],
            ],
        ], 404),
    ]);

    $package = Package::factory()->shipped()->create([
        'carrier' => 'UPS',
        'tracking_number' => '1Z999AA10123456784',
    ]);

    $response = $this->adapter->trackShipment($package);

    expect($response->success)->toBeFalse()
        ->and($response->message)->toBe('Tracking number not found');
});

it('handles non-json UPS tracking errors without crashing', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        TrackShipment::class => MockResponse::make(
            body: '<html><body>Service unavailable</body></html>',
            status: 503,
            headers: ['Content-Type' => 'text/html']
        ),
    ]);

    $package = Package::factory()->shipped()->create([
        'carrier' => 'UPS',
        'tracking_number' => '1Z999AA10123456784',
    ]);

    $response = $this->adapter->trackShipment($package);

    expect($response->success)->toBeFalse()
        ->and($response->message)->toContain('Response')
        ->and(data_get($response->details, 'raw.body'))->toContain('Service unavailable');
});
