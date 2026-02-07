<?php

use App\Http\Integrations\Shopify\Requests\GraphQL;
use App\Models\Channel;
use App\Models\Package;
use App\Models\Shipment;
use App\Services\ShipmentImport\PackageExportService;
use App\Services\ShipmentImport\ShipmentImportService;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Illuminate\Support\Facades\Cache;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

function shopifyOrdersResponse(array $nodes = [], bool $hasNextPage = false, ?string $endCursor = null): MockResponse
{
    return MockResponse::make([
        'data' => [
            'orders' => [
                'pageInfo' => [
                    'hasNextPage' => $hasNextPage,
                    'endCursor' => $endCursor,
                ],
                'nodes' => $nodes,
            ],
        ],
    ]);
}

function sampleOrder(string $name = '#1001', string $orderId = 'gid://shopify/Order/1001'): array
{
    return [
        'id' => $orderId,
        'name' => $name,
        'email' => 'test@example.com',
        'shippingAddress' => [
            'firstName' => 'Jane',
            'lastName' => 'Smith',
            'company' => null,
            'address1' => '456 Oak Ave',
            'address2' => null,
            'city' => 'Seattle',
            'provinceCode' => 'WA',
            'zip' => '98101',
            'countryCodeV2' => 'US',
            'phone' => '2065551234',
        ],
        'lineItems' => [
            'nodes' => [
                [
                    'sku' => 'SKU-100',
                    'name' => 'Test Product',
                    'quantity' => 3,
                    'unfulfilledQuantity' => 3,
                    'originalUnitPriceSet' => ['shopMoney' => ['amount' => '25.00']],
                    'variant' => [
                        'barcode' => '100100100100',
                        'inventoryItem' => [
                            'measurement' => [
                                'weight' => ['unit' => 'OUNCES', 'value' => 12.0],
                            ],
                        ],
                    ],
                ],
                [
                    'sku' => 'SKU-200',
                    'name' => 'Another Product',
                    'quantity' => 1,
                    'unfulfilledQuantity' => 1,
                    'originalUnitPriceSet' => ['shopMoney' => ['amount' => '10.00']],
                    'variant' => [
                        'barcode' => '200200200200',
                        'inventoryItem' => [
                            'measurement' => [
                                'weight' => ['unit' => 'GRAMS', 'value' => 150.0],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'fulfillmentOrders' => [
            'nodes' => [
                ['id' => 'gid://shopify/FulfillmentOrder/9001', 'status' => 'OPEN'],
            ],
        ],
    ];
}

function fulfillmentSuccessResponse(): MockResponse
{
    return MockResponse::make([
        'data' => [
            'fulfillmentCreate' => [
                'fulfillment' => [
                    'id' => 'gid://shopify/Fulfillment/1',
                    'status' => 'SUCCESS',
                    'trackingInfo' => ['company' => 'USPS', 'number' => 'TRACK123', 'url' => null],
                ],
                'userErrors' => [],
            ],
        ],
    ]);
}

beforeEach(function (): void {
    config([
        'services.shopify.shop_domain' => 'test-shop.myshopify.com',
        'services.shopify.client_id' => 'test-client-id',
        'services.shopify.client_secret' => 'test-client-secret',
        'services.shopify.api_version' => '2025-01',
    ]);

    Cache::put('shopify_access_token', 'shpat_test_token', 3600);
});

it('imports shopify orders into shipments table with metadata', function (): void {
    $channel = Channel::factory()->create(['name' => 'Shopify', 'channel_reference' => 'Shopify']);

    Saloon::fake([
        GraphQL::class => shopifyOrdersResponse([sampleOrder()]),
    ]);

    $source = new ShopifySource([
        'driver' => ShopifySource::class,
        'enabled' => true,
        'channel_name' => 'Shopify',
        'shipping_method' => null,
        'notify_customer' => false,
        'export' => ['enabled' => false, 'field_mapping' => []],
    ]);

    $result = ShipmentImportService::forSource($source)->import();

    expect($result->shipmentsCreated)->toBe(1);
    expect($result->itemsCreated)->toBe(2);
    expect($result->errors)->toBeEmpty();

    $shipment = Shipment::where('shipment_reference', '#1001')->first();
    expect($shipment)->not->toBeNull();
    expect($shipment->first_name)->toBe('Jane');
    expect($shipment->last_name)->toBe('Smith');
    expect($shipment->city)->toBe('Seattle');
    expect($shipment->state)->toBe('WA');
    expect($shipment->zip)->toBe('98101');
    expect($shipment->country)->toBe('US');
    expect($shipment->email)->toBe('test@example.com');
    expect($shipment->channel_id)->toBe($channel->id);

    // Metadata stored correctly
    expect($shipment->metadata)->toBeArray();
    expect($shipment->metadata['shopify_order_id'])->toBe('gid://shopify/Order/1001');
    expect($shipment->metadata['shopify_fulfillment_order_ids'])->toBe(['gid://shopify/FulfillmentOrder/9001']);

    // Items created
    expect($shipment->shipmentItems)->toHaveCount(2);
});

it('exports package to shopify as fulfillment', function (): void {
    $channel = Channel::factory()->create(['name' => 'Shopify']);

    $shipment = Shipment::factory()->create([
        'channel_id' => $channel->id,
        'shipment_reference' => '#1001',
        'metadata' => [
            'shopify_order_id' => 'gid://shopify/Order/1001',
            'shopify_fulfillment_order_ids' => ['gid://shopify/FulfillmentOrder/9001'],
        ],
    ]);

    $package = Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'tracking_number' => 'TRACK123',
        'carrier' => 'USPS',
        'service' => 'Priority Mail',
        'exported' => false,
    ]);

    Saloon::fake([
        GraphQL::class => fulfillmentSuccessResponse(),
    ]);

    config([
        'shipment-import.sources.shopify' => [
            'driver' => ShopifySource::class,
            'enabled' => true,
            'channel_name' => 'Shopify',
            'notify_customer' => false,
            'export' => [
                'enabled' => true,
                'field_mapping' => [
                    'tracking_number' => 'tracking_number',
                    'carrier' => 'carrier',
                    'shipment_reference' => 'shipment_reference',
                    'fulfillment_order_id' => 'fulfillment_order_id',
                ],
            ],
        ],
        'shipment-import.export_channel_map' => [
            'Shopify' => ['shopify'],
        ],
    ]);

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    expect($result->success)->toBeTrue();
    expect($result->destinationsAttempted)->toBe(1);
    expect($result->destinationsSucceeded)->toBe(1);
    expect($package->fresh()->exported)->toBeTrue();

    Saloon::assertSent(function (GraphQL $request) {
        $body = $request->body()->all();
        $fulfillment = $body['variables']['fulfillment'] ?? [];

        return ($fulfillment['trackingInfo']['number'] ?? '') === 'TRACK123'
            && ($fulfillment['trackingInfo']['company'] ?? '') === 'USPS'
            && ($fulfillment['lineItemsByFulfillmentOrder'][0]['fulfillmentOrderId'] ?? '') === 'gid://shopify/FulfillmentOrder/9001';
    });
});

it('handles package without metadata gracefully in export', function (): void {
    $channel = Channel::factory()->create(['name' => 'Shopify']);

    $shipment = Shipment::factory()->create([
        'channel_id' => $channel->id,
        'shipment_reference' => '#2001',
        'metadata' => null,
    ]);

    $package = Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'tracking_number' => 'TRACK456',
        'carrier' => 'USPS',
        'exported' => false,
    ]);

    config([
        'shipment-import.sources.shopify' => [
            'driver' => ShopifySource::class,
            'enabled' => true,
            'channel_name' => 'Shopify',
            'notify_customer' => false,
            'export' => [
                'enabled' => true,
                'field_mapping' => [
                    'tracking_number' => 'tracking_number',
                    'carrier' => 'carrier',
                    'shipment_reference' => 'shipment_reference',
                    'fulfillment_order_id' => 'fulfillment_order_id',
                ],
            ],
        ],
        'shipment-import.export_channel_map' => [
            'Shopify' => ['shopify'],
        ],
    ]);

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    // Should fail gracefully (no fulfillment order ID)
    expect($result->success)->toBeFalse();
    expect($result->errors)->not->toBeEmpty();
    expect($result->errors[0])->toContain('fulfillment order ID');
});

it('imports multiple pages of orders', function (): void {
    $channel = Channel::factory()->create(['name' => 'Shopify', 'channel_reference' => 'Shopify']);

    Saloon::fake([
        shopifyOrdersResponse(
            [sampleOrder('#1001', 'gid://shopify/Order/1001')],
            hasNextPage: true,
            endCursor: 'cursor_page1'
        ),
        shopifyOrdersResponse(
            [sampleOrder('#1002', 'gid://shopify/Order/1002')],
        ),
    ]);

    $source = new ShopifySource([
        'driver' => ShopifySource::class,
        'enabled' => true,
        'channel_name' => 'Shopify',
        'shipping_method' => null,
        'notify_customer' => false,
        'export' => ['enabled' => false, 'field_mapping' => []],
    ]);

    $result = ShipmentImportService::forSource($source)->import();

    expect($result->shipmentsCreated)->toBe(2);
    expect(Shipment::where('shipment_reference', '#1001')->exists())->toBeTrue();
    expect(Shipment::where('shipment_reference', '#1002')->exists())->toBeTrue();
});
