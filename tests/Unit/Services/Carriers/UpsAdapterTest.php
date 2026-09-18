<?php

use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\CustomsItem;
use App\DataTransferObjects\Shipping\PackageData;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\Enums\CarrierPackaging;
use App\Enums\TrackingStatus;
use App\Exceptions\Carriers\CarrierRateFetchException;
use App\Exceptions\Carriers\UnclassifiablePackagingException;
use App\Http\Integrations\Ups\Requests\CreateShipment;
use App\Http\Integrations\Ups\Requests\LabelRecovery;
use App\Http\Integrations\Ups\Requests\Rate;
use App\Http\Integrations\Ups\Requests\TrackShipment;
use App\Models\BoxSize;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\CarrierAccountScope;
use App\Models\Client;
use App\Models\Package;
use App\Models\ShippingOffer;
use App\Services\Carriers\UpsAdapter;
use Carbon\CarbonImmutable;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Exceptions\Request\ServerException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;

beforeEach(function (): void {
    $this->adapter = new UpsAdapter;
    createUpsAccount();
});

it('returns false when only an empty active account exists', function (): void {
    $carrierId = CarrierAccount::query()->firstOrFail()->carrier_id;
    CarrierAccount::query()->delete();
    CarrierAccount::factory()->create([
        'carrier_id' => $carrierId,
        'credentials' => null,
        'secret_credentials' => null,
    ]);

    expect($this->adapter->isConfigured())->toBeFalse();
});

it('supports tracking', function (): void {
    expect($this->adapter->supportsTracking())->toBeTrue();
});

it('prepares UPS rates without keeping account state on the adapter', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Rate::class => MockResponse::make(['RateResponse' => ['RatedShipment' => []]]),
    ]);

    CarrierAccount::query()->delete();

    $carrier = Carrier::firstOrCreate(['name' => 'UPS']);
    $firstClient = Client::factory()->create();
    $secondClient = Client::factory()->create();

    $firstAccount = CarrierAccount::factory()->create([
        'carrier_id' => $carrier->id,
        'credentials' => ['account_number' => 'first_account'],
        'secret_credentials' => ['client_id' => 'first_key', 'client_secret' => 'first_secret'],
    ]);
    $secondAccount = CarrierAccount::factory()->create([
        'carrier_id' => $carrier->id,
        'credentials' => ['account_number' => 'second_account'],
        'secret_credentials' => ['client_id' => 'second_key', 'client_secret' => 'second_secret'],
    ]);

    CarrierAccountScope::factory()->forAccount($firstAccount)->clientScoped($firstClient)->create();
    CarrierAccountScope::factory()->forAccount($secondAccount)->clientScoped($secondClient)->create();

    $this->adapter->getRates(rateRequestForClient($firstClient->id), ['03']);
    $this->adapter->getRates(rateRequestForClient($secondClient->id), ['03']);

    expect((new ReflectionClass($this->adapter))->hasProperty('currentAccount'))->toBeFalse();
});

it('throws CarrierRateFetchException when the UPS rate API fails', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Rate::class => MockResponse::make(['errors' => [['message' => 'Internal Server Error']]], 500),
    ]);

    $request = new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: '90210',
        packages: [new PackageData(weight: 5.0, length: 12, width: 10, height: 8)],
    );

    expect(fn () => $this->adapter->getRates($request, ['03']))
        ->toThrow(CarrierRateFetchException::class, 'Failed to fetch rates from UPS');
});

it('wraps the original exception as previous when rate fetch fails', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Rate::class => MockResponse::make(['errors' => [['message' => 'Service Unavailable']]], 503),
    ]);

    $request = new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: '90210',
        packages: [new PackageData(weight: 5.0, length: 12, width: 10, height: 8)],
    );

    try {
        $this->adapter->getRates($request, ['03']);
        $this->fail('Expected CarrierRateFetchException was not thrown');
    } catch (CarrierRateFetchException $e) {
        expect($e->carrier)->toBe('UPS')
            ->and($e->getPrevious())->toBeInstanceOf(RequestException::class);
    }
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

it('falls back to the UPS summary delivery date when no delivered scan event exists', function (): void {
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
                                    ['type' => 'DEL', 'date' => '20260414'],
                                ],
                                'deliveryTime' => [
                                    'type' => 'DEL',
                                    'endTime' => '134500',
                                ],
                                // Only a non-delivered scan event: deliveredAt must
                                // come from the summary deliveryDate[DEL] fallback.
                                'activity' => [
                                    [
                                        'date' => '20260413',
                                        'time' => '090000',
                                        'status' => [
                                            'description' => 'Origin Scan',
                                            'statusCode' => 'OR',
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
        'tracking_number' => '1Z999AA10123456785',
    ]);

    $response = $this->adapter->trackShipment($package);

    expect($response->status)->toBe(TrackingStatus::Delivered)
        ->and($response->deliveredAt?->format('Y-m-d H:i:s'))->toBe('2026-04-14 13:45:00');
});

it('defers to the UPS summary delivery date when the delivered scan event has no timestamp', function (): void {
    // Aligns UPS with the bug #1 fix: a delivered scan event that carries no
    // parseable timestamp must not short-circuit deliveredAt to null — the
    // shared resolveDeliveredAt template falls through to the summary fallback.
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
                                    'statusCode' => '003',
                                    'type' => 'D',
                                ],
                                'deliveryDate' => [
                                    ['type' => 'DEL', 'date' => '20260414'],
                                ],
                                'deliveryTime' => [
                                    'type' => 'DEL',
                                    'endTime' => '134500',
                                ],
                                // Delivered scan event, but no date/time -> null timestamp.
                                'activity' => [
                                    [
                                        'status' => [
                                            'description' => 'Delivered',
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
        'tracking_number' => '1Z999AA10123456786',
    ]);

    $response = $this->adapter->trackShipment($package);

    expect($response->status)->toBe(TrackingStatus::Delivered)
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

function upsSpecialServiceShipRequest(array $codes, array $config = [], array $references = []): ShipRequest
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
            carrier: 'UPS',
            serviceCode: '03',
            serviceName: 'UPS Ground',
            price: 11.00,
            metadata: ['serviceCode' => '03'],
        ),
        specialServiceCodes: $codes,
        specialServiceConfig: $config,
        references: $references,
    );
}

/**
 * A ship request carrying a label reference, addressed to the given destination.
 *
 * @param  array<int, CustomsItem>  $customsItems
 * @param  AddressData|null  $fromAddress  Origin, a Seattle warehouse unless the lane under test needs another
 * @param  array<string, mixed>  $rateMetadata  The selected rate's metadata, as UpsAdapter::extractRateDetails() stamps it
 */
function upsShipRequestTo(AddressData $toAddress, string $reference = 'ORD-10042', array $customsItems = [], array $specialServiceCodes = [], ?AddressData $fromAddress = null, array $rateMetadata = ['serviceCode' => '03']): ShipRequest
{
    return new ShipRequest(
        fromAddress: $fromAddress ?? new AddressData(
            firstName: 'Shipping',
            lastName: 'Center',
            streetAddress: '123 Warehouse St',
            city: 'Seattle',
            stateOrProvince: 'WA',
            postalCode: '98072',
        ),
        toAddress: $toAddress,
        packageData: new PackageData(weight: 2.0, length: 10, width: 8, height: 4),
        selectedRate: new RateResponse(
            carrier: 'UPS',
            serviceCode: '03',
            serviceName: 'UPS Ground',
            price: 11.00,
            metadata: $rateMetadata,
        ),
        references: [$reference],
        customsItems: $customsItems,
        specialServiceCodes: $specialServiceCodes,
    );
}

/**
 * The customs items an international ship request needs before UpsAdapter will
 * build InternationalForms at all.
 *
 * @return array<int, CustomsItem>
 */
function upsCustomsItems(): array
{
    return [new CustomsItem(
        description: 'Blue Widget',
        quantity: 2,
        unitValue: 19.99,
        weight: 0.5,
        hsTariffNumber: '9503.00.0090',
        countryOfOrigin: 'US',
    )];
}

/**
 * A Canadian destination, for the international paths.
 */
