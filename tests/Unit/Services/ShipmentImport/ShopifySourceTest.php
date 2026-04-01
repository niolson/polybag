<?php

use App\Http\Integrations\Shopify\Requests\GraphQL;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Illuminate\Support\Facades\Cache;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

function shopifyConfig(array $overrides = []): array
{
    return array_merge([
        'driver' => ShopifySource::class,
        'enabled' => true,
        'channel_name' => 'Shopify',
        'shipping_method' => null,
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
    ], $overrides);
}

function shopifyOrderNode(array $overrides = []): array
{
    return array_merge([
        'id' => 'gid://shopify/Order/1001',
        'name' => '#1001',
        'email' => 'customer@example.com',
        'shippingAddress' => [
            'firstName' => 'John',
            'lastName' => 'Doe',
            'company' => 'Acme Inc',
            'address1' => '123 Main St',
            'address2' => 'Apt 4',
            'city' => 'Portland',
            'provinceCode' => 'OR',
            'zip' => '97201',
            'countryCodeV2' => 'US',
            'phone' => '5035551234',
        ],
        'lineItems' => [
            'nodes' => [
                [
                    'sku' => 'WIDGET-001',
                    'name' => 'Blue Widget',
                    'quantity' => 2,
                    'unfulfilledQuantity' => 2,
                    'originalUnitPriceSet' => ['shopMoney' => ['amount' => '19.99']],
                    'variant' => [
                        'barcode' => '012345678901',
                        'inventoryItem' => [
                            'measurement' => [
                                'weight' => ['unit' => 'OUNCES', 'value' => 8.5],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'fulfillmentOrders' => [
            'nodes' => [
                ['id' => 'gid://shopify/FulfillmentOrder/5001', 'status' => 'OPEN'],
            ],
        ],
    ], $overrides);
}

function mockShopifyOrders(array $nodes, bool $hasNextPage = false, ?string $endCursor = null): MockResponse
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

beforeEach(function (): void {
    config([
        'services.shopify.shop_domain' => 'test-shop.myshopify.com',
        'services.shopify.client_id' => 'test-client-id',
        'services.shopify.client_secret' => 'test-client-secret',
        'services.shopify.api_version' => '2025-01',
    ]);

    // Pre-seed cached token so the connector doesn't make a real HTTP call
    Cache::put('shopify_access_token', 'shpat_test_token', 3600);
});

it('throws when shop domain is not configured', function (): void {
    config(['services.shopify.shop_domain' => null]);

    $source = new ShopifySource(shopifyConfig());
    $source->validateConfiguration();
})->throws(InvalidArgumentException::class, 'shop domain');

it('throws when client id is not configured', function (): void {
    config(['services.shopify.client_id' => null]);

    $source = new ShopifySource(shopifyConfig());
    $source->validateConfiguration();
})->throws(InvalidArgumentException::class, 'client ID');

it('throws when client secret is not configured', function (): void {
    config(['services.shopify.client_secret' => null]);

    $source = new ShopifySource(shopifyConfig());
    $source->validateConfiguration();
})->throws(InvalidArgumentException::class, 'client secret');

it('throws when channel name is not configured', function (): void {
    $source = new ShopifySource(shopifyConfig(['channel_name' => null]));
    $source->validateConfiguration();
})->throws(InvalidArgumentException::class, 'channel name');

it('maps shopify order to shipment data', function (): void {
    Saloon::fake([
        GraphQL::class => mockShopifyOrders([shopifyOrderNode()]),
    ]);

    $source = new ShopifySource(shopifyConfig());
    $shipments = $source->fetchShipments();

    expect($shipments)->toHaveCount(1);

    $shipment = $shipments->first();
    expect($shipment['shipment_reference'])->toBe('#1001')
        ->and($shipment['first_name'])->toBe('John')
        ->and($shipment['last_name'])->toBe('Doe')
        ->and($shipment['company'])->toBe('Acme Inc')
        ->and($shipment['address1'])->toBe('123 Main St')
        ->and($shipment['address2'])->toBe('Apt 4')
        ->and($shipment['city'])->toBe('Portland')
        ->and($shipment['state_or_province'])->toBe('OR')
        ->and($shipment['postal_code'])->toBe('97201')
        ->and($shipment['country'])->toBe('US')
        ->and($shipment['phone'])->toBe('5035551234')
        ->and($shipment['email'])->toBe('customer@example.com')
        ->and($shipment['value'])->toBe(39.98)
        ->and($shipment['channel_id'])->toBe('Shopify')
        ->and($shipment['metadata']['shopify_order_id'])->toBe('gid://shopify/Order/1001')
        ->and($shipment['metadata']['shopify_fulfillment_order_ids'])->toBe(['gid://shopify/FulfillmentOrder/5001']);
});

it('handles cursor pagination', function (): void {
    Saloon::fake([
        mockShopifyOrders([shopifyOrderNode()], hasNextPage: true, endCursor: 'cursor_abc'),
        mockShopifyOrders([shopifyOrderNode(['id' => 'gid://shopify/Order/1002', 'name' => '#1002'])]),
    ]);

    $source = new ShopifySource(shopifyConfig());
    $shipments = $source->fetchShipments();

    expect($shipments)->toHaveCount(2);
    expect($shipments[0]['shipment_reference'])->toBe('#1001');
    expect($shipments[1]['shipment_reference'])->toBe('#1002');

    Saloon::assertSentCount(2);
});

it('maps line items using unfulfilled quantity', function (): void {
    $order = shopifyOrderNode([
        'lineItems' => [
            'nodes' => [
                [
                    'sku' => 'WIDGET-001',
                    'name' => 'Blue Widget',
                    'quantity' => 5,
                    'unfulfilledQuantity' => 3,
                    'originalUnitPriceSet' => ['shopMoney' => ['amount' => '10.00']],
                    'variant' => [
                        'barcode' => '111111111111',
                        'inventoryItem' => [
                            'measurement' => [
                                'weight' => ['unit' => 'POUNDS', 'value' => 1.5],
                            ],
                        ],
                    ],
                ],
                [
                    'sku' => 'WIDGET-002',
                    'name' => 'Red Widget',
                    'quantity' => 2,
                    'unfulfilledQuantity' => 0,
                    'originalUnitPriceSet' => ['shopMoney' => ['amount' => '15.00']],
                    'variant' => [
                        'barcode' => '222222222222',
                        'inventoryItem' => [
                            'measurement' => [
                                'weight' => ['unit' => 'OUNCES', 'value' => 4.0],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    Saloon::fake([
        GraphQL::class => mockShopifyOrders([$order]),
    ]);

    $source = new ShopifySource(shopifyConfig());
    $source->fetchShipments();

    $items = $source->fetchShipmentItems('gid://shopify/Order/1001');

    // Only unfulfilled items (Red Widget has 0 unfulfilled, should be excluded)
    expect($items)->toHaveCount(1);
    expect($items[0]['sku'])->toBe('WIDGET-001');
    expect($items[0]['quantity'])->toBe(3);
    expect($items[0]['value'])->toBe(10.0);
    expect($items[0]['barcode'])->toBe('111111111111');
    expect($items[0]['weight'])->toBe(1.5); // 1.5 lbs (stored as pounds)
});

it('returns empty collection for unknown shipment reference', function (): void {
    Saloon::fake([
        GraphQL::class => mockShopifyOrders([shopifyOrderNode()]),
    ]);

    $source = new ShopifySource(shopifyConfig());
    $source->fetchShipments();

    $items = $source->fetchShipmentItems('#9999');

    expect($items)->toBeEmpty();
});

it('filters fulfillment orders to OPEN and IN_PROGRESS only', function (): void {
    $order = shopifyOrderNode([
        'fulfillmentOrders' => [
            'nodes' => [
                ['id' => 'gid://shopify/FulfillmentOrder/5001', 'status' => 'OPEN'],
                ['id' => 'gid://shopify/FulfillmentOrder/5002', 'status' => 'CLOSED'],
                ['id' => 'gid://shopify/FulfillmentOrder/5003', 'status' => 'IN_PROGRESS'],
            ],
        ],
    ]);

    Saloon::fake([
        GraphQL::class => mockShopifyOrders([$order]),
    ]);

    $source = new ShopifySource(shopifyConfig());
    $shipments = $source->fetchShipments();

    $metadata = $shipments->first()['metadata'];
    expect($metadata['shopify_fulfillment_order_ids'])->toBe([
        'gid://shopify/FulfillmentOrder/5001',
        'gid://shopify/FulfillmentOrder/5003',
    ]);
});

it('sends fulfillment mutation with correct carrier mapping', function (): void {
    Saloon::fake([
        GraphQL::class => MockResponse::make([
            'data' => [
                'fulfillmentCreate' => [
                    'fulfillment' => [
                        'id' => 'gid://shopify/Fulfillment/1',
                        'status' => 'SUCCESS',
                        'trackingInfo' => [
                            'company' => 'USPS',
                            'number' => '9400111899223456789012',
                            'url' => null,
                        ],
                    ],
                    'userErrors' => [],
                ],
            ],
        ]),
    ]);

    $source = new ShopifySource(shopifyConfig());
    $source->exportPackage([
        'tracking_number' => '9400111899223456789012',
        'carrier' => 'USPS',
        'shipment_reference' => '#1001',
        'fulfillment_order_id' => 'gid://shopify/FulfillmentOrder/5001',
    ]);

    Saloon::assertSent(function (GraphQL $request) {
        $body = $request->body()->all();
        $fulfillment = $body['variables']['fulfillment'];

        return $fulfillment['trackingInfo']['company'] === 'USPS'
            && $fulfillment['trackingInfo']['number'] === '9400111899223456789012'
            && $fulfillment['lineItemsByFulfillmentOrder'][0]['fulfillmentOrderId'] === 'gid://shopify/FulfillmentOrder/5001'
            && $fulfillment['notifyCustomer'] === false;
    });
});

it('throws when fulfillment has no fulfillment order id', function (): void {
    $source = new ShopifySource(shopifyConfig());
    $source->exportPackage([
        'tracking_number' => '1234',
        'carrier' => 'USPS',
        'shipment_reference' => '#1001',
        'fulfillment_order_id' => null,
    ]);
})->throws(InvalidArgumentException::class, 'fulfillment order ID');

it('throws on shopify user errors', function (): void {
    Saloon::fake([
        GraphQL::class => MockResponse::make([
            'data' => [
                'fulfillmentCreate' => [
                    'fulfillment' => null,
                    'userErrors' => [
                        ['field' => 'trackingInfo', 'message' => 'Invalid tracking number'],
                    ],
                ],
            ],
        ]),
    ]);

    $source = new ShopifySource(shopifyConfig());
    $source->exportPackage([
        'tracking_number' => 'BAD',
        'carrier' => 'USPS',
        'shipment_reference' => '#1001',
        'fulfillment_order_id' => 'gid://shopify/FulfillmentOrder/5001',
    ]);
})->throws(RuntimeException::class, 'Invalid tracking number');

it('throws on shopify graphql errors during fetch', function (): void {
    Saloon::fake([
        GraphQL::class => MockResponse::make([
            'errors' => [
                ['message' => 'Throttled'],
            ],
        ]),
    ]);

    $source = new ShopifySource(shopifyConfig());
    $source->fetchShipments();
})->throws(RuntimeException::class, 'Throttled');

it('maps FedEx carrier name correctly', function (): void {
    Saloon::fake([
        GraphQL::class => MockResponse::make([
            'data' => [
                'fulfillmentCreate' => [
                    'fulfillment' => [
                        'id' => 'gid://shopify/Fulfillment/2',
                        'status' => 'SUCCESS',
                        'trackingInfo' => ['company' => 'FedEx', 'number' => '7946', 'url' => null],
                    ],
                    'userErrors' => [],
                ],
            ],
        ]),
    ]);

    $source = new ShopifySource(shopifyConfig());
    $source->exportPackage([
        'tracking_number' => '7946',
        'carrier' => 'FedEx',
        'shipment_reference' => '#1001',
        'fulfillment_order_id' => 'gid://shopify/FulfillmentOrder/5001',
    ]);

    Saloon::assertSent(function (GraphQL $request) {
        $body = $request->body()->all();

        return $body['variables']['fulfillment']['trackingInfo']['company'] === 'FedEx';
    });
});

it('markExported is a no-op that returns false', function (): void {
    $source = new ShopifySource(shopifyConfig());

    // Should not throw or make any API calls
    $result = $source->markExported('#1001');

    expect($result)->toBeFalse();
    Saloon::assertNothingSent();
});

it('respects notify_customer config', function (): void {
    Saloon::fake([
        GraphQL::class => MockResponse::make([
            'data' => [
                'fulfillmentCreate' => [
                    'fulfillment' => [
                        'id' => 'gid://shopify/Fulfillment/3',
                        'status' => 'SUCCESS',
                        'trackingInfo' => ['company' => 'USPS', 'number' => '1234', 'url' => null],
                    ],
                    'userErrors' => [],
                ],
            ],
        ]),
    ]);

    $source = new ShopifySource(shopifyConfig(['notify_customer' => true]));
    $source->exportPackage([
        'tracking_number' => '1234',
        'carrier' => 'USPS',
        'shipment_reference' => '#1001',
        'fulfillment_order_id' => 'gid://shopify/FulfillmentOrder/5001',
    ]);

    Saloon::assertSent(function (GraphQL $request) {
        $body = $request->body()->all();

        return $body['variables']['fulfillment']['notifyCustomer'] === true;
    });
});
