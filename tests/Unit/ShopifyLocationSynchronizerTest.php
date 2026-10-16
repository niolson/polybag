<?php

use App\Http\Integrations\Shopify\Requests\GraphQL;
use App\Models\DataSourceLocation;
use App\Models\Location;
use App\Models\Shipment;
use App\Services\SettingsService;
use App\Services\ShopifyLocationSynchronizer;
use Illuminate\Support\Facades\Cache;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

beforeEach(function (): void {
    Cache::put('shopify_access_token_'.md5('test-shop.myshopify.com'), 'shpat_test_token', 3600);
});

it('synchronizes active shopify locations and preserves an existing mapping', function (): void {
    $source = createShopifyDataSource();
    $polyBagLocation = Location::factory()->create();
    DataSourceLocation::factory()->create([
        'data_source_id' => $source,
        'external_id' => 'gid://shopify/Location/1',
        'name' => 'Old name',
        'location_id' => $polyBagLocation,
    ]);
    DataSourceLocation::factory()->create([
        'data_source_id' => $source,
        'external_id' => 'gid://shopify/Location/stale',
    ]);

    Saloon::fake([
        shopifyAccessScopesResponse(),
        MockResponse::make([
            'data' => ['locations' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'nodes' => [[
                    'id' => 'gid://shopify/Location/1',
                    'name' => 'Main Warehouse',
                    'isActive' => true,
                    'address' => ['city' => 'Seattle', 'countryCode' => 'US'],
                ]],
            ]],
        ]),
    ]);

    $result = app(ShopifyLocationSynchronizer::class)->synchronize($source);

    expect($result)->toBe(['synced' => 1, 'deactivated' => 1, 'auto_mapped' => 0]);
    $synchronizedLocation = DataSourceLocation::where('external_id', 'gid://shopify/Location/1')->firstOrFail();
    expect($synchronizedLocation->name)->toBe('Main Warehouse')
        ->and($synchronizedLocation->location_id)->toBe($polyBagLocation->id)
        ->and($synchronizedLocation->is_active)->toBeTrue();
    expect(DataSourceLocation::where('external_id', 'gid://shopify/Location/stale')->first()->is_active)
        ->toBeFalse();
});

it('auto maps a sole shopify location to the default in single-location mode', function (): void {
    $source = createShopifyDataSource();
    $defaultLocation = Location::getDefault();
    $sourceLocation = DataSourceLocation::factory()->create([
        'data_source_id' => $source,
        'external_id' => 'gid://shopify/Location/1',
        'location_id' => null,
    ]);
    $shipment = Shipment::factory()->create([
        'data_source_id' => $source,
        'data_source_location_id' => $sourceLocation,
        'location_id' => null,
        'status' => 'open',
    ]);

    Saloon::fake([
        shopifyAccessScopesResponse(),
        MockResponse::make([
            'data' => ['locations' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'nodes' => [[
                    'id' => 'gid://shopify/Location/1',
                    'name' => 'Only Warehouse',
                    'isActive' => true,
                    'address' => null,
                ]],
            ]],
        ]),
    ]);

    app(ShopifyLocationSynchronizer::class)->synchronize($source);

    expect($source->locations()->first()->location_id)->toBe($defaultLocation->id)
        ->and($shipment->refresh()->location_id)->toBe($defaultLocation->id);
});

it('paginates the Shopify location catalog', function (): void {
    $source = createShopifyDataSource();
    Saloon::fake([
        shopifyAccessScopesResponse(),
        MockResponse::make(['data' => ['locations' => [
            'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'page-2'],
            'nodes' => [['id' => 'gid://shopify/Location/1', 'name' => 'East', 'isActive' => true, 'address' => null]],
        ]]]),
        MockResponse::make(['data' => ['locations' => [
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            'nodes' => [['id' => 'gid://shopify/Location/2', 'name' => 'West', 'isActive' => true, 'address' => null]],
        ]]]),
    ]);

    $result = app(ShopifyLocationSynchronizer::class)->synchronize($source);

    expect($result['synced'])->toBe(2)
        ->and($source->locations()->count())->toBe(2);
    Saloon::assertSentCount(3);
});

it('refuses to synchronize when the live token is missing fulfillment-order scopes', function (): void {
    $source = createShopifyDataSource();
    Saloon::fake([
        shopifyAccessScopesResponse(['read_orders', 'read_locations']),
    ]);

    expect(fn () => app(ShopifyLocationSynchronizer::class)->synchronize($source))
        ->toThrow(DomainException::class, 'write_merchant_managed_fulfillment_orders');
});

it('synchronizes when the live token has the scopes but the cached oauth_scopes are stale', function (): void {
    // Shopify ignores the requested scope parameter for apps that declare their
    // scopes, so `oauth_scopes` can be empty or stale while the token is fine.
    // Trusting that cache used to block synchronizing entirely.
    $source = createShopifyDataSource([
        'auth_mode' => 'authorization_code',
        'oauth_scopes' => '',
    ]);

    Saloon::fake([
        shopifyAccessScopesResponse(),
        MockResponse::make(['data' => ['locations' => [
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            'nodes' => [['id' => 'gid://shopify/Location/1', 'name' => 'Shop location', 'isActive' => true, 'address' => null]],
        ]]]),
    ]);

    $result = app(ShopifyLocationSynchronizer::class)->synchronize($source);

    expect($result['synced'])->toBe(1);
});

it('surfaces Shopify authentication and GraphQL failures without changing mappings', function (): void {
    $source = createShopifyDataSource();
    Saloon::fake([
        GraphQL::class => MockResponse::make(['errors' => [['message' => 'Access denied']]], 200),
    ]);

    expect(fn () => app(ShopifyLocationSynchronizer::class)->synchronize($source))
        ->toThrow(RuntimeException::class, 'Access denied');
    expect($source->locations()->count())->toBe(0);
});

it('maps a sole shopify location to the only active PolyBag location in multi-location mode', function (int $otherActive, bool $mapped): void {
    app(SettingsService::class)->set('multi_location_enabled', true, 'boolean');
    $source = createShopifyDataSource();
    Location::factory()->count($otherActive)->create(['active' => true]);
    Location::factory()->create(['active' => false]);

    Saloon::fake([
        shopifyAccessScopesResponse(),
        MockResponse::make(['data' => ['locations' => [
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            'nodes' => [['id' => 'gid://shopify/Location/1', 'name' => 'Only Warehouse', 'isActive' => true, 'address' => null]],
        ]]]),
    ]);

    $result = app(ShopifyLocationSynchronizer::class)->synchronize($source);

    expect($result['auto_mapped'])->toBe($mapped ? 1 : 0)
        ->and($source->locations()->sole()->location_id)->toBe($mapped ? Location::getDefault()->id : null);
})->with([
    'the default is the only active one' => [0, true],
    'two are active' => [1, false],
]);

it('leaves several shopify locations unmapped even with one PolyBag location', function (): void {
    $source = createShopifyDataSource();

    Saloon::fake([
        shopifyAccessScopesResponse(),
        MockResponse::make(['data' => ['locations' => [
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            'nodes' => [
                ['id' => 'gid://shopify/Location/1', 'name' => 'East', 'isActive' => true, 'address' => null],
                ['id' => 'gid://shopify/Location/2', 'name' => 'West', 'isActive' => true, 'address' => null],
            ],
        ]]]),
    ]);

    $result = app(ShopifyLocationSynchronizer::class)->synchronize($source);

    expect($result['auto_mapped'])->toBe(0)
        ->and($source->locations()->whereNotNull('location_id')->count())->toBe(0);
});
