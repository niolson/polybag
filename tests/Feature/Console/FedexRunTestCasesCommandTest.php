<?php

use App\Http\Integrations\Fedex\FedexConnector;
use App\Http\Integrations\Fedex\Requests\CreateFreightShipment;
use App\Http\Integrations\Fedex\Requests\CreateShipment;
use App\Models\Package;
use App\Models\Shipment;
use App\Services\FedexTestCases\FedexTestCaseNormalizer;
use App\Services\FedexTestCases\FedexTestCaseRepository;
use App\Services\FedexTestCases\FedexTestCaseRunner;
use Saloon\Http\Auth\AccessTokenAuthenticator;
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

it('normalizes IntegratorUS08 with the corrected Freight LTL payload', function (): void {
    $suite = app(FedexTestCaseRepository::class)->load(region: 'us', suite: 'ship');
    $testCase = collect($suite->cases())->firstWhere('id', 'IntegratorUS08');

    expect($testCase)->not->toBeNull();

    $payload = app(FedexTestCaseNormalizer::class)->normalize($testCase, '700257037');

    expect(data_get($payload, 'freightRequestedShipment.shipper.contact.personName'))->toBeNull()
        ->and(data_get($payload, 'accountNumber.value'))->toBe('740561073')
        ->and(data_get($payload, 'freightRequestedShipment.recipient.contact.personName'))->toBeNull()
        ->and(data_get($payload, 'freightRequestedShipment.shippingChargesPayment.payor.responsibleParty.contact.personName'))->toBeNull()
        ->and(data_get($payload, 'freightRequestedShipment.freightShipmentDetail.fedExFreightBillingContactAndAddress.contact.personName'))->toBeNull()
        ->and(data_get($payload, 'freightRequestedShipment.rateRequestTypes'))->toBeNull()
        ->and(data_get($payload, 'freightRequestedShipment.rateRequestType'))->toBe(['LIST', 'PREFERRED'])
        ->and(data_get($payload, 'freightRequestedShipment.freightShipmentDetail.lineItems'))->toBeNull()
        ->and(data_get($payload, 'freightRequestedShipment.freightShipmentDetail.lineItem.0.id'))->toBe(10)
        ->and(data_get($payload, 'freightRequestedShipment.freightShipmentDetail.fedExFreightAccountNumber.value'))->toBe('630081440')
        ->and(data_get($payload, 'freightRequestedShipment.recipient.dispositionType'))->toBeNull()
        ->and(data_get($payload, 'freightRequestedShipment.shippingDocumentSpecification.commercialInvoiceDetail'))->toBeNull()
        ->and(data_get($payload, 'freightRequestedShipment.shippingDocumentSpecification.shippingDocumentTypes'))->toBe(['FEDEX_FREIGHT_STRAIGHT_BILL_OF_LADING']);
});

it('stores Freight LTL labels using the freight image type', function (): void {
    Saloon::fake([
        CreateFreightShipment::class => MockResponse::make([
            'output' => [
                'transactionShipments' => [
                    [
                        'serviceType' => 'FEDEX_FREIGHT_PRIORITY',
                        'pieceResponses' => [
                            [
                                'trackingNumber' => '794804116230',
                                'packageDocuments' => [
                                    [
                                        'encodedLabel' => base64_encode('freight-zpl'),
                                    ],
                                ],
                            ],
                        ],
                        'completedShipmentDetail' => [
                            'shipmentRating' => [
                                'shipmentRateDetails' => [
                                    [
                                        'totalNetFedExCharge' => 123.45,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $suite = app(FedexTestCaseRepository::class)->load(region: 'us', suite: 'ship');
    $testCase = collect($suite->cases())->firstWhere('id', 'IntegratorUS08');

    expect($testCase)->not->toBeNull();

    $payload = app(FedexTestCaseNormalizer::class)->normalize($testCase, '700257037');
    $connector = new FedexConnector;
    $connector->authenticate(new AccessTokenAuthenticator('test-token'));

    $result = app(FedexTestCaseRunner::class)->run(
        connector: $connector,
        testCase: $testCase,
        payload: $payload,
        saveLabels: false,
        artifactDirectory: null,
    );

    expect($result['success'])->toBeTrue()
        ->and(Package::query()->latest('id')->first()?->label_format)->toBe('zpl');
});

it('fails fast for an unsupported region', function (): void {
    $this->artisan('fedex:run-test-cases', ['--region' => 'emea'])
        ->expectsOutputToContain('Unsupported region [emea]. Valid regions: us, ca, lac')
        ->assertFailed();
});