function upsCanadianAddress(): AddressData
{
    return new AddressData(
        firstName: 'Jean',
        lastName: 'Tremblay',
        streetAddress: '100 Queen St W',
        city: 'Toronto',
        stateOrProvince: 'ON',
        postalCode: 'M5H 2N2',
        country: 'CA',
    );
}

/**
 * A Canadian origin, for lanes that do not start in the fifty states.
 */
function upsCanadianOrigin(): AddressData
{
    return new AddressData(
        firstName: 'Shipping',
        lastName: 'Centre',
        streetAddress: '200 Bay St',
        city: 'Toronto',
        stateOrProvince: 'ON',
        postalCode: 'M5J 2J2',
        country: 'CA',
    );
}

/**
 * Whether the UPS ship request that was sent asked for a customs invoice.
 */
function upsSentInternationalForms(): bool
{
    $sent = null;

    Saloon::assertSent(function ($request) use (&$sent): bool {
        if (! $request instanceof CreateShipment) {
            return false;
        }

        $sent = $request->body()->all()['ShipmentRequest']['Shipment'];

        return true;
    });

    return isset($sent['ShipmentServiceOptions']['InternationalForms']);
}

function fakeUpsShipEndpoints(): void
{
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        CreateShipment::class => MockResponse::make([
            'ShipmentResponse' => [
                'ShipmentResults' => [
                    'ShipmentIdentificationNumber' => '1Z9999999999999999',
                    'ShipmentCharges' => [
                        'TotalCharges' => ['MonetaryValue' => '11.00'],
                    ],
                    'PackageResults' => [
                        'TrackingNumber' => '1Z9999999999999999',
                        'ShippingLabel' => ['GraphicImage' => 'R0lGODlhAQABAAAAACw='],
                    ],
                ],
            ],
        ]),
    ]);
}

it('puts the label reference on the package, where UPS prints it for domestic shipments', function (): void {
    fakeUpsShipEndpoints();

    expect($this->adapter->createShipment(upsSpecialServiceShipRequest([], [], ['ORD-10042']))->success)->toBeTrue();

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof CreateShipment) {
            return false;
        }

        $shipment = $request->body()->all()['ShipmentRequest']['Shipment'];

        return ($shipment['Package'][0]['ReferenceNumber'] ?? null) === [
            ['Code' => 'TN', 'Value' => 'ORD-10042'],
        ]
            && ! array_key_exists('ReferenceNumber', $shipment);
    });
});

it('moves the label reference to the shipment for lanes UPS will not take it on the package', function (array $destination): void {
    fakeUpsShipEndpoints();

    expect($this->adapter->createShipment(upsShipRequestTo(new AddressData(...$destination)))->success)->toBeTrue();

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof CreateShipment) {
            return false;
        }

        $shipment = $request->body()->all()['ShipmentRequest']['Shipment'];

        return ($shipment['ReferenceNumber'] ?? null) === [['Code' => 'TN', 'Value' => 'ORD-10042']]
            && ! array_key_exists('ReferenceNumber', $shipment['Package'][0]);
    });
})->with([
    'international' => [[
        'firstName' => 'Jean',
        'lastName' => 'Tremblay',
        'streetAddress' => '100 Queen St W',
        'city' => 'Toronto',
        'stateOrProvince' => 'ON',
        'postalCode' => 'M5H 2N2',
        'country' => 'CA',
    ]],
    // Country code US on both ends, but US↔PR is not one domestic area to UPS.
    'puerto rico' => [[
        'firstName' => 'Ana',
        'lastName' => 'Rivera',
        'streetAddress' => '1 Calle Fortaleza',
        'city' => 'San Juan',
        'stateOrProvince' => 'PR',
        'postalCode' => '00901',
        'country' => 'US',
    ]],
]);

it('keeps the label reference on the package for shipments inside Puerto Rico', function (): void {
    fakeUpsShipEndpoints();

    $request = new ShipRequest(
        fromAddress: new AddressData(
            firstName: 'Shipping',
            lastName: 'Center',
            streetAddress: '500 Ave Ponce de Leon',
            city: 'San Juan',
            stateOrProvince: 'PR',
            postalCode: '00901',
        ),
        toAddress: new AddressData(
            firstName: 'Ana',
            lastName: 'Rivera',
            streetAddress: '1 Calle Fortaleza',
            city: 'Ponce',
            stateOrProvince: 'PR',
            postalCode: '00716',
        ),
        packageData: new PackageData(weight: 2.0, length: 10, width: 8, height: 4),
        selectedRate: new RateResponse(
            carrier: 'UPS',
            serviceCode: '03',
            serviceName: 'UPS Ground',
            price: 11.00,
            metadata: ['serviceCode' => '03'],
        ),
        references: ['ORD-10042'],
    );

    expect($this->adapter->createShipment($request)->success)->toBeTrue();

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof CreateShipment) {
            return false;
        }

        $shipment = $request->body()->all()['ShipmentRequest']['Shipment'];

        return ($shipment['Package'][0]['ReferenceNumber'] ?? null) === [['Code' => 'TN', 'Value' => 'ORD-10042']]
            && ! array_key_exists('ReferenceNumber', $shipment);
    });
});

it('sends the recipient phone and attention name on the label request', function (): void {
    fakeUpsShipEndpoints();

    $request = new ShipRequest(
        fromAddress: new AddressData(
            firstName: 'Shipping',
            lastName: 'Center',
            streetAddress: '123 Warehouse St',
            city: 'Seattle',
            stateOrProvince: 'WA',
            postalCode: '98072',
            company: 'PolyBag Fulfillment',
            phone: '4255551234',
        ),
        toAddress: new AddressData(
            firstName: 'Kenji',
            lastName: 'Sato',
            streetAddress: '4 Chome-2-8 Shibakoen',
            city: 'Minato City',
            stateOrProvince: 'TOKYO',
            postalCode: '105-0011',
            country: 'JP',
            // What carrierDigits() yields for +81-3-3433-5111: the national
            // number, with the country code stripped.
            phone: '334335111',
            phoneExtension: '22',
        ),
        packageData: new PackageData(weight: 0.1, length: 10, width: 8, height: 4),
        selectedRate: new RateResponse(
            carrier: 'UPS',
            serviceCode: '07',
            serviceName: 'UPS Worldwide Express',
            price: 61.00,
            metadata: ['serviceCode' => '07'],
        ),
    );

    expect($this->adapter->createShipment($request)->success)->toBeTrue();

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof CreateShipment) {
            return false;
        }

        $shipment = $request->body()->all()['ShipmentRequest']['Shipment'];

        return ($shipment['ShipTo']['Phone'] ?? null) === ['Number' => '334335111', 'Extension' => '22']
            && ($shipment['ShipTo']['AttentionName'] ?? null) === 'Kenji Sato'
            && ($shipment['Shipper']['Phone'] ?? null) === ['Number' => '4255551234']
            && ($shipment['Shipper']['AttentionName'] ?? null) === 'Shipping Center';
    });
});

it('falls back to the company when an address names no person to ask for', function (): void {
    fakeUpsShipEndpoints();

    $request = new ShipRequest(
        fromAddress: new AddressData(
            firstName: '',
            lastName: '',
            streetAddress: '123 Warehouse St',
            city: 'Seattle',
            stateOrProvince: 'WA',
            postalCode: '98072',
            company: 'PolyBag Fulfillment',
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
            carrier: 'UPS',
            serviceCode: '03',
            serviceName: 'UPS Ground',
            price: 11.00,
            metadata: ['serviceCode' => '03'],
        ),
    );

    expect($this->adapter->createShipment($request)->success)->toBeTrue();

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof CreateShipment) {
            return false;
        }

        $shipment = $request->body()->all()['ShipmentRequest']['Shipment'];

        // No phone on either address, so UPS gets no empty container to reject.
        return ($shipment['Shipper']['AttentionName'] ?? null) === 'PolyBag Fulfillment'
            && ! array_key_exists('Phone', $shipment['Shipper'])
            && ! array_key_exists('Phone', $shipment['ShipTo']);
    });
});

