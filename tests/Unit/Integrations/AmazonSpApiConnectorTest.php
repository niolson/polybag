<?php

use App\Enums\AmazonSpApiRegion;
use App\Http\Integrations\Amazon\AmazonSpApiConnector;
use App\Http\Integrations\Amazon\Requests\ConfirmShipment;
use App\Http\Integrations\Amazon\Requests\GetMarketplaceParticipations;
use App\Http\Integrations\Amazon\Requests\GetShippingRates;
use App\Http\Integrations\Amazon\Requests\SearchOrders;
use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

// These build requests to read their URLs and never send one, so they carry
// no mock client.
beforeEach(fn () => TestCase::allowStraySaloonRequests());

function amazonConnector(): AmazonSpApiConnector
{
    // The connector reads its token from the cache before asking for a new one, and a
    // PendingRequest is built with headers attached, so seeding it keeps these tests
    // off the network.
    Cache::put('amazon_sp_api_access_token_'.md5('refresh-token'), 'access-token', 3600);

    return AmazonSpApiConnector::fromSettings([
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'refresh_token' => 'refresh-token',
        'auth_mode' => 'manual',
    ]);
}

function enableAmazonSandboxMode(): void
{
    Setting::create(['key' => 'sandbox_mode', 'value' => '1', 'type' => 'boolean', 'group' => 'testing']);
    app(SettingsService::class)->clearCache();
}

it('sends every API to the North America host in production', function (): void {
    $connector = amazonConnector();

    expect($connector->createPendingRequest(new SearchOrders)->getUrl())
        ->toBe('https://sellingpartnerapi-na.amazon.com/orders/2026-01-01/orders')
        ->and($connector->createPendingRequest(new GetShippingRates)->getUrl())
        ->toBe('https://sellingpartnerapi-na.amazon.com/shipping/v2/shipments/rates');
});

// The two APIs disagree about which sandbox host they need, which is the whole reason
// the host is resolved per request rather than once for the connector.
it('resolves the sandbox host per API from one connector', function (): void {
    enableAmazonSandboxMode();

    $connector = amazonConnector();

    expect($connector->createPendingRequest(new SearchOrders)->getUrl())
        ->toBe('https://sandbox.sellingpartnerapi-fe.amazon.com/orders/2026-01-01/orders')
        ->and($connector->createPendingRequest(new GetShippingRates)->getUrl())
        ->toBe('https://sandbox.sellingpartnerapi-na.amazon.com/shipping/v2/shipments/rates');
});

// Confirmation stays on NA even though the import it follows runs against FE: the two
// sandbox paths are unrelated fixtures, and the confirmShipment test case is a US one.
// AmazonImportExportTest asserts this on the URL the export path actually sends.
it('keeps a sandbox shipment confirmation on the North America host', function (): void {
    enableAmazonSandboxMode();

    expect(amazonConnector()->createPendingRequest(new ConfirmShipment('123-4567890-1234567'))->getUrl())
        ->toBe('https://sandbox.sellingpartnerapi-na.amazon.com/orders/v0/orders/123-4567890-1234567/shipmentConfirmation');
});

it('leaves a request that declares no region on the default sandbox host', function (): void {
    enableAmazonSandboxMode();

    expect(amazonConnector()->createPendingRequest(new GetMarketplaceParticipations)->getUrl())
        ->toBe('https://sandbox.sellingpartnerapi-na.amazon.com/sellers/v1/marketplaceParticipations');
});

it('keeps query parameters when a request moves region', function (): void {
    enableAmazonSandboxMode();

    $pendingRequest = amazonConnector()->createPendingRequest(new SearchOrders(['marketplaceIds' => 'A1VC38T7YXB528']));

    expect($pendingRequest->getUrl())->toBe('https://sandbox.sellingpartnerapi-fe.amazon.com/orders/2026-01-01/orders')
        ->and($pendingRequest->query()->all())->toBe(['marketplaceIds' => 'A1VC38T7YXB528']);
});

it('derives each region host from its region code', function (): void {
    expect(AmazonSpApiRegion::NorthAmerica->sandboxUrl())->toBe('https://sandbox.sellingpartnerapi-na.amazon.com')
        ->and(AmazonSpApiRegion::Europe->sandboxUrl())->toBe('https://sandbox.sellingpartnerapi-eu.amazon.com')
        ->and(AmazonSpApiRegion::FarEast->sandboxUrl())->toBe('https://sandbox.sellingpartnerapi-fe.amazon.com');
});
