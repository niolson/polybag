<?php

use App\Http\Integrations\Shopify\Requests\GraphQL;
use App\Models\DataSourceLocation;
use App\Models\Location;
use App\Models\Shipment;
use App\Services\ShopifyFulfillmentOrderActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::put('shopify_access_token_'.md5('test-shop.myshopify.com'), 'shpat_test_token', 3600);
});

test('it activates the one-way fulfillment order import after preflight succeeds', function (): void {
    $source = createShopifyDataSource();
    DataSourceLocation::factory()->create([
        'data_source_id' => $source,
        'location_id' => Location::factory()->create(),
    ]);
    fakeShopifyAccessScopes(ShopifyFulfillmentOrderActivationService::REQUIRED_SCOPES);

    app(ShopifyFulfillmentOrderActivationService::class)->activate($source);

    $settings = $source->refresh()->settings;

    expect($settings['fulfillment_order_import_enabled'])->toBeTrue()
        ->and($settings['authoritative_shipment_items'])->toBeTrue();
});

test('it reports missing shopify scopes before activation', function (): void {
    $source = createShopifyDataSource();
    fakeShopifyAccessScopes(['read_orders']);

    expect(fn () => app(ShopifyFulfillmentOrderActivationService::class)->activate($source))
        ->toThrow(DomainException::class, 'read_locations');
});

test('it refuses activation to a token without read_products, naming it', function (): void {
    $source = createShopifyDataSource();
    fakeShopifyAccessScopes(array_values(array_diff(ShopifyFulfillmentOrderActivationService::REQUIRED_SCOPES, ['read_products'])));

    expect(fn () => app(ShopifyFulfillmentOrderActivationService::class)->activate($source))
        ->toThrow(DomainException::class, 'Reconnect Shopify with the required scopes before activation: read_products.');

    expect($source->refresh()->settings['fulfillment_order_import_enabled'] ?? false)->toBeFalse();
});

test('it activates without inspecting shipments created outside fulfillment-order import', function (): void {
    $source = createShopifyDataSource();
    DataSourceLocation::factory()->create([
        'data_source_id' => $source,
        'location_id' => Location::factory()->create(),
    ]);
    Shipment::factory()->create([
        'data_source_id' => $source,
        'source_record_id' => 'gid://shopify/Order/123',
        'status' => 'open',
    ]);
    fakeShopifyAccessScopes(ShopifyFulfillmentOrderActivationService::REQUIRED_SCOPES);

    app(ShopifyFulfillmentOrderActivationService::class)->activate($source);

    expect($source->refresh()->settings['fulfillment_order_import_enabled'])->toBeTrue();
});

/** @param list<string> $scopes */
function fakeShopifyAccessScopes(array $scopes): void
{
    Saloon::fake([
        GraphQL::class => MockResponse::make([
            'data' => [
                'currentAppInstallation' => [
                    'accessScopes' => collect($scopes)->map(fn (string $scope): array => ['handle' => $scope])->all(),
                ],
            ],
        ]),
    ]);
}
