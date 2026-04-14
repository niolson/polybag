<?php

use App\Http\Integrations\Fedex\FedexConnector;
use App\Http\Integrations\Fedex\FedexRegistrationProxyConnector;
use App\Http\Integrations\Fedex\Requests\CreateShipment;
use App\Http\Integrations\Fedex\Requests\Rates;
use App\Http\Integrations\Fedex\Requests\Registration\ValidateAddress;
use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;

it('resolves production base URL by default', function (): void {
    config([
        'services.fedex.base_url' => 'https://apis.fedex.com',
        'services.fedex.sandbox_url' => 'https://apis-sandbox.fedex.com',
    ]);
    app(SettingsService::class)->clearCache();

    $connector = new FedexConnector;

    expect($connector->resolveBaseUrl())->toBe('https://apis.fedex.com');
});

it('resolves sandbox base URL when sandbox_mode is enabled', function (): void {
    config([
        'services.fedex.base_url' => 'https://apis.fedex.com',
        'services.fedex.sandbox_url' => 'https://apis-sandbox.fedex.com',
    ]);
    Setting::create(['key' => 'sandbox_mode', 'value' => '1', 'type' => 'boolean', 'group' => 'testing']);
    app(SettingsService::class)->clearCache();

    $connector = new FedexConnector;

    expect($connector->resolveBaseUrl())->toBe('https://apis-sandbox.fedex.com');
});

it('requests correct endpoint for rates', function (): void {
    $request = new Rates;

    expect($request->resolveEndpoint())->toBe('/rate/v1/rates/quotes');
});

it('requests correct endpoint for create shipment', function (): void {
    $request = new CreateShipment;

    expect($request->resolveEndpoint())->toBe('/ship/v1/shipments');
});

it('builds correct rate request', function (): void {
    config(['services.fedex.account_number' => 'TEST_ACCOUNT']);

    Saloon::fake([
        Rates::class => MockResponse::make([
            'output' => ['rateReplyDetails' => []],
        ]),
    ]);

    $connector = new FedexConnector;
    $request = new Rates;
    $request->body()->set([
        'accountNumber' => [
            'value' => config('services.fedex.account_number'),
        ],
        'requestedShipment' => [
            'shipper' => [
                'address' => [
                    'postalCode' => '98072',
                    'countryCode' => 'US',
                ],
            ],
            'recipient' => [
                'address' => [
                    'postalCode' => '90210',
                    'countryCode' => 'US',
                ],
            ],
            'requestedPackageLineItems' => [
                [
                    'weight' => [
                        'units' => 'LB',
                        'value' => 5.0,
                    ],
                ],
            ],
        ],
    ]);

    $connector->send($request);

    Saloon::assertSent(function (Rates $request) {
        $body = $request->body()->all();

        return $body['accountNumber']['value'] === 'TEST_ACCOUNT'
            && $body['requestedShipment']['shipper']['address']['postalCode'] === '98072'
            && $body['requestedShipment']['recipient']['address']['postalCode'] === '90210'
            && $body['requestedShipment']['requestedPackageLineItems'][0]['weight']['value'] === 5.0;
    });
});