it('sends no reference number when the client prints none', function (): void {
    fakeUpsShipEndpoints();

    expect($this->adapter->createShipment(upsSpecialServiceShipRequest([]))->success)->toBeTrue();

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof CreateShipment) {
            return false;
        }

        return ! array_key_exists('ReferenceNumber', $request->body()->all()['ShipmentRequest']['Shipment']['Package'][0]);
    });
});

it('maps delivery confirmation and declared value into the ship request', function (): void {
    fakeUpsShipEndpoints();

    $response = $this->adapter->createShipment(upsSpecialServiceShipRequest(
        ['adult_signature_required', 'declared_value'],
        ['declared_value' => ['amount' => 1250.50, 'currency' => 'USD']],
    ));

    expect($response->success)->toBeTrue()
        ->and($response->appliedServices)->toBe(['adult_signature_required', 'declared_value']);

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof CreateShipment) {
            return false;
        }

        $options = $request->body()->all()['ShipmentRequest']['Shipment']['Package'][0]['PackageServiceOptions'] ?? [];

        // Package-level DCIS code set: 3 = adult signature
        return ($options['DeliveryConfirmation']['DCISType'] ?? null) === '3'
            && ($options['DeclaredValue']['CurrencyCode'] ?? null) === 'USD'
            && ($options['DeclaredValue']['MonetaryValue'] ?? null) === '1250.50';
    });
});

it('uses DCIS type 2 for standard signature and sends no options for unwired codes', function (): void {
    fakeUpsShipEndpoints();

    $response = $this->adapter->createShipment(upsSpecialServiceShipRequest(
        ['signature_required', 'lithium_battery_in_equipment'],
    ));

    // Section II in-equipment batteries ship with no UPS API declaration —
    // only the signature option appears in the payload
    expect($response->success)->toBeTrue()
        ->and($response->appliedServices)->toBe(['signature_required']);

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof CreateShipment) {
            return false;
        }

        $options = $request->body()->all()['ShipmentRequest']['Shipment']['Package'][0]['PackageServiceOptions'] ?? [];

        return ($options['DeliveryConfirmation']['DCISType'] ?? null) === '2'
            && ! array_key_exists('DeclaredValue', $options)
            && ! array_key_exists('HazMat', $options);
    });
});

it('sends a fully qualified origin on rate requests so international lanes resolve', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Rate::class => MockResponse::make(['RateResponse' => ['RatedShipment' => []]]),
    ]);

    $this->adapter->getRates(new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: 'M5H 2N2',
        destinationCountry: 'CA',
        destinationCity: 'Toronto',
        destinationStateOrProvince: 'ON',
        packages: [new PackageData(weight: 2.0, length: 10, width: 8, height: 4)],
        originCity: 'Woodinville',
        originStateOrProvince: 'WA',
    ), ['07']);

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof Rate) {
            return false;
        }

        $shipment = $request->body()->all()['RateRequest']['Shipment'];
        $origin = [
            'City' => 'Woodinville',
            'StateProvinceCode' => 'WA',
            'PostalCode' => '98072',
            'CountryCode' => 'US',
        ];

        return $shipment['Shipper']['Address'] === $origin
            && $shipment['ShipFrom']['Address'] === $origin;
    });
});

it('declares the contents value on rate requests that leave the origin country', function (array $destination): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Rate::class => MockResponse::make(['RateResponse' => ['RatedShipment' => []]]),
    ]);

    $this->adapter->getRates(new RateRequest(
        originPostalCode: '98072',
        packages: [new PackageData(weight: 2.0, length: 10, width: 8, height: 4)],
        contentsValue: 18.03,
        destinationPostalCode: $destination['destinationPostalCode'],
        destinationCountry: $destination['destinationCountry'],
        destinationStateOrProvince: $destination['destinationStateOrProvince'] ?? null,
    ), ['03']);

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof Rate) {
            return false;
        }

        return ($request->body()->all()['RateRequest']['Shipment']['InvoiceLineTotal'] ?? null) === [
            'CurrencyCode' => 'USD',
            'MonetaryValue' => '18.03',
        ];
    });
})->with([
    // The lane that surfaced 111549.
    'japan' => [['destinationPostalCode' => '105-0011', 'destinationCountry' => 'JP', 'destinationStateOrProvince' => 'TOKYO']],
    'canada' => [['destinationPostalCode' => 'M5H 2N2', 'destinationCountry' => 'CA']],
    'puerto rico as its own country' => [['destinationPostalCode' => '00926', 'destinationCountry' => 'PR']],
    // The same destination as imported by a source that files PR under US.
    'puerto rico under US' => [[
        'destinationPostalCode' => '00926',
        'destinationCountry' => 'US',
        'destinationStateOrProvince' => 'PR',
    ]],
]);

it('sends the shipment total weight on international rate requests', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Rate::class => MockResponse::make(['RateResponse' => ['RatedShipment' => []]]),
    ]);

    $this->adapter->getRates(new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: '105-0011',
        destinationCountry: 'JP',
        destinationStateOrProvince: 'TOKYO',
        packages: [new PackageData(weight: 0.1, length: 10, width: 8, height: 4)],
        contentsValue: 400.00,
    ), ['07']);

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof Rate) {
            return false;
        }

        return ($request->body()->all()['RateRequest']['Shipment']['ShipmentTotalWeight'] ?? null) === [
            'UnitOfMeasurement' => ['Code' => 'LBS'],
            'Weight' => '0.1',
        ];
    });
});

it('leaves the shipment total weight off domestic rate requests', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Rate::class => MockResponse::make(['RateResponse' => ['RatedShipment' => []]]),
    ]);

    $this->adapter->getRates(new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: '90210',
        packages: [new PackageData(weight: 2.0, length: 10, width: 8, height: 4)],
    ), ['03']);

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof Rate) {
            return false;
        }

        return ! array_key_exists('ShipmentTotalWeight', $request->body()->all()['RateRequest']['Shipment']);
    });
});

it('leaves the contents value off domestic rate requests and off shipments with no value', function (?float $contentsValue, string $destinationCountry): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Rate::class => MockResponse::make(['RateResponse' => ['RatedShipment' => []]]),
    ]);

    $this->adapter->getRates(new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: '90210',
        destinationCountry: $destinationCountry,
        packages: [new PackageData(weight: 2.0, length: 10, width: 8, height: 4)],
        contentsValue: $contentsValue,
    ), ['03']);

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof Rate) {
            return false;
        }

        return ! array_key_exists('InvoiceLineTotal', $request->body()->all()['RateRequest']['Shipment']);
    });
})->with([
    'domestic with a value' => [18.03, 'US'],
    'international with no value' => [null, 'CA'],
    'international with a zero value' => [0.0, 'CA'],
]);

it('omits origin fields the location does not have rather than sending blanks', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Rate::class => MockResponse::make(['RateResponse' => ['RatedShipment' => []]]),
    ]);

    $this->adapter->getRates(new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: '90210',
        packages: [new PackageData(weight: 2.0, length: 10, width: 8, height: 4)],
    ), ['03']);

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof Rate) {
            return false;
        }

        return $request->body()->all()['RateRequest']['Shipment']['Shipper']['Address'] === [
            'PostalCode' => '98072',
            'CountryCode' => 'US',
        ];
    });
});

it('includes package service options in the rating request', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Rate::class => MockResponse::make(['RateResponse' => ['RatedShipment' => []]]),
    ]);

    $request = new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: '90210',
        packages: [new PackageData(weight: 2.0, length: 10, width: 8, height: 4)],
        specialServiceCodes: ['signature_required', 'declared_value'],
        specialServiceConfig: ['declared_value' => ['amount' => 300.00, 'currency' => 'USD']],
    );

    $this->adapter->getRates($request, ['03']);

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof Rate) {
            return false;
        }

        $options = $request->body()->all()['RateRequest']['Shipment']['Package']['PackageServiceOptions'] ?? [];

        return ($options['DeliveryConfirmation']['DCISType'] ?? null) === '2'
            && ($options['DeclaredValue']['MonetaryValue'] ?? null) === '300.00';
    });
});

