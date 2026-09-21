<?php

use App\Http\Integrations\Fedex\FedexConnector;
use App\Http\Integrations\Fedex\FedexRegistrationProxyConnector;
use App\Http\Integrations\Fedex\Requests\CreateShipment;
use App\Http\Integrations\Fedex\Requests\Rates;
use App\Http\Integrations\Fedex\Requests\Registration\ValidateAddress;
use App\Http\Integrations\Fedex\Requests\UploadEtdDocument;
use App\Http\Integrations\Fedex\Requests\UploadEtdImage;
use App\Models\CarrierAccount;
use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\OAuth2\GetClientCredentialsTokenRequest;
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

it('routes ETD upload requests to the sandbox document API host', function (): void {
    config([
        'services.fedex.document_sandbox_url' => 'https://documentapitest.prod.fedex.com/sandbox',
    ]);

    Setting::create(['key' => 'sandbox_mode', 'value' => '1', 'type' => 'boolean', 'group' => 'testing']);
    app(SettingsService::class)->clearCache();

    $capturedUrls = [];

    $mockClient = new MockClient([
        UploadEtdImage::class => function (PendingRequest $pendingRequest) use (&$capturedUrls): MockResponse {
            $capturedUrls['image'] = $pendingRequest->getUrl();

            return MockResponse::make(['output' => ['documentReferenceId' => 'image-doc-id']], 200);
        },
        UploadEtdDocument::class => function (PendingRequest $pendingRequest) use (&$capturedUrls): MockResponse {
            $capturedUrls['document'] = $pendingRequest->getUrl();

            return MockResponse::make(['output' => ['meta' => ['docId' => 'document-doc-id']]], 200);
        },
    ]);

    $connector = new FedexConnector;
    $connector->withMockClient($mockClient);

    $connector->send(new UploadEtdImage(
        imageType: 'LETTERHEAD',
        imageIndex: 'IMAGE_1',
        filename: 'letterhead.png',
        fileContent: 'png-bytes',
    ));

    $connector->send(new UploadEtdDocument(
        filename: 'commercial-invoice.pdf',
        contentType: 'application/pdf',
        originCountryCode: 'US',
        destCountryCode: 'GB',
        fileContent: 'pdf-bytes',
    ));

    expect($capturedUrls['image'] ?? null)->toBe('https://documentapitest.prod.fedex.com/sandbox/documents/v1/lhsimages/upload')
        ->and($capturedUrls['document'] ?? null)->toBe('https://documentapitest.prod.fedex.com/sandbox/documents/v1/etds/upload');
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

    Saloon::assertSent(function (Rates $request): bool {
        $body = $request->body()->all();

        return $body['accountNumber']['value'] === 'TEST_ACCOUNT'
            && $body['requestedShipment']['shipper']['address']['postalCode'] === '98072'
            && $body['requestedShipment']['recipient']['address']['postalCode'] === '90210'
            && $body['requestedShipment']['requestedPackageLineItems'][0]['weight']['value'] === 5.0;
    });
});

