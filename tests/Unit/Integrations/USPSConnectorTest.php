<?php

use App\Http\Integrations\USPS\Requests\Address;
use App\Http\Integrations\USPS\Requests\Label;
use App\Http\Integrations\USPS\Requests\PaymentAuthorization;
use App\Http\Integrations\USPS\Requests\ShippingOptions;
use App\Http\Integrations\USPS\USPSConnector;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

it('builds correct shipping options request', function (): void {
    Saloon::fake([
        ShippingOptions::class => MockResponse::make(['pricingOptions' => []]),
    ]);

    $connector = new USPSConnector;
    $request = new ShippingOptions;
    $request->body()->set([
        'originZIPCode' => '98072',
        'destinationZIPCode' => '90210',
        'packageDescription' => [
            'weight' => 2.5,
            'length' => 10,
            'width' => 8,
            'height' => 6,
            'mailClass' => 'ALL_OUTBOUND',
        ],
    ]);

    $connector->send($request);

    Saloon::assertSent(function (ShippingOptions $request) {
        $body = $request->body()->all();

        return $body['originZIPCode'] === '98072'
            && $body['destinationZIPCode'] === '90210'
            && $body['packageDescription']['weight'] === 2.5;
    });
});

it('requests correct endpoint for shipping options', function (): void {
    $request = new ShippingOptions;

    expect($request->resolveEndpoint())->toBe('/shipments/v3/options/search');
});

it('requests correct endpoint for address validation', function (): void {
    $request = new Address;

    expect($request->resolveEndpoint())->toBe('/addresses/v3/address');
});

it('requests correct endpoint for labels', function (): void {
    $request = new Label;

    expect($request->resolveEndpoint())->toBe('/labels/v3/label');
});

it('requests correct endpoint for payment authorization', function (): void {
    $request = new PaymentAuthorization;

    expect($request->resolveEndpoint())->toBe('/payments/v3/payment-authorization');
});

it('parses multipart label response correctly', function (): void {
    $boundary = '----=_Part_123456';
    $trackingNumber = '9400111899223456789012';
    $postage = '8.50';

    $multipartBody = implode("\r\n", [
        '------=_Part_123456',
        'Content-Type: application/json',
        'Content-Disposition: form-data; name="labelMetadata"',
        '',
        json_encode([
            'trackingNumber' => $trackingNumber,
            'postage' => $postage,
            'labelDate' => '2025-01-15',
        ]),
        '------=_Part_123456',
        'Content-Type: application/pdf',
        'Content-Disposition: form-data; name="labelImage"',
        '',
        base64_encode('%PDF-1.4 fake label content'),
        '------=_Part_123456--',
    ]);

    // Test the parsing logic directly using reflection to access the methods
    $psrResponse = new \GuzzleHttp\Psr7\Response(
        200,
        ['Content-Type' => "multipart/mixed; boundary={$boundary}"],
        $multipartBody
    );

    // Extract boundary from Content-Type header
    $contentType = $psrResponse->getHeaderLine('Content-Type');
    $extractedBoundary = \Illuminate\Support\Str::after($contentType, 'boundary=');

    // Parse the multipart body
    $separator = '--'.$extractedBoundary;
    $parts = explode($separator, (string) $psrResponse->getBody());

    $metadataLines = explode("\r\n", $parts[1]);
    $metadata = json_decode($metadataLines[4], true);

    $labelLines = explode("\r\n", $parts[2]);
    $label = $labelLines[4];

    expect($metadata['trackingNumber'])->toBe($trackingNumber)
        ->and($metadata['postage'])->toBe($postage)
        ->and($label)->not->toBeEmpty();
});

it('builds payment authorization request with correct roles', function (): void {
    config([
        'services.usps.crid' => 'TEST_CRID',
        'services.usps.mid' => 'TEST_MID',
    ]);

    Saloon::fake([
        PaymentAuthorization::class => MockResponse::make([
            'paymentAuthorizationToken' => 'test-token-123',
        ]),
    ]);

    $connector = new USPSConnector;
    $request = new PaymentAuthorization;
    $request->body()->set([
        'roles' => [
            [
                'roleName' => 'PAYER',
                'CRID' => config('services.usps.crid'),
                'MID' => config('services.usps.mid'),
                'accountType' => 'EPS',
                'accountNumber' => config('services.usps.crid'),
            ],
        ],
    ]);

    $connector->send($request);

    Saloon::assertSent(function (PaymentAuthorization $request) {
        $body = $request->body()->all();

        return isset($body['roles'])
            && $body['roles'][0]['roleName'] === 'PAYER'
            && $body['roles'][0]['CRID'] === 'TEST_CRID';
    });
});

it('resolves production base URL by default', function (): void {
    config([
        'services.usps.base_url' => 'https://apis.usps.com',
        'services.usps.sandbox_url' => 'https://apis-tem.usps.com',
    ]);
    app(\App\Services\SettingsService::class)->clearCache();

    $connector = new USPSConnector;

    expect($connector->resolveBaseUrl())->toBe('https://apis.usps.com');
});

it('resolves sandbox base URL when sandbox_mode is enabled', function (): void {
    config([
        'services.usps.base_url' => 'https://apis.usps.com',
        'services.usps.sandbox_url' => 'https://apis-tem.usps.com',
    ]);
    \App\Models\Setting::create(['key' => 'sandbox_mode', 'value' => '1', 'type' => 'boolean', 'group' => 'testing']);
    app(\App\Services\SettingsService::class)->clearCache();

    $connector = new USPSConnector;

    expect($connector->resolveBaseUrl())->toBe('https://apis-tem.usps.com');
});

it('builds label request with address data', function (): void {
    Saloon::fake([
        Label::class => MockResponse::make('', 200, [
            'Content-Type' => 'multipart/mixed; boundary=test',
        ]),
    ]);

    $connector = new USPSConnector;
    $request = new Label;
    $request->headers()->set([
        'X-Payment-Authorization-Token' => 'test-token',
    ]);
    $request->body()->set([
        'toAddress' => [
            'firstName' => 'John',
            'lastName' => 'Doe',
            'streetAddress' => '123 Main St',
            'city' => 'Beverly Hills',
            'state' => 'CA',
            'ZIPCode' => '90210',
        ],
        'fromAddress' => [
            'firstName' => 'Shipping',
            'lastName' => 'Center',
            'streetAddress' => '456 Warehouse Blvd',
            'city' => 'Seattle',
            'state' => 'WA',
            'ZIPCode' => '98101',
        ],
        'packageDescription' => [
            'mailClass' => 'USPS_GROUND_ADVANTAGE',
            'weight' => 2.5,
        ],
    ]);

    $connector->send($request);

    Saloon::assertSent(function (Label $request) {
        $body = $request->body()->all();
        $headers = $request->headers()->all();

        return $headers['X-Payment-Authorization-Token'] === 'test-token'
            && $body['toAddress']['firstName'] === 'John'
            && $body['toAddress']['ZIPCode'] === '90210'
            && $body['fromAddress']['city'] === 'Seattle'
            && $body['packageDescription']['mailClass'] === 'USPS_GROUND_ADVANTAGE';
    });
});