/*
|--------------------------------------------------------------------------
| Request schema conformance
|--------------------------------------------------------------------------
|
| The assertions above check individual fields we care about. These check the
| whole body against UPS's own published OpenAPI schemas, so a field we rename,
| mistype, or drop fails here rather than at the workstation. See
| tests/Fixtures/Schemas/README.md.
|
*/

function fakeUpsRateEndpoints(): void
{
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Rate::class => MockResponse::make(['RateResponse' => ['RatedShipment' => []]]),
    ]);
}

it('builds a rate request that conforms to the UPS Rating schema', function (): void {
    fakeUpsRateEndpoints();

    $this->adapter->getRates(rateRequestForClient(Client::query()->firstOrFail()->id), ['03']);

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof Rate) {
            return false;
        }

        assertMatchesUpsSchema($request->body()->all(), 'RATERequestWrapper', 'upsRating');

        return true;
    });
})->skip('Rating.yaml describes a stricter contract than the JSON API enforces — see the note below.');

it('builds a cross-border rate request that conforms to the UPS Rating schema', function (): void {
    fakeUpsRateEndpoints();

    $this->adapter->getRates(new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: 'M5H 2N2',
        destinationCountry: 'CA',
        destinationCity: 'Toronto',
        destinationStateOrProvince: 'ON',
        residential: true,
        packages: [new PackageData(weight: 2.0, length: 10, width: 8, height: 4)],
        originCity: 'Woodinville',
        originStateOrProvince: 'WA',
        contentsValue: 125.00,
    ), ['07']);

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof Rate) {
            return false;
        }

        assertMatchesUpsSchema($request->body()->all(), 'RATERequestWrapper', 'upsRating');

        return true;
    });
})->skip('Rating.yaml describes a stricter contract than the JSON API enforces — see the note below.');

/*
| Why the two rating tests above are skipped
|
| Validated against Rating.yaml, our rate body reports four gaps. None of them
| stop UPS returning rates today, so none were changed here — flipping the
| request shape to satisfy a spec the live API does not enforce is a product
| decision, not a test fix:
|
|   1. Request.RequestOption is required. We pass the request option in the URL
|      instead (/api/rating/v2403/Shoptimeintransit).
|   2. Shipper.Address.AddressLine and ShipTo.Address.AddressLine are required.
|      We rate on postal code alone, which UPS accepts.
|   3. ShipmentTotalWeight.UnitOfMeasurement.Description is required. We send
|      Code without Description.
|   4. Shipment.Package must be an array. buildRateApiRequest() sends a single
|      object, while sendCreateShipment() correctly sends an array. UPS tolerates
|      both, and the rate path only ever rates packages[0] anyway — but the two
|      paths in our own adapter disagree with each other.
|
| (4) is the one worth acting on; the rest are the spec over-describing the XML
| contract. Delete the skips once the rate body is settled.
*/

it('builds a label request that conforms to the UPS Shipping schema', function (): void {
    fakeUpsShipEndpoints();

    expect($this->adapter->createShipment(upsSpecialServiceShipRequest([], [], ['ORD-10042']))->success)->toBeTrue();

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof CreateShipment) {
            return false;
        }

        assertMatchesUpsSchema($request->body()->all(), 'SHIPRequestWrapper', 'upsShipping');

        return true;
    });
});

it('builds an international label request that conforms to the UPS Shipping schema', function (): void {
    fakeUpsShipEndpoints();

    $request = upsShipRequestTo(upsCanadianAddress(), customsItems: upsCustomsItems());

    expect($this->adapter->createShipment($request)->success)->toBeTrue();

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof CreateShipment) {
            return false;
        }

        assertMatchesUpsSchema($request->body()->all(), 'SHIPRequestWrapper', 'upsShipping');

        return true;
    });
});

/*
| Schema conformance cannot catch this on its own. UPS's OpenAPI leaves
| additionalProperties unset on ShipmentRequest_Shipment, so InternationalForms
| sent one level too high validates clean and is then ignored by UPS — which is
| how every international shipment went out without a commercial invoice being
| requested at all. These assert the placement directly.
*/

it('nests InternationalForms inside ShipmentServiceOptions, where UPS defines it', function (): void {
    fakeUpsShipEndpoints();

    expect($this->adapter->createShipment(
        upsShipRequestTo(upsCanadianAddress(), customsItems: upsCustomsItems())
    )->success)->toBeTrue();

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof CreateShipment) {
            return false;
        }

        $shipment = $request->body()->all()['ShipmentRequest']['Shipment'];

        expect($shipment)->not->toHaveKey('InternationalForms')
            ->and($shipment['ShipmentServiceOptions']['InternationalForms']['FormType'])->toBe(['01'])
            ->and($shipment['ShipmentServiceOptions']['InternationalForms']['Product'][0]['Description'])->toBe(['Blue Widget']);

        return true;
    });
});

/*
| Whether a declaration goes out is a question about the pair of addresses,
| and the answer has to match the workflow's: it reconciles customs weights
| for every cross-zone lane, and a lane it reconciles for but UPS then ships
| without a declaration would buy a label that clears no customs. The mock
| carrier in the workflow tests cannot see this, so it is pinned here.
*/

it('sends InternationalForms from a Canadian origin into the US, which the destination alone calls domestic', function (): void {
    fakeUpsShipEndpoints();

    $toPortland = new AddressData(
        firstName: 'John',
        lastName: 'Doe',
        streetAddress: '456 Main St',
        city: 'Portland',
        stateOrProvince: 'OR',
        postalCode: '97201',
    );

    expect($this->adapter->createShipment(
        upsShipRequestTo($toPortland, customsItems: upsCustomsItems(), fromAddress: upsCanadianOrigin())
    )->success)->toBeTrue()
        ->and(upsSentInternationalForms())->toBeTrue();
});

it('sends no InternationalForms on a lane that stays inside Canada', function (): void {
    fakeUpsShipEndpoints();

    expect($this->adapter->createShipment(
        upsShipRequestTo(upsCanadianAddress(), customsItems: upsCustomsItems(), fromAddress: upsCanadianOrigin())
    )->success)->toBeTrue()
        ->and(upsSentInternationalForms())->toBeFalse();
});

it('names the buyer on the customs invoice, which UPS requires for an Invoice form', function (): void {
    fakeUpsShipEndpoints();

    expect($this->adapter->createShipment(
        upsShipRequestTo(upsCanadianAddress(), customsItems: upsCustomsItems())
    )->success)->toBeTrue();

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof CreateShipment) {
            return false;
        }

        $soldTo = $request->body()->all()['ShipmentRequest']['Shipment']['ShipmentServiceOptions']['InternationalForms']['Contacts']['SoldTo'];

        // UPS answers a missing SoldTo with 9120800 "Missing contact information",
        // and its own schema does not mark Contacts required, so only this catches it.
        expect($soldTo['Name'])->toBe('Jean Tremblay')
            ->and($soldTo['AttentionName'])->toBe('Jean Tremblay')
            ->and($soldTo['Address']['City'])->toBe('Toronto')
            ->and($soldTo['Address']['CountryCode'])->toBe('CA');

        return true;
    });
});

it('declares the invoice line total, which UPS requires beside an Invoice form', function (): void {
    fakeUpsShipEndpoints();

    expect($this->adapter->createShipment(
        upsShipRequestTo(upsCanadianAddress(), customsItems: upsCustomsItems())
    )->success)->toBeTrue();

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof CreateShipment) {
            return false;
        }

        $shipment = $request->body()->all()['ShipmentRequest']['Shipment'];

        // Two Blue Widgets at 19.99. UPS refuses a zero or absent total with
        // 120502, and cross-checks it against the invoice's own lines.
        expect($shipment['InvoiceLineTotal'])->toBe([
            'CurrencyCode' => 'USD',
            'MonetaryValue' => '39.98',
        ]);

        return true;
    });
});