it('builds correct create shipment request', function (): void {
    config(['services.fedex.account_number' => 'TEST_ACCOUNT']);

    Saloon::fake([
        CreateShipment::class => MockResponse::make([
            'output' => [
                'transactionShipments' => [
                    [
                        'masterTrackingNumber' => '794644790293',
                        'pieceResponses' => [
                            [
                                'trackingNumber' => '794644790293',
                                'packageDocuments' => [
                                    ['encodedLabel' => base64_encode('PDF content')],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $connector = new FedexConnector;
    $request = new CreateShipment;
    $request->body()->set([
        'labelResponseOptions' => 'LABEL',
        'accountNumber' => ['value' => config('services.fedex.account_number')],
        'requestedShipment' => [
            'shipper' => [
                'contact' => [
                    'personName' => 'Shipping Center',
                    'phoneNumber' => '5551234567',
                ],
                'address' => [
                    'streetLines' => ['123 Warehouse Blvd'],
                    'city' => 'Seattle',
                    'stateOrProvinceCode' => 'WA',
                    'postalCode' => '98101',
                    'countryCode' => 'US',
                ],
            ],
            'recipients' => [
                [
                    'contact' => [
                        'personName' => 'John Doe',
                        'phoneNumber' => '5559876543',
                    ],
                    'address' => [
                        'streetLines' => ['456 Main St'],
                        'city' => 'Beverly Hills',
                        'stateOrProvinceCode' => 'CA',
                        'postalCode' => '90210',
                        'countryCode' => 'US',
                    ],
                ],
            ],
            'serviceType' => 'FEDEX_GROUND',
            'packagingType' => 'YOUR_PACKAGING',
            'labelSpecification' => [
                'imageType' => 'PDF',
                'labelStockType' => 'PAPER_4X6',
            ],
            'requestedPackageLineItems' => [
                [
                    'weight' => ['units' => 'LB', 'value' => 5.0],
                    'dimensions' => [
                        'length' => 12,
                        'width' => 10,
                        'height' => 8,
                        'units' => 'IN',
                    ],
                ],
            ],
        ],
    ]);

    $response = $connector->send($request);

    Saloon::assertSent(function (CreateShipment $request) {
        $body = $request->body()->all();

        return $body['labelResponseOptions'] === 'LABEL'
            && $body['requestedShipment']['serviceType'] === 'FEDEX_GROUND'
            && $body['requestedShipment']['shipper']['address']['city'] === 'Seattle'
            && $body['requestedShipment']['recipients'][0]['address']['city'] === 'Beverly Hills';
    });

    $data = $response->json();
    expect($data['output']['transactionShipments'][0]['masterTrackingNumber'])->toBe('794644790293');
});

it('parses rate response correctly', function (): void {
    Saloon::fake([
        Rates::class => MockResponse::make([
            'output' => [
                'rateReplyDetails' => [
                    [
                        'serviceType' => 'FEDEX_GROUND',
                        'serviceName' => 'FedEx Ground',
                        'ratedShipmentDetails' => [
                            [
                                'totalNetCharge' => 12.50,
                                'totalBaseCharge' => 10.00,
                                'totalNetChargeWithDutiesAndTaxes' => 12.50,
                            ],
                        ],
                        'commit' => [
                            'dateDetail' => [
                                'dayOfWeek' => 'FRIDAY',
                                'dayFormat' => '2025-01-17',
                            ],
                            'transitDays' => 'THREE_DAYS',
                        ],
                    ],
                    [
                        'serviceType' => 'FEDEX_EXPRESS_SAVER',
                        'serviceName' => 'FedEx Express Saver',
                        'ratedShipmentDetails' => [
                            ['totalNetCharge' => 25.00],
                        ],
                        'commit' => [
                            'transitDays' => 'TWO_DAYS',
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $connector = new FedexConnector;
    $request = new Rates;
    $request->body()->set([
        'accountNumber' => ['value' => 'TEST'],
        'requestedShipment' => [
            'shipper' => ['address' => ['postalCode' => '98072', 'countryCode' => 'US']],
            'recipient' => ['address' => ['postalCode' => '90210', 'countryCode' => 'US']],
            'requestedPackageLineItems' => [['weight' => ['units' => 'LB', 'value' => 5]]],
        ],
    ]);

    $response = $connector->send($request);
    $data = $response->json('output.rateReplyDetails');

    expect($data)->toHaveCount(2)
        ->and($data[0]['serviceType'])->toBe('FEDEX_GROUND')
        ->and((float) $data[0]['ratedShipmentDetails'][0]['totalNetCharge'])->toBe(12.50)
        ->and($data[0]['commit']['transitDays'])->toBe('THREE_DAYS')
        ->and($data[1]['serviceType'])->toBe('FEDEX_EXPRESS_SAVER')
        ->and((float) $data[1]['ratedShipmentDetails'][0]['totalNetCharge'])->toBe(25.00);
});

it('handles error responses', function (): void {
    Saloon::fake([
        Rates::class => MockResponse::make([
            'errors' => [
                [
                    'code' => 'INVALID.INPUT.EXCEPTION',
                    'message' => 'Invalid postal code',
                ],
            ],
        ], 400),
    ]);

    $connector = new FedexConnector;
    // Disable retry for this test to get the raw response
    $connector->tries = 1;
    $request = new Rates;
    $request->body()->set([
        'accountNumber' => ['value' => 'TEST'],
        'requestedShipment' => [
            'shipper' => ['address' => ['postalCode' => 'INVALID', 'countryCode' => 'US']],
            'recipient' => ['address' => ['postalCode' => '90210', 'countryCode' => 'US']],
            'requestedPackageLineItems' => [['weight' => ['units' => 'LB', 'value' => 5]]],
        ],
    ]);

    $response = $connector->send($request);

    expect($response->status())->toBe(400)
        ->and($response->json('errors.0.code'))->toBe('INVALID.INPUT.EXCEPTION');
});

it('logs parent authorization artifacts when requesting a token directly', function (): void {
    Storage::fake();

    config([
        'services.fedex.base_url' => 'https://apis.fedex.com',
        'services.fedex.api_key' => 'parent-key',
        'services.fedex.api_secret' => 'parent-secret',
    ]);

    Saloon::fake([
        'https://apis.fedex.com/oauth/token' => MockResponse::make([
            'access_token' => 'parent-access-token',
            'token_type' => 'bearer',
            'expires_in' => 3600,
        ], 200),
    ]);

    $authenticator = (new FedexConnector)->getAccessToken();

    expect($authenticator->getAccessToken())->toBe('parent-access-token');

    Storage::assertExists('fedex-mfa/latest/parent-authorization/request.json');
    Storage::assertExists('fedex-mfa/latest/parent-authorization/response.json');

    $requestArtifact = json_decode(Storage::get('fedex-mfa/latest/parent-authorization/request.json'), true);
    $responseArtifact = json_decode(Storage::get('fedex-mfa/latest/parent-authorization/response.json'), true);

    expect(data_get($requestArtifact, 'body.client_id'))->toBe('[REDACTED]')
        ->and(data_get($requestArtifact, 'body.client_secret'))->toBe('[REDACTED]')
        ->and(data_get($responseArtifact, 'body.access_token'))->toBe('[REDACTED]');
});

it('logs child authorization artifacts when brokered child credentials request a token', function (): void {
    Storage::fake();
    Http::fake([
        'https://broker.example.test/fedex/token' => Http::response([
            'access_token' => 'child-access-token',
            'token_type' => 'bearer',
            'expires_in' => 3600,
        ], 200),
    ]);

    config([
        'services.oauth.broker_url' => 'https://broker.example.test',
        'services.oauth.instance_id' => 'instance-123',
        'services.oauth.broker_secret' => 'broker-secret',
    ]);

    Setting::create(['key' => 'fedex.child_key', 'value' => 'child-key-123', 'type' => 'string', 'encrypted' => true, 'group' => 'fedex']);
    Setting::create(['key' => 'fedex.child_secret', 'value' => 'child-secret-456', 'type' => 'string', 'encrypted' => true, 'group' => 'fedex']);
    Setting::create(['key' => 'fedex.child_env', 'value' => 'production', 'type' => 'string', 'group' => 'fedex']);
    app(SettingsService::class)->clearCache();

    $authenticator = (new FedexConnector)->getAccessToken();

    expect($authenticator->getAccessToken())->toBe('child-access-token');

    Storage::assertExists('fedex-mfa/latest/child-authorization/request.json');
    Storage::assertExists('fedex-mfa/latest/child-authorization/response.json');

    $requestArtifact = json_decode(Storage::get('fedex-mfa/latest/child-authorization/request.json'), true);
    $responseArtifact = json_decode(Storage::get('fedex-mfa/latest/child-authorization/response.json'), true);

    expect(data_get($requestArtifact, 'body.child_key'))->toBe('[REDACTED]')
        ->and(data_get($requestArtifact, 'body.child_secret'))->toBe('[REDACTED]')
        ->and(data_get($responseArtifact, 'body.access_token'))->toBe('[REDACTED]');
});

// ─── FedexRegistrationProxyConnector ──────────────────────────────────────────

it('proxy connector resolves base url from broker config', function (): void {
    config(['services.oauth.broker_url' => 'https://polybag-connect.example.com']);

    $connector = new FedexRegistrationProxyConnector;

    expect($connector->resolveBaseUrl())->toBe('https://polybag-connect.example.com');
});

it('proxy connector strips trailing slash from broker url', function (): void {
    config(['services.oauth.broker_url' => 'https://polybag-connect.example.com/']);

    $connector = new FedexRegistrationProxyConnector;

    expect($connector->resolveBaseUrl())->toBe('https://polybag-connect.example.com');
});

it('proxy connector injects instance_id, nonce, and signature into request body', function (): void {
    config([
        'services.oauth.broker_url' => 'https://polybag-connect.example.com',
        'services.oauth.instance_id' => 'test-instance',
        'services.oauth.broker_secret' => 'test-secret',
    ]);

    $capturedBody = null;
    $capturedUrl = null;

    $mockClient = new MockClient([
        ValidateAddress::class => function (PendingRequest $pendingRequest) use (&$capturedBody, &$capturedUrl): MockResponse {
            $capturedBody = $pendingRequest->body()->all();
            $capturedUrl = $pendingRequest->getUrl();

            return MockResponse::make(['output' => ['mfaOptions' => []]], 200);
        },
    ]);

    $connector = new FedexRegistrationProxyConnector;
    $connector->withMockClient($mockClient);
    $connector->send(new ValidateAddress(
        accountNumber: '123',
        customerName: 'Test',
        residential: false,
        street1: '123 Main St',
        street2: '',
        city: 'New York',
        stateOrProvinceCode: 'NY',
        postalCode: '10001',
        countryCode: 'US',
    ));

    expect($capturedUrl)->toBe('https://polybag-connect.example.com/fedex/registration/validate-address');

    expect($capturedBody)
        ->toHaveKey('instance_id', 'test-instance')
        ->toHaveKey('nonce')
        ->toHaveKey('signature');

    $expectedSignature = hash_hmac(
        'sha256',
        '/registration/v2/address/keysgeneration:test-instance:'.$capturedBody['nonce'],
        'test-secret',
    );

    expect($capturedBody['signature'])->toBe($expectedSignature);
});