it('refreshes the cached authenticator and retries once when FedEx rejects a valid-looking token', function (): void {
    Storage::fake();
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('info')->byDefault();
    Log::shouldReceive('warning')
        ->once()
        ->with('FedEx returned 401 with cached authenticator; refreshed token and retrying request', Mockery::on(
            fn (array $context): bool => $context['request'] === Rates::class
                && $context['cache_key'] === 'fedex_authenticator_sandbox'
        ));

    config([
        'services.fedex.sandbox_url' => 'https://apis-sandbox.fedex.com',
        'services.fedex.sandbox_api_key' => 'sandbox-key',
        'services.fedex.sandbox_api_secret' => 'sandbox-secret',
    ]);

    Setting::create(['key' => 'sandbox_mode', 'value' => '1', 'type' => 'boolean', 'group' => 'testing']);
    app(SettingsService::class)->clearCache();

    Cache::put(
        'fedex_authenticator_sandbox',
        ['access_token' => 'stale-token', 'refresh_token' => null, 'expires_at' => now()->addMinutes(30)->timestamp],
        now()->addMinutes(20),
    );

    $sentRequests = [];

    $mockClient = new MockClient([
        function (PendingRequest $pendingRequest) use (&$sentRequests): MockResponse {
            $sentRequests[] = [
                'request' => $pendingRequest->getRequest()::class,
                'authorization' => $pendingRequest->headers()->get('Authorization'),
            ];

            return MockResponse::make(['error' => 'invalid_token'], 401);
        },
        function (PendingRequest $pendingRequest) use (&$sentRequests): MockResponse {
            $sentRequests[] = [
                'request' => $pendingRequest->getRequest()::class,
                'authorization' => $pendingRequest->headers()->get('Authorization'),
            ];

            return MockResponse::make([
                'access_token' => 'fresh-token',
                'token_type' => 'bearer',
                'expires_in' => 3600,
            ], 200);
        },
        function (PendingRequest $pendingRequest) use (&$sentRequests): MockResponse {
            $sentRequests[] = [
                'request' => $pendingRequest->getRequest()::class,
                'authorization' => $pendingRequest->headers()->get('Authorization'),
            ];

            return MockResponse::make([
                'output' => ['rateReplyDetails' => []],
            ], 200);
        },
    ]);

    $connector = new FedexConnector;
    $connector
        ->withMockClient($mockClient)
        ->authenticate(FedexConnector::deserializeAuthenticator(Cache::get('fedex_authenticator_sandbox')));

    $response = $connector->send(new Rates);

    expect($response->status())->toBe(200)
        ->and($sentRequests)->toHaveCount(3)
        ->and($sentRequests[0]['request'])->toBe(Rates::class)
        ->and($sentRequests[0]['authorization'])->toBe('Bearer stale-token')
        ->and($sentRequests[1]['request'])->toBe(GetClientCredentialsTokenRequest::class)
        ->and($sentRequests[1]['authorization'])->toBeNull()
        ->and($sentRequests[2]['request'])->toBe(Rates::class)
        ->and($sentRequests[2]['authorization'])->toBe('Bearer fresh-token')
        ->and(Cache::get('fedex_authenticator_sandbox')['access_token'])->toBe('fresh-token');
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

    Saloon::assertSent(function (CreateShipment $request): bool {
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

    $account = createFedexAccount(
        ['child_key' => 'child-key-123', 'child_secret' => 'child-secret-456'],
        ['child_env' => 'production'],
    );

    $authenticator = FedexConnector::forAccount($account)->getAccessToken();

    expect($authenticator->getAccessToken())->toBe('child-access-token');

    Storage::assertExists('fedex-mfa/latest/child-authorization/request.json');
    Storage::assertExists('fedex-mfa/latest/child-authorization/response.json');

    $requestArtifact = json_decode(Storage::get('fedex-mfa/latest/child-authorization/request.json'), true);
    $responseArtifact = json_decode(Storage::get('fedex-mfa/latest/child-authorization/response.json'), true);

    expect(data_get($requestArtifact, 'body.child_key'))->toBe('[REDACTED]')
        ->and(data_get($requestArtifact, 'body.child_secret'))->toBe('[REDACTED]')
        ->and(data_get($responseArtifact, 'body.access_token'))->toBe('[REDACTED]');
});

it('uses sandbox client credentials when child credentials were registered in production', function (): void {
    Storage::fake();
    Http::fake([
        'https://broker.example.test/fedex/token' => Http::response([
            'access_token' => 'production-child-access-token',
            'token_type' => 'bearer',
            'expires_in' => 3600,
        ], 200),
    ]);

    config([
        'services.oauth.broker_url' => 'https://broker.example.test',
        'services.oauth.instance_id' => 'instance-123',
        'services.oauth.broker_secret' => 'broker-secret',
        'services.fedex.sandbox_url' => 'https://apis-sandbox.fedex.com',
    ]);

    Setting::create(['key' => 'sandbox_mode', 'value' => '1', 'type' => 'boolean', 'group' => 'testing']);
    app(SettingsService::class)->clearCache();

    $account = createFedexAccount(
        [
            'child_key' => 'production-child-key',
            'child_secret' => 'production-child-secret',
            'sandbox_api_key' => 'sandbox-parent-key',
            'sandbox_api_secret' => 'sandbox-parent-secret',
        ],
        ['child_env' => 'production'],
    );

    Saloon::fake([
        'https://apis-sandbox.fedex.com/oauth/token' => MockResponse::make([
            'access_token' => 'sandbox-parent-access-token',
            'token_type' => 'bearer',
            'expires_in' => 3600,
        ], 200),
    ]);

    $connector = FedexConnector::forAccount($account);
    $authenticator = $connector->getAccessToken();

    expect($connector->resolveBaseUrl())->toBe('https://apis-sandbox.fedex.com')
        ->and($authenticator->getAccessToken())->toBe('sandbox-parent-access-token');

    Saloon::assertSent(function (GetClientCredentialsTokenRequest $request): bool {
        return $request->body()->get('client_id') === 'sandbox-parent-key'
            && $request->body()->get('client_secret') === 'sandbox-parent-secret';
    });
    Http::assertNothingSent();
});

it('does not reuse a cached production child token after switching to sandbox', function (): void {
    Storage::fake();
    config(['services.fedex.sandbox_url' => 'https://apis-sandbox.fedex.com']);

    Setting::create(['key' => 'sandbox_mode', 'value' => '1', 'type' => 'boolean', 'group' => 'testing']);
    app(SettingsService::class)->clearCache();

    $account = createFedexAccount(
        [
            'child_key' => 'production-child-key',
            'child_secret' => 'production-child-secret',
            'sandbox_api_key' => 'sandbox-parent-key',
            'sandbox_api_secret' => 'sandbox-parent-secret',
        ],
        ['child_env' => 'production'],
    );

    Cache::put(
        'fedex_authenticator_child_production_'.hash('sha256', 'production-child-key'),
        ['access_token' => 'cached-production-child-token', 'refresh_token' => null, 'expires_at' => now()->addMinutes(30)->timestamp],
        now()->addMinutes(20),
    );

    $rateAuthorization = null;
    Saloon::fake([
        GetClientCredentialsTokenRequest::class => MockResponse::make([
            'access_token' => 'sandbox-parent-token',
            'token_type' => 'bearer',
            'expires_in' => 3600,
        ], 200),
        Rates::class => function (PendingRequest $pendingRequest) use (&$rateAuthorization): MockResponse {
            $rateAuthorization = $pendingRequest->headers()->get('Authorization');

            return MockResponse::make(['output' => ['rateReplyDetails' => []]], 200);
        },
    ]);

    FedexConnector::getAuthenticatedConnector($account)->send(new Rates);

    expect($rateAuthorization)->toBe('Bearer sandbox-parent-token');
});

it('does not share sandbox parent tokens between FedEx carrier accounts', function (): void {
    Storage::fake();
    Setting::create(['key' => 'sandbox_mode', 'value' => '1', 'type' => 'boolean', 'group' => 'testing']);
    app(SettingsService::class)->clearCache();

    $firstAccount = createFedexAccount(
        [
            'child_key' => 'first-production-child-key',
            'child_secret' => 'first-production-child-secret',
            'sandbox_api_key' => 'first-sandbox-key',
            'sandbox_api_secret' => 'first-sandbox-secret',
        ],
        ['child_env' => 'production'],
    );
    $secondAccount = CarrierAccount::factory()->fedex()->create([
        'carrier_id' => $firstAccount->carrier_id,
        'secret_credentials' => [
            'child_key' => 'second-production-child-key',
            'child_secret' => 'second-production-child-secret',
            'sandbox_api_key' => 'second-sandbox-key',
            'sandbox_api_secret' => 'second-sandbox-secret',
        ],
        'credentials' => [
            'account_number' => 'second-production-account',
            'child_env' => 'production',
            'sandbox_account_number' => 'second-sandbox-account',
        ],
    ]);

    $rateAuthorizations = [];
    Saloon::fake([
        GetClientCredentialsTokenRequest::class => function (PendingRequest $pendingRequest): MockResponse {
            $clientId = $pendingRequest->body()->all()['client_id'];

            return MockResponse::make([
                'access_token' => $clientId.'-access-token',
                'token_type' => 'bearer',
                'expires_in' => 3600,
            ], 200);
        },
        Rates::class => function (PendingRequest $pendingRequest) use (&$rateAuthorizations): MockResponse {
            $rateAuthorizations[] = $pendingRequest->headers()->get('Authorization');

            return MockResponse::make(['output' => ['rateReplyDetails' => []]], 200);
        },
    ]);

    FedexConnector::getAuthenticatedConnector($firstAccount)->send(new Rates);
    FedexConnector::getAuthenticatedConnector($secondAccount)->send(new Rates);

    expect($rateAuthorizations)->toBe([
        'Bearer first-sandbox-key-access-token',
        'Bearer second-sandbox-key-access-token',
    ]);
});

it('uses direct child authorization when broker mode is disabled', function (): void {
    Storage::fake();
    Http::fake([
        'https://apis-sandbox.fedex.com/oauth/token' => Http::response([
            'access_token' => 'child-access-token',
            'token_type' => 'bearer',
            'expires_in' => 3600,
        ], 200),
    ]);

    config([
        'services.oauth.broker_url' => null,
        'services.oauth.instance_id' => null,
        'services.oauth.broker_secret' => null,
        'services.fedex.sandbox_url' => 'https://apis-sandbox.fedex.com',
        'services.fedex.sandbox_api_key' => 'parent-sandbox-key',
        'services.fedex.sandbox_api_secret' => 'parent-sandbox-secret',
    ]);

    Setting::create(['key' => 'sandbox_mode', 'value' => '1', 'type' => 'boolean', 'group' => 'testing']);
    app(SettingsService::class)->clearCache();

    $account = createFedexAccount(
        [
            'child_key' => 'child-key-123',
            'child_secret' => 'child-secret-456',
            'sandbox_api_key' => 'parent-sandbox-key',
            'sandbox_api_secret' => 'parent-sandbox-secret',
        ],
        ['child_env' => 'sandbox'],
    );

    $authenticator = FedexConnector::forAccount($account)->getAccessToken();

    expect($authenticator->getAccessToken())->toBe('child-access-token');

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://apis-sandbox.fedex.com/oauth/token'
            && $request['grant_type'] === 'csp_credentials'
            && $request['client_id'] === 'parent-sandbox-key'
            && $request['client_secret'] === 'parent-sandbox-secret'
            && $request['child_key'] === 'child-key-123'
            && $request['child_secret'] === 'child-secret-456';
    });

    Storage::assertExists('fedex-mfa/latest/child-authorization/request.json');
    Storage::assertExists('fedex-mfa/latest/child-authorization/response.json');

    $requestArtifact = json_decode(Storage::get('fedex-mfa/latest/child-authorization/request.json'), true);
    $responseArtifact = json_decode(Storage::get('fedex-mfa/latest/child-authorization/response.json'), true);

    expect(data_get($requestArtifact, 'transport'))->toBe('direct')
        ->and(data_get($requestArtifact, 'body.grant_type'))->toBe('csp_credentials')
        ->and(data_get($requestArtifact, 'body.client_id'))->toBe('[REDACTED]')
        ->and(data_get($requestArtifact, 'body.client_secret'))->toBe('[REDACTED]')
        ->and(data_get($requestArtifact, 'body.child_key'))->toBe('[REDACTED]')
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