it('prices a customs line per unit, not per line, so UPS totals it correctly', function (): void {
    fakeUpsShipEndpoints();

    expect($this->adapter->createShipment(
        upsShipRequestTo(upsCanadianAddress(), customsItems: upsCustomsItems())
    )->success)->toBeTrue();

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof CreateShipment) {
            return false;
        }

        $shipment = $request->body()->all()['ShipmentRequest']['Shipment'];
        $unit = $shipment['ShipmentServiceOptions']['InternationalForms']['Product'][0]['Unit'];

        // UPS prints Value as "Unit Value" and multiplies it by Number to get the
        // line's Total Value. Sending the extended total here made a real invoice
        // declare 2 x 39.98 = 79.96 for goods worth 39.98.
        expect($unit['Number'])->toBe('2')
            ->and($unit['Value'])->toBe('19.99');

        // And UPS's own arithmetic over the lines has to land on the total we
        // declare on the shipment, or the invoice contradicts itself.
        $linesTotal = array_sum(array_map(
            fn (array $product): float => (float) $product['Unit']['Number'] * (float) $product['Unit']['Value'],
            $shipment['ShipmentServiceOptions']['InternationalForms']['Product'],
        ));

        expect(number_format($linesTotal, 2, '.', ''))->toBe($shipment['InvoiceLineTotal']['MonetaryValue']);

        return true;
    });
});

it('sends no invoice line total on a domestic label, which carries no invoice', function (): void {
    fakeUpsShipEndpoints();

    expect($this->adapter->createShipment(upsSpecialServiceShipRequest([]))->success)->toBeTrue();

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof CreateShipment) {
            return false;
        }

        expect($request->body()->all()['ShipmentRequest']['Shipment'])->not->toHaveKey('InvoiceLineTotal');

        return true;
    });
});

it('keeps the customs document UPS returns beside the label', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        CreateShipment::class => MockResponse::make([
            'ShipmentResponse' => [
                'ShipmentResults' => [
                    'ShipmentIdentificationNumber' => '1Z9999999999999999',
                    'ShipmentCharges' => ['TotalCharges' => ['MonetaryValue' => '11.00']],
                    // UPS returns the invoice separately from the label, as its own
                    // PDF, even when the label itself is a GIF.
                    'Form' => [
                        'Code' => '01',
                        'Description' => 'All Requested International Forms',
                        'Image' => [
                            'ImageFormat' => ['Code' => 'PDF', 'Description' => 'PDF'],
                            'GraphicImage' => base64_encode('customs-invoice-pdf'),
                        ],
                    ],
                    'PackageResults' => [
                        'TrackingNumber' => '1Z9999999999999999',
                        'ShippingLabel' => ['GraphicImage' => base64_encode('label-bytes')],
                    ],
                ],
            ],
        ]),
    ]);

    $response = $this->adapter->createShipment(
        upsShipRequestTo(upsCanadianAddress(), customsItems: upsCustomsItems())
    );

    expect($response->success)->toBeTrue()
        ->and($response->customsFormData)->toBe(base64_encode('customs-invoice-pdf'));
});

it('leaves the customs document null when UPS returns no form', function (): void {
    fakeUpsShipEndpoints();

    $response = $this->adapter->createShipment(upsSpecialServiceShipRequest([]));

    expect($response->success)->toBeTrue()
        ->and($response->customsFormData)->toBeNull();
});

it('keeps the customs invoice when the selected rate is a Saturday quote', function (): void {
    fakeUpsShipEndpoints();

    expect($this->adapter->createShipment(upsShipRequestTo(
        upsCanadianAddress(),
        customsItems: upsCustomsItems(),
        specialServiceCodes: ['saturday_delivery'],
        rateMetadata: ['serviceCode' => '03', 'saturday_delivery' => true],
    ))->success)->toBeTrue();

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof CreateShipment) {
            return false;
        }

        $options = $request->body()->all()['ShipmentRequest']['Shipment']['ShipmentServiceOptions'];

        expect($options)->toHaveKeys(['SaturdayDeliveryIndicator', 'InternationalForms']);

        return true;
    });
});

/*
|--------------------------------------------------------------------------
| Saturday delivery — read off the rate response, not a calendar
|--------------------------------------------------------------------------
|
| A shop request on a Friday returns a service twice when it can reach
| Saturday: a Saturday row and a weekday row, told apart only by
| TimeInTransit.ServiceSummary.SaturdayDelivery. Sending the indicator
| filters the response to the Saturday rows; it is never an error. So the
| adapter sends one request, keeps the rows matching what was asked, tags
| the Saturday ones, and at label time sends the indicator for a tagged
| rate only. shopify-shipping-carrier/25, from a CIE capture of 2026-09-18.
|
*/

/**
 * One rated shipment as a Friday shop response lists it.
 *
 * @return array<string, mixed>
 */
function upsRatedShipment(string $serviceCode, string $price, bool $saturday, string $arrival): array
{
    return [
        'Service' => ['Code' => $serviceCode],
        'TotalCharges' => ['CurrencyCode' => 'USD', 'MonetaryValue' => $price],
        'TimeInTransit' => [
            'PickupDate' => '20260918',
            'ServiceSummary' => [
                'EstimatedArrival' => [
                    'Arrival' => ['Date' => $arrival, 'Time' => '103000'],
                    'BusinessDaysInTransit' => '1',
                    'DayOfWeek' => $saturday ? 'SAT' : 'MON',
                ],
                'SaturdayDelivery' => $saturday ? '1' : '0',
                'SundayDelivery' => '0',
            ],
        ],
        ...($saturday ? ['ItemizedCharges' => [['Code' => '300', 'CurrencyCode' => 'USD', 'MonetaryValue' => '20.96']]] : []),
    ];
}

/**
 * The Friday capture, cut to the rows the assertions need: Next Day Air
 * twice, 3 Day Select once.
 *
 * @return array<int, array<string, mixed>>
 */
function upsFridayShopResponse(): array
{
    return [
        upsRatedShipment('01', '87.17', saturday: true, arrival: '20260919'),
        upsRatedShipment('01', '66.21', saturday: false, arrival: '20260921'),
        upsRatedShipment('12', '27.90', saturday: false, arrival: '20260923'),
    ];
}

function upsFridayRateRequest(array $specialServiceCodes = []): RateRequest
{
    return new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: '90210',
        packages: [new PackageData(weight: 5.0, length: 12, width: 10, height: 8)],
        specialServiceCodes: $specialServiceCodes,
        shipDate: CarbonImmutable::parse('2026-09-18'),
    );
}

it('drops the Saturday rows when Saturday delivery was not requested', function (): void {
    fakeUpsRateEndpointsQuoting(upsFridayShopResponse());

    $rates = $this->adapter->getRates(upsFridayRateRequest(), ['01', '12']);

    expect($rates->map(fn (RateResponse $rate): array => [$rate->serviceCode, $rate->price, $rate->deliveryDate])->all())
        ->toBe([['01', 66.21, '2026-09-21'], ['12', 27.90, '2026-09-23']])
        ->and($rates->pluck('metadata')->filter(fn (array $metadata): bool => array_key_exists('saturday_delivery', $metadata)))->toBeEmpty()
        ->and(sentUpsRatePackages())->toHaveCount(1);

    Saloon::assertSent(fn ($request): bool => $request instanceof Rate
        && ! isset($request->body()->all()['RateRequest']['Shipment']['ShipmentServiceOptions']));
});

it('keeps only the Saturday rows, tagged, when Saturday delivery was requested', function (): void {
    fakeUpsRateEndpointsQuoting(upsFridayShopResponse());

    $rates = $this->adapter->getRates(upsFridayRateRequest(['saturday_delivery']), ['01', '12']);

    expect($rates)->toHaveCount(1)
        ->and($rates[0]->serviceCode)->toBe('01')
        ->and($rates[0]->price)->toBe(87.17)
        ->and($rates[0]->deliveryDate)->toBe('2026-09-19')
        ->and($rates[0]->metadata['saturday_delivery'])->toBeTrue()
        ->and(sentUpsRatePackages())->toHaveCount(1);

    Saloon::assertSent(fn ($request): bool => $request instanceof Rate
        && array_key_exists('SaturdayDeliveryIndicator', $request->body()->all()['RateRequest']['Shipment']['ShipmentServiceOptions']));
});

