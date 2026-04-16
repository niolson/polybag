<?php

use App\Http\Integrations\Fedex\Requests\CreateShipment;
use App\Models\Package;
use App\Models\Shipment;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

it('runs a supported FedEx fixture case and skips unsupported cases from the suite', function (): void {
    $fixturePath = tempnam(sys_get_temp_dir(), 'fedex-command-suite-');

    file_put_contents($fixturePath, json_encode([
        'carrier' => 'fedex',
        'region' => 'us',
        'suite' => 'ship',
        'cases' => [
            [
                'id' => 'Supported01',
                'description' => 'Supported shipment',
                'request_type' => 'create_shipment',
                'supported' => true,
                'request' => [
                    'requestedShipment' => [
                        'shipDatestamp' => 'CURRENT_DATE',
                        'recipients' => [
                            [
                                'contact' => ['personName' => 'Jane Doe'],
                                'address' => [
                                    'streetLines' => ['123 Main St'],
                                    'city' => 'Memphis',
                                    'stateOrProvinceCode' => 'TN',
                                    'postalCode' => '38116',
                                    'countryCode' => 'US',
                                ],
                            ],
                        ],
                        'shippingChargesPayment' => [
                            'paymentType' => 'SENDER',
                            'payor' => ['responsibleParty' => []],
                        ],
                        'labelSpecification' => [
                            'imageType' => 'PDF',
                            'labelStockType' => 'STOCK_4X6',
                        ],
                        'requestedPackageLineItems' => [
                            [
                                'weight' => ['units' => 'LB', 'value' => 2],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id' => 'Unsupported01',
                'description' => 'Unsupported shipment',
                'request_type' => 'create_shipment',
                'supported' => false,
                'skip_reason' => 'Requires a multi-step flow.',
                'request' => [],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        CreateShipment::class => MockResponse::make([
            'output' => [
                'transactionShipments' => [
                    [
                        'serviceType' => 'FEDEX_GROUND',
                        'pieceResponses' => [
                            [
                                'trackingNumber' => '123456789012',
                                'packageDocuments' => [
                                    [
                                        'encodedLabel' => base64_encode('label-pdf'),
                                    ],
                                ],
                            ],
                        ],
                        'completedShipmentDetail' => [
                            'shipmentRating' => [
                                'shipmentRateDetails' => [
                                    [
                                        'totalNetFedExCharge' => 12.34,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $this->artisan('fedex:run-test-cases', ['--fixture' => $fixturePath])
        ->expectsOutputToContain('Skipping Unsupported01')
        ->expectsOutputToContain('Running Supported01')
        ->expectsOutputToContain('Tracking: 123456789012')
        ->expectsOutputToContain('Done. Passed: 1, Failed: 0.')
        ->assertSuccessful();

    expect(Shipment::count())->toBe(1)
        ->and(Package::count())->toBe(1)
        ->and(Package::query()->first()?->tracking_number)->toBe('123456789012');

    @unlink($fixturePath);
});

it('runs the CA ship suite through the region option', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        CreateShipment::class => MockResponse::make([
            'output' => [
                'transactionShipments' => [
                    [
                        'serviceType' => 'FEDEX_EXPRESS_SAVER',
                        'pieceResponses' => [
                            [
                                'trackingNumber' => '111111111111',
                                'packageDocuments' => [
                                    [
                                        'encodedLabel' => base64_encode('label-pdf'),
                                    ],
                                ],
                            ],
                        ],
                        'completedShipmentDetail' => [
                            'shipmentRating' => [
                                'shipmentRateDetails' => [
                                    [
                                        'totalNetFedExCharge' => 12.34,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $this->artisan('fedex:run-test-cases', ['IntegratorCA01', '--region' => 'ca', '--suite' => 'ship'])
        ->expectsOutputToContain('Running IntegratorCA01')
        ->expectsOutputToContain('Tracking: 111111111111')
        ->assertSuccessful();
});

it('runs the LAC ship suite through the region option', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        CreateShipment::class => MockResponse::make([
            'output' => [
                'transactionShipments' => [
                    [
                        'serviceType' => 'PRIORITY_OVERNIGHT',
                        'pieceResponses' => [
                            [
                                'trackingNumber' => '222222222222',
                                'packageDocuments' => [
                                    [
                                        'encodedLabel' => base64_encode('label-pdf'),
                                    ],
                                ],
                            ],
                        ],
                        'completedShipmentDetail' => [
                            'shipmentRating' => [
                                'shipmentRateDetails' => [
                                    [
                                        'totalNetFedExCharge' => 23.45,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $this->artisan('fedex:run-test-cases', ['IntegratorLAC03', '--region' => 'lac', '--suite' => 'ship'])
        ->expectsOutputToContain('Running IntegratorLAC03')
        ->expectsOutputToContain('Tracking: 222222222222')
        ->assertSuccessful();
});

it('fails fast for an unsupported region', function (): void {
    $this->artisan('fedex:run-test-cases', ['--region' => 'emea'])
        ->expectsOutputToContain('Unsupported region [emea]. Valid regions: us, ca, lac')
        ->assertFailed();
});