it('returns nothing, without a second request, when Saturday was requested and UPS offers no Saturday row', function (): void {
    fakeUpsRateEndpointsQuoting([
        upsRatedShipment('01', '66.21', saturday: false, arrival: '20260921'),
        upsRatedShipment('12', '27.90', saturday: false, arrival: '20260923'),
    ]);

    $rates = $this->adapter->getRates(upsFridayRateRequest(['saturday_delivery']), ['01', '12']);

    expect($rates)->toBeEmpty()
        ->and(sentUpsRatePackages())->toHaveCount(1);
});

it('sends the Saturday indicator at label time only for a rate quoted as Saturday', function (bool $tagged, array $specialServiceCodes): void {
    fakeUpsShipEndpoints();

    $response = $this->adapter->createShipment(upsShipRequestTo(
        new AddressData(firstName: 'Jane', lastName: 'Doe', streetAddress: '456 Main St', city: 'Los Angeles', stateOrProvince: 'CA', postalCode: '90210'),
        specialServiceCodes: $specialServiceCodes,
        rateMetadata: ['serviceCode' => '01', ...($tagged ? ['saturday_delivery' => true] : [])],
    ));

    expect($response->success)->toBeTrue()
        ->and(in_array('saturday_delivery', $response->appliedServices, true))->toBe($tagged);

    Saloon::assertSent(function ($request) use ($tagged): bool {
        if (! $request instanceof CreateShipment) {
            return false;
        }

        $shipment = $request->body()->all()['ShipmentRequest']['Shipment'];

        expect(isset($shipment['ShipmentServiceOptions']['SaturdayDeliveryIndicator']))->toBe($tagged);

        return true;
    });
})->with([
    'a tagged rate' => [true, []],
    'an untagged rate, even with the request flag still set' => [false, ['saturday_delivery']],
]);

it('fails the label with the UPS message from a single attempt when UPS refuses it', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        CreateShipment::class => MockResponse::make([
            'response' => ['errors' => [['code' => '120202', 'message' => 'Saturday Delivery is not available for this shipment.']]],
        ], 400),
    ]);

    $response = $this->adapter->createShipment(upsShipRequestTo(
        new AddressData(firstName: 'Jane', lastName: 'Doe', streetAddress: '456 Main St', city: 'Los Angeles', stateOrProvince: 'CA', postalCode: '90210'),
        rateMetadata: ['serviceCode' => '01', 'saturday_delivery' => true],
    ));

    expect($response->success)->toBeFalse()
        ->and($response->errorMessage)->toBe('Saturday Delivery is not available for this shipment.')
        ->and(collect(Saloon::mockClient()->getRecordedResponses())
            ->filter(fn ($recorded): bool => $recorded->getPendingRequest()->getRequest() instanceof CreateShipment))
        ->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| Carrier packaging — ADR-0005 decision 3, the UPS half
|--------------------------------------------------------------------------
|
| UPS puts packaging on the request, so the adapter sends the code the Box
| Size's CarrierPackaging maps to on both bodies and stamps every rate with
| the code it sent. packaging-form-and-carrier-identity/07.
|
*/

function fakeUpsRateEndpointsQuoting(array $ratedShipments): void
{
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Rate::class => MockResponse::make(['RateResponse' => ['RatedShipment' => $ratedShipments]]),
    ]);
}

function upsRateRequestIn(?CarrierPackaging $packaging): RateRequest
{
    return new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: '90210',
        packages: [new PackageData(weight: 2.0, length: 12, width: 10, height: 8, carrierPackaging: $packaging)],
    );
}

function upsShipRequestIn(?CarrierPackaging $packaging, array $metadata = ['serviceCode' => '03']): ShipRequest
{
    return new ShipRequest(
        fromAddress: new AddressData(firstName: 'Shipping', lastName: 'Center', streetAddress: '123 Warehouse St', city: 'Seattle', stateOrProvince: 'WA', postalCode: '98072', phone: '5551234567'),
        toAddress: new AddressData(firstName: 'Jane', lastName: 'Doe', streetAddress: '456 Main St', city: 'Los Angeles', stateOrProvince: 'CA', postalCode: '90210', phone: '5559876543'),
        packageData: new PackageData(weight: 2.0, length: 12, width: 10, height: 8, carrierPackaging: $packaging),
        selectedRate: new RateResponse(carrier: 'UPS', serviceCode: '03', serviceName: 'UPS Ground', price: 11.00, metadata: $metadata),
    );
}

/**
 * The `Package` of every rate request actually sent, in order. Read off the
 * recorded responses rather than a counting closure: a mock closure also runs
 * when prepareRateRequest() creates a PendingRequest it never sends.
 *
 * @return array<int, array<string, mixed>>
 */
function sentUpsRatePackages(): array
{
    return collect(Saloon::mockClient()->getRecordedResponses())
        ->map(fn ($response) => $response->getPendingRequest())
        ->filter(fn (PendingRequest $pendingRequest): bool => $pendingRequest->getRequest() instanceof Rate)
        ->map(fn (PendingRequest $pendingRequest): array => $pendingRequest->body()->all()['RateRequest']['Shipment']['Package'])
        ->values()
        ->all();
}

dataset('ups packaging codes', [
    'a UPS Letter' => [CarrierPackaging::UpsLetter, '01'],
    'a UPS Pak' => [CarrierPackaging::UpsPak, '04'],
    'a medium UPS Express Box' => [CarrierPackaging::UpsExpressBoxMedium, '2b'],
    'the packer\'s own box' => [null, '02'],
    'a USPS Medium Flat Rate Box' => [CarrierPackaging::UspsMediumFlatRateBox, '02'],
]);

it('names the packaging on the rate request', function (?CarrierPackaging $packaging, string $expectedCode): void {
    fakeUpsRateEndpointsQuoting([]);

    $this->adapter->getRates(upsRateRequestIn($packaging), ['03']);

    $sent = sentUpsRatePackages();

    expect($sent)->toHaveCount(1)
        ->and($sent[0]['PackagingType']['Code'])->toBe($expectedCode);

    // The whole rate body is not validated (see the skips above); the
    // container this slice changes is.
    assertMatchesUpsSchema($sent[0]['PackagingType'], 'Package_PackagingType', 'upsRating');
})->with('ups packaging codes');

it('names the packaging on the ship request', function (?CarrierPackaging $packaging, string $expectedCode): void {
    fakeUpsShipEndpoints();

    expect($this->adapter->createShipment(upsShipRequestIn($packaging))->success)->toBeTrue();

    Saloon::assertSent(function ($request) use ($expectedCode): bool {
        if (! $request instanceof CreateShipment) {
            return false;
        }

        $body = $request->body()->all();

        assertMatchesUpsSchema($body, 'SHIPRequestWrapper', 'upsShipping');

        return $body['ShipmentRequest']['Shipment']['Package'][0]['Packaging']['Code'] === $expectedCode;
    });
})->with('ups packaging codes');

it('sends dimensions for every packaging except a UPS Letter, whose size is UPS\'s own', function (?CarrierPackaging $packaging, bool $expectsDimensions): void {
    fakeUpsShipEndpoints();

    expect($this->adapter->createShipment(upsShipRequestIn($packaging))->success)->toBeTrue();

    Saloon::assertSent(function ($request) use ($expectsDimensions): bool {
        if (! $request instanceof CreateShipment) {
            return false;
        }

        $body = $request->body()->all();

        assertMatchesUpsSchema($body, 'SHIPRequestWrapper', 'upsShipping');

        return array_key_exists('Dimensions', $body['ShipmentRequest']['Shipment']['Package'][0]) === $expectsDimensions;
    });
})->with([
    'a UPS Letter' => [CarrierPackaging::UpsLetter, false],
    'a UPS Pak' => [CarrierPackaging::UpsPak, true],
    'the packer\'s own box' => [null, true],
]);

it('stamps every rate from a UPS Pak request as exactly a UPS Pak', function (): void {
    fakeUpsRateEndpointsQuoting([
        ['Service' => ['Code' => '03'], 'TotalCharges' => ['MonetaryValue' => '11.00']],
        ['Service' => ['Code' => '02'], 'TotalCharges' => ['MonetaryValue' => '28.00']],
    ]);

    $rates = $this->adapter->getRates(upsRateRequestIn(CarrierPackaging::UpsPak), ['03', '02']);

    expect($rates)->toHaveCount(2)
        ->and($rates->pluck('metadata.packagingCode')->unique()->all())->toBe(['04']);

    foreach ($rates as $rate) {
        expect($rate->packagingRequirement->accepts(CarrierPackaging::UpsPak))->toBeTrue()
            ->and($rate->packagingRequirement->accepts(CarrierPackaging::UpsLetter))->toBeFalse()
            ->and($rate->packagingRequirement->accepts(null))->toBeFalse()
            ->and($this->adapter->packagingRequirementFor($rate)->accepts(CarrierPackaging::UpsPak))->toBeTrue();
    }
});

it('stamps every rate from a plain request as the shipper\'s packaging', function (?CarrierPackaging $packaging): void {
    fakeUpsRateEndpointsQuoting([
        ['Service' => ['Code' => '03'], 'TotalCharges' => ['MonetaryValue' => '11.00']],
    ]);

    $rates = $this->adapter->getRates(upsRateRequestIn($packaging), ['03']);

    expect($rates)->toHaveCount(1)
        ->and($rates[0]->metadata['packagingCode'])->toBe('02')
        ->and($rates[0]->packagingRequirement->isShipperPackaging())->toBeTrue()
        ->and($this->adapter->packagingRequirementFor($rates[0])->isShipperPackaging())->toBeTrue();
})->with([
    'the packer\'s own box' => [null],
    'a USPS Medium Flat Rate Box, which UPS rates as customer packaging' => [CarrierPackaging::UspsMediumFlatRateBox],
]);

it('treats a rate quoted before the packaging code was stamped as the shipper\'s packaging', function (): void {
    $legacy = new RateResponse(carrier: 'UPS', serviceCode: '03', serviceName: 'UPS Ground', price: 11.00, metadata: ['serviceCode' => '03']);

    expect($this->adapter->packagingRequirementFor($legacy)->isShipperPackaging())->toBeTrue();
});

it('refuses to classify a packaging code it never sends', function (string $code): void {
    $restated = new RateResponse(carrier: 'UPS', serviceCode: '03', serviceName: 'UPS Ground', price: 11.00, metadata: ['serviceCode' => '03', 'packagingCode' => $code]);

    expect(fn () => $this->adapter->packagingRequirementFor($restated))
        ->toThrow(UnclassifiablePackagingException::class, "UPS packaging code {$code}");
})->with([
    'the 25KG box, not yet in the enum' => ['24'],
    'a pallet' => ['30'],
    'nonsense' => ['zz'],
]);

it('keeps a pre-selected rate only when the package meets its packaging requirement', function (): void {
    $package = Package::factory()->create([
        'box_size_id' => BoxSize::factory()->carrierPackaging(CarrierPackaging::UpsPak)->create()->id,
    ]);

    $quotedForAPak = new RateResponse(carrier: 'UPS', serviceCode: '03', serviceName: 'UPS Ground', price: 11.00, metadata: ['serviceCode' => '03', 'packagingCode' => '04'], packagingRequirement: PackagingRequirement::exactly(CarrierPackaging::UpsPak));
    $rulesRate = new RateResponse(carrier: 'UPS', serviceCode: '03', serviceName: 'UPS Ground', price: 11.00, metadata: ['serviceCode' => '03']);

    expect($this->adapter->resolvePreSelectedRate($quotedForAPak, $package))->toBe($quotedForAPak)
        ->and($this->adapter->resolvePreSelectedRate($rulesRate, $package))->toBeNull();
});

// --- Recovery by reference — postage-source-split/18 ------------------------

/**
 * A domestic ship request from an offer, the way the workflow builds one.
 */
function upsOfferShipRequest(ShippingOffer $offer, array $references = ['ORD-10042'], string $labelFormat = 'image', ?int $labelDpi = null): ShipRequest
{
    return new ShipRequest(
        fromAddress: new AddressData(firstName: 'Shipping', lastName: 'Center', streetAddress: '123 Warehouse St', city: 'Seattle', stateOrProvince: 'WA', postalCode: '98072'),
        toAddress: new AddressData(firstName: 'John', lastName: 'Doe', streetAddress: '456 Main St', city: 'Los Angeles', stateOrProvince: 'CA', postalCode: '90210'),
        packageData: new PackageData(weight: 2.0, length: 10, width: 8, height: 4),
        selectedRate: new RateResponse(carrier: 'UPS', serviceCode: '03', serviceName: 'UPS Ground', price: 16.96, metadata: ['serviceCode' => '03']),
        labelFormat: $labelFormat,
        labelDpi: $labelDpi,
        references: $references,
        offer: $offer,
    );
}

/**
 * Label Recovery's answers, as captured in production on 2026-09-18.
 */
function upsLabelRecoveryFound(string $trackingNumber = '1Z14A6G90303889622', string $format = 'gif', ?string $form = null): MockResponse
{
    return MockResponse::make([
        'LabelRecoveryResponse' => [
            'Response' => ['ResponseStatus' => ['Code' => '1', 'Description' => 'Success']],
            'ShipmentIdentificationNumber' => $trackingNumber,
            'LabelResults' => [[
                'TrackingNumber' => $trackingNumber,
                'LabelImage' => [
                    'LabelImageFormat' => ['Code' => $format],
                    'GraphicImage' => $format === 'zpl' ? base64_encode('^XA^FDrecovered^FS^XZ') : 'R0lGODlhAQABAAAAACw=',
                ],
            ]],
            ...($form === null ? [] : ['Form' => ['Code' => '01', 'Image' => ['ImageFormat' => ['Code' => 'PDF'], 'GraphicImage' => $form]]]),
        ],
    ]);
}

/**
 * A ship request from an offer for a lane that crosses a customs zone.
 */
function upsInternationalOfferShipRequest(ShippingOffer $offer): ShipRequest
{
    return new ShipRequest(
        fromAddress: new AddressData(firstName: 'Shipping', lastName: 'Center', streetAddress: '123 Warehouse St', city: 'Seattle', stateOrProvince: 'WA', postalCode: '98072'),
        toAddress: upsCanadianAddress(),
        packageData: new PackageData(weight: 2.0, length: 10, width: 8, height: 4),
        selectedRate: new RateResponse(carrier: 'UPS', serviceCode: '11', serviceName: 'UPS Standard', price: 24.10, metadata: ['serviceCode' => '11']),
        customsItems: upsCustomsItems(),
        offer: $offer,
    );
}

function upsLabelRecoveryError(string $code, string $message): MockResponse
{
    return MockResponse::make(['response' => ['errors' => [['code' => $code, 'message' => $message]]]], 400);
}

it('spends the first reference slot on the offer, and the client keeps one', function (): void {
    fakeUpsShipEndpoints();
    $offer = ShippingOffer::factory()->direct()->create(['carrier' => 'UPS']);

    expect($this->adapter->createShipment(upsOfferShipRequest($offer, ['ORD-10042', 'PO-77']))->success)->toBeTrue();

    Saloon::assertSent(function ($request) use ($offer): bool {
        if (! $request instanceof CreateShipment) {
            return false;
        }

        $shipment = $request->body()->all()['ShipmentRequest']['Shipment'];

        // The offer's public_id is 26 characters, inside UPS's 35; the
        // client's second reference is the one that gives way.
        return ($shipment['Package'][0]['ReferenceNumber'] ?? null) === [
            ['Code' => 'TN', 'Value' => $offer->public_id],
            ['Code' => 'TN', 'Value' => 'ORD-10042'],
        ];
    });
});

it('lets a ship request that got no answer escape, sent once', function (): void {
    $attempts = 0;
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        CreateShipment::class => function (PendingRequest $pending) use (&$attempts): MockResponse {
            $attempts++;

            return MockResponse::make()->throw(new FatalRequestException(new RuntimeException('Connection timed out'), $pending));
        },
    ]);

    expect(fn () => $this->adapter->createShipment(upsOfferShipRequest(ShippingOffer::factory()->direct()->create(['carrier' => 'UPS']))))
        ->toThrow(FatalRequestException::class);

    expect($attempts)->toBe(1);
});

it('still answers a refusal as a failed response, not an exception', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        CreateShipment::class => MockResponse::make(['response' => ['errors' => [['code' => '120100', 'message' => 'Missing or invalid shipper number']]]], 400),
    ]);

    $response = $this->adapter->createShipment(upsOfferShipRequest(ShippingOffer::factory()->direct()->create(['carrier' => 'UPS'])));

    expect($response->success)->toBeFalse()
        ->and($response->errorMessage)->toBe('Missing or invalid shipper number');
});

it('recovers the label by the offer reference instead of buying again', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        LabelRecovery::class => upsLabelRecoveryFound(format: 'zpl'),
    ]);
    $offer = ShippingOffer::factory()->direct()->awaitingConfirmation()->create(['carrier' => 'UPS']);

    $response = $this->adapter->recoverPurchase(upsOfferShipRequest($offer, labelFormat: 'zpl', labelDpi: 300));

    expect($response)->not->toBeNull()
        ->and($response->success)->toBeTrue()
        ->and($response->trackingNumber)->toBe('1Z14A6G90303889622')
        ->and($response->carrier)->toBe('UPS')
        ->and($response->service)->toBe('UPS Ground')
        // Label Recovery returns no charges; the offer's price is the cost.
        ->and($response->cost)->toBe(16.96)
        ->and($response->labelFormat)->toBe('zpl')
        ->and(base64_decode((string) $response->labelData))->toStartWith('^XA^JMA');

    Saloon::assertSent(function ($request) use ($offer): bool {
        if (! $request instanceof LabelRecovery) {
            return false;
        }

        $body = $request->body()->all()['LabelRecoveryRequest'];

        return $body['ReferenceValues'] === ['ReferenceNumber' => ['Value' => $offer->public_id], 'ShipperNumber' => 'A1B2C3']
            && $body['LabelSpecification']['LabelImageFormat']['Code'] === 'ZPL'
            && isset($body['LabelSpecification']['LabelStockSize']);
    });
    Saloon::assertNotSent(CreateShipment::class);
});

it('names no stock size when recovering a GIF label, which UPS refuses', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        LabelRecovery::class => upsLabelRecoveryFound(),
    ]);

    $response = $this->adapter->recoverPurchase(upsOfferShipRequest(ShippingOffer::factory()->direct()->awaitingConfirmation()->create(['carrier' => 'UPS'])));

    expect($response?->success)->toBeTrue()
        ->and($response->labelFormat)->toBe('image')
        ->and($response->labelOrientation)->toBe('landscape');

    Saloon::assertSent(fn ($request): bool => $request instanceof LabelRecovery
        && ! isset($request->body()->all()['LabelRecoveryRequest']['LabelSpecification']['LabelStockSize']));
});

it('settles the offer when UPS is certain nothing was created, or the shipment was voided', function (string $code, string $message, string $expected): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        LabelRecovery::class => upsLabelRecoveryError($code, $message),
    ]);

    $response = $this->adapter->recoverPurchase(upsOfferShipRequest(ShippingOffer::factory()->direct()->awaitingConfirmation()->create(['carrier' => 'UPS'])));

    expect($response)->not->toBeNull()
        ->and($response->success)->toBeFalse()
        ->and($response->errorMessage)->toContain($expected);
})->with([
    'reference never used' => ['9801031', 'The shipment for the requested tracking number or the combination of reference number plus shipper number could not be found.', 'nothing was bought'],
    'shipment voided' => ['9801040', 'The shipment for which you are trying to recover a label or Receipt has been voided.', 'has since been voided'],
]);

it('leaves the question open on any other Label Recovery answer', function (MockResponse $recovery): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        LabelRecovery::class => $recovery,
    ]);

    expect($this->adapter->recoverPurchase(upsOfferShipRequest(ShippingOffer::factory()->direct()->awaitingConfirmation()->create(['carrier' => 'UPS']))))->toBeNull();
})->with([
    'a different 400' => fn (): MockResponse => upsLabelRecoveryError('9801050', 'Label Stock Size not allowed for specified Label Image Type.'),
    'a 503' => fn () => MockResponse::make(['response' => ['errors' => [['code' => '10429', 'message' => 'Service Unavailable']]]], 503),
    'no answer' => fn () => MockResponse::make()->throw(fn (PendingRequest $pending): FatalRequestException => new FatalRequestException(new RuntimeException('Connection timed out'), $pending)),
    'a label with no tracking number' => fn () => MockResponse::make(['LabelRecoveryResponse' => ['Response' => ['ResponseStatus' => ['Code' => '1']], 'LabelResults' => []]]),
]);

it('lets a 5xx on the ship request escape rather than settle it as a decline', function (): void {
    // UPS may have created the shipment before the server error, so a 5xx
    // is no answer at all: the offer stays unresolved and Label Recovery
    // decides.
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        CreateShipment::class => MockResponse::make(['response' => ['errors' => [['code' => '10429', 'message' => 'Service Unavailable']]]], 503),
    ]);

    expect(fn () => $this->adapter->createShipment(upsOfferShipRequest(ShippingOffer::factory()->direct()->create(['carrier' => 'UPS']))))
        ->toThrow(ServerException::class);
});

it('recovers the international forms with the label', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        LabelRecovery::class => upsLabelRecoveryFound(form: 'JVBERi0xLjQ='),
    ]);

    $response = $this->adapter->recoverPurchase(upsInternationalOfferShipRequest(ShippingOffer::factory()->direct()->awaitingConfirmation()->create(['carrier' => 'UPS'])));

    expect($response?->success)->toBeTrue()
        ->and($response->customsFormData)->toBe('JVBERi0xLjQ=');
});

it('leaves a cross-border recovery open when the label comes back without its forms', function (): void {
    // A package shipped without the customs document it needs is worse than
    // one still blocked; a person decides.
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        LabelRecovery::class => upsLabelRecoveryFound(),
    ]);

    expect($this->adapter->recoverPurchase(upsInternationalOfferShipRequest(ShippingOffer::factory()->direct()->awaitingConfirmation()->create(['carrier' => 'UPS']))))->toBeNull();
});

it('asks UPS with the shipper number of the account the offer was bought on', function (): void {
    // Label Recovery looks up by reference *and* shipper number. A second
    // account is now preferred; the offer records the first.
    $original = CarrierAccount::query()->firstOrFail();
    // Scopes edited since the quote: the global default now points at a
    // second account, and the original keeps no scope at all.
    $original->scopes()->delete();
    createUpsAccount(['client_id' => 'other_client'], ['account_number' => 'Z9Y8X7']);

    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        LabelRecovery::class => upsLabelRecoveryFound(),
    ]);
    $offer = ShippingOffer::factory()->direct()->awaitingConfirmation()->create([
        'carrier' => 'UPS',
        'carrier_account_id' => $original->id,
        'carrier_account_fingerprint' => $original->fingerprint(),
    ]);

    $response = $this->adapter->recoverPurchase(upsOfferShipRequest($offer));

    expect($response?->success)->toBeTrue()
        ->and($response->carrierAccountId)->toBe($original->id);
    Saloon::assertSent(fn ($request): bool => $request instanceof LabelRecovery
        && $request->body()->all()['LabelRecoveryRequest']['ReferenceValues']['ShipperNumber'] === 'A1B2C3');
});

it('leaves the question open when the account the offer was bought on is gone or bills someone else', function (string $change): void {
    Saloon::fake(['*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600])]);
    $account = CarrierAccount::query()->firstOrFail();
    $offer = ShippingOffer::factory()->direct()->awaitingConfirmation()->create([
        'carrier' => 'UPS',
        'carrier_account_id' => $account->id,
        'carrier_account_fingerprint' => $account->fingerprint(),
    ]);

    match ($change) {
        'deleted' => $account->delete(),
        'rebilled' => $account->update(['credentials' => ['account_number' => 'Q1W2E3']]),
        default => throw new InvalidArgumentException($change),
    };

    expect($this->adapter->recoverPurchase(upsOfferShipRequest($offer->fresh())))->toBeNull();
    Saloon::assertNothingSent();
})->with(['deleted', 'rebilled']);
