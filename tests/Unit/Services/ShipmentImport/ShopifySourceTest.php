<?php

use App\Exceptions\PermanentExportException;
use App\Http\Integrations\Shopify\Requests\GraphQL;
use App\Services\ShipmentImport\Sources\ShopifySource;
use App\Services\ShopifyFulfillmentOrderActivationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

function shopifyConfig(array $overrides = []): array
{
    return array_merge([
        'source_type' => ShopifySource::class,
        'enabled' => true,
        'channel_name' => 'Shopify',
        'shop_domain' => 'test-shop.myshopify.com',
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
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

function shopifyFulfillmentOrderNode(array $overrides = []): array
{
    return array_merge([
        'id' => 'gid://shopify/FulfillmentOrder/7001',
        'status' => 'OPEN',
        'order' => [
            'id' => 'gid://shopify/Order/1001',
            'name' => '#1001',
            'email' => 'customer@example.com',
        ],
        'destination' => [
            'firstName' => 'John',
            'lastName' => 'Doe',
            'company' => 'Acme Inc',
            'address1' => '123 Main St',
            'address2' => 'Apt 4',
            'city' => 'Portland',
            'province' => 'OR',
            'zip' => '97201',
            'countryCode' => 'US',
            'phone' => '5035551234',
        ],
        'assignedLocation' => [
            'name' => 'Main Warehouse',
            'location' => [
                'id' => 'gid://shopify/Location/1',
                'name' => 'Main Warehouse',
                'isActive' => true,
                'address' => ['city' => 'Portland', 'countryCode' => 'US'],
            ],
        ],
        'lineItems' => [
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            'nodes' => [
                [
                    'id' => 'gid://shopify/FulfillmentOrderLineItem/1',
                    'sku' => 'WIDGET-001',
                    'productTitle' => 'Blue Widget',
                    'remainingQuantity' => 2,
                    'requiresShipping' => true,
                    'weight' => ['unit' => 'OUNCES', 'value' => 8.5],
                    'variant' => [
                        'id' => 'gid://shopify/ProductVariant/1',
                        'barcode' => '012345678901',
                    ],
                    'lineItem' => ['originalUnitPriceSet' => ['shopMoney' => ['amount' => '19.99']]],
                ],
            ],
        ],
    ], $overrides);
}

function mockShopifyFulfillmentOrders(array $nodes, bool $hasNextPage = false, ?string $endCursor = null): MockResponse
{
    return MockResponse::make([
        'data' => [
            'fulfillmentOrders' => [
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
    // Pre-seed cached token so the connector doesn't make a real HTTP call.
    // Key format: shopify_access_token_ + md5(shop_domain)
    Cache::put('shopify_access_token_'.md5('test-shop.myshopify.com'), 'shpat_test_token', 3600);
});

it('throws when shop domain is not configured', function (): void {
    $source = new ShopifySource(shopifyConfig(['shop_domain' => null]));
    $source->validateConfiguration();
})->throws(InvalidArgumentException::class, 'shop domain');

it('throws when client id is not configured', function (): void {
    $source = new ShopifySource(shopifyConfig(['client_id' => null]));
    $source->validateConfiguration();
})->throws(InvalidArgumentException::class, 'credentials are not configured');

it('throws when client secret is not configured', function (): void {
    $source = new ShopifySource(shopifyConfig(['client_secret' => null]));
    $source->validateConfiguration();
})->throws(InvalidArgumentException::class, 'credentials are not configured');

it('throws when channel name is not configured', function (): void {
    $source = new ShopifySource(shopifyConfig(['channel_name' => null]));
    $source->validateConfiguration();
})->throws(InvalidArgumentException::class, 'channel name');

it('blocks shipment imports until fulfillment-order import is activated', function (): void {
    $source = new ShopifySource(shopifyConfig());

    expect(fn (): Collection => $source->fetchShipments())
        ->toThrow(DomainException::class, 'activate fulfillment-order imports');

    Saloon::assertNothingSent();
});

it('refuses an import when the token lacks read_products, before fetching any fulfillment order', function (): void {
    Saloon::fake([
        GraphQL::class => shopifyAccessScopesResponse(array_values(array_diff(
            ShopifyFulfillmentOrderActivationService::REQUIRED_SCOPES,
            ['read_products'],
        ))),
    ]);

    $source = new ShopifySource(shopifyConfig(['fulfillment_order_import_enabled' => true]));

    expect(fn (): Collection => $source->fetchShipments())
        ->toThrow(DomainException::class, 'Reconnect Shopify with the required scopes before importing: read_products.');

    Saloon::assertSentCount(1);
});

it('maps shopify fulfillment order to shipment data', function (): void {
    Saloon::fake([
        GraphQL::class => answeringShopifyScopes(mockShopifyFulfillmentOrders([shopifyFulfillmentOrderNode()])),
    ]);

    $source = new ShopifySource(shopifyConfig(['fulfillment_order_import_enabled' => true]));
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
        ->and($shipment['metadata']['shopify_fulfillment_order_id'])->toBe('gid://shopify/FulfillmentOrder/7001');
});

it('maps fulfillment orders as location-aware shipments', function (): void {
    Saloon::fake([
        GraphQL::class => answeringShopifyScopes(MockResponse::make([
            'data' => ['fulfillmentOrders' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'nodes' => [[
                    'id' => 'gid://shopify/FulfillmentOrder/7001',
                    'status' => 'OPEN',
                    'order' => ['id' => 'gid://shopify/Order/1001', 'name' => '#1001', 'email' => 'customer@example.com'],
                    'destination' => [
                        'firstName' => 'Jane', 'lastName' => 'Smith', 'address1' => '123 Main St',
                        'city' => 'Seattle', 'provinceCode' => 'WA', 'zip' => '98101', 'countryCode' => 'US',
                    ],
                    'assignedLocation' => [
                        'name' => 'West Warehouse',
                        'location' => [
                            'id' => 'gid://shopify/Location/11', 'name' => 'West Warehouse', 'isActive' => true,
                            'address' => ['city' => 'Seattle', 'countryCode' => 'US'],
                        ],
                    ],
                    'lineItems' => [
                        'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                        'nodes' => [[
                            'id' => 'gid://shopify/FulfillmentOrderLineItem/1',
                            'sku' => 'SKU-1', 'productTitle' => 'Widget', 'remainingQuantity' => 2,
                            'requiresShipping' => true, 'weight' => ['unit' => 'OUNCES', 'value' => 8],
                            'variant' => ['id' => 'gid://shopify/ProductVariant/9', 'barcode' => '123'],
                            'lineItem' => ['originalUnitPriceSet' => ['shopMoney' => ['amount' => '12.50']]],
                        ]],
                    ],
                ]],
            ]],
        ])),
    ]);

    $source = new ShopifySource(shopifyConfig(['fulfillment_order_import_enabled' => true]));
    $shipments = $source->fetchShipments();
    $shipment = $shipments->sole();

    expect($shipment['source_record_id'])->toBe('gid://shopify/FulfillmentOrder/7001')
        ->and($shipment['shipment_reference'])->toBe('#1001')
        ->and($shipment['source_location']['external_id'])->toBe('gid://shopify/Location/11')
        ->and($shipment['metadata']['shopify_fulfillment_order_id'])->toBe('gid://shopify/FulfillmentOrder/7001')
        ->and($shipment['value'])->toBe(25.0);

    expect($source->fetchShipmentItems('gid://shopify/FulfillmentOrder/7001')->sole())
        ->toMatchArray(['sku' => 'SKU-1', 'quantity' => 2, 'value' => 12.5, 'barcode' => '123', 'weight' => 0.5]);
});

it('paginates fulfillment order line items and excludes non-shipping work', function (): void {
    $baseItem = [
        'id' => 'gid://shopify/FulfillmentOrderLineItem/1',
        'sku' => 'SKU-1', 'productTitle' => 'Widget', 'remainingQuantity' => 1,
        'requiresShipping' => true, 'weight' => null, 'variant' => null,
        'lineItem' => ['originalUnitPriceSet' => ['shopMoney' => ['amount' => '5.00']]],
    ];
    Saloon::fake([
        GraphQL::class => answeringShopifyScopes(
            MockResponse::make(['data' => ['fulfillmentOrders' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'nodes' => [[
                    'id' => 'gid://shopify/FulfillmentOrder/8001', 'status' => 'IN_PROGRESS',
                    'order' => ['id' => 'gid://shopify/Order/1001', 'name' => '#1001'],
                    'destination' => ['address1' => '1 Main', 'city' => 'Seattle', 'province' => 'WA', 'countryCode' => 'US'],
                    'assignedLocation' => ['location' => ['id' => 'gid://shopify/Location/1', 'name' => 'Main', 'isActive' => true]],
                    'lineItems' => [
                        'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'items-1'],
                        'nodes' => [$baseItem],
                    ],
                ]],
            ]]]),
            MockResponse::make(['data' => ['fulfillmentOrder' => ['lineItems' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'nodes' => [
                    array_merge($baseItem, ['id' => 'gid://shopify/FulfillmentOrderLineItem/2', 'sku' => 'SKU-2']),
                    array_merge($baseItem, ['id' => 'gid://shopify/FulfillmentOrderLineItem/3', 'sku' => 'DIGITAL', 'requiresShipping' => false]),
                    array_merge($baseItem, ['id' => 'gid://shopify/FulfillmentOrderLineItem/4', 'sku' => 'DONE', 'remainingQuantity' => 0]),
                ],
            ]]]]),
        ),
    ]);

    $source = new ShopifySource(shopifyConfig(['fulfillment_order_import_enabled' => true]));
    $source->fetchShipments();

    expect($source->fetchShipmentItems('gid://shopify/FulfillmentOrder/8001')->pluck('sku')->all())
        ->toBe(['SKU-1', 'SKU-2']);
    Saloon::assertSentCount(3);
});

it('handles fulfillment-order cursor pagination', function (): void {
    Saloon::fake([
        GraphQL::class => answeringShopifyScopes(
            mockShopifyFulfillmentOrders([shopifyFulfillmentOrderNode()], hasNextPage: true, endCursor: 'cursor_abc'),
            mockShopifyFulfillmentOrders([shopifyFulfillmentOrderNode([
                'id' => 'gid://shopify/FulfillmentOrder/7002',
                'order' => ['id' => 'gid://shopify/Order/1002', 'name' => '#1002'],
            ])]),
        ),
    ]);

    $source = new ShopifySource(shopifyConfig(['fulfillment_order_import_enabled' => true]));
    $shipments = $source->fetchShipments();

    expect($shipments)->toHaveCount(2);
    expect($shipments[0]['shipment_reference'])->toBe('#1001');
    expect($shipments[1]['shipment_reference'])->toBe('#1002');

    Saloon::assertSentCount(3);
});

it('maps line items using remaining quantity', function (): void {
    $fulfillmentOrder = shopifyFulfillmentOrderNode([
        'lineItems' => [
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            'nodes' => [
                [
                    'id' => 'gid://shopify/FulfillmentOrderLineItem/1',
                    'sku' => 'WIDGET-001',
                    'productTitle' => 'Blue Widget',
                    'remainingQuantity' => 3,
                    'requiresShipping' => true,
                    'weight' => ['unit' => 'POUNDS', 'value' => 1.5],
                    'variant' => ['id' => 'gid://shopify/ProductVariant/1', 'barcode' => '111111111111'],
                    'lineItem' => ['originalUnitPriceSet' => ['shopMoney' => ['amount' => '10.00']]],
                ],
                [
                    'id' => 'gid://shopify/FulfillmentOrderLineItem/2',
                    'sku' => 'WIDGET-002',
                    'productTitle' => 'Red Widget',
                    'remainingQuantity' => 0,
                    'requiresShipping' => true,
                    'weight' => ['unit' => 'OUNCES', 'value' => 4.0],
                    'variant' => ['id' => 'gid://shopify/ProductVariant/2', 'barcode' => '222222222222'],
                    'lineItem' => ['originalUnitPriceSet' => ['shopMoney' => ['amount' => '15.00']]],
                ],
            ],
        ],
    ]);

    Saloon::fake([
        GraphQL::class => answeringShopifyScopes(mockShopifyFulfillmentOrders([$fulfillmentOrder])),
    ]);

    $source = new ShopifySource(shopifyConfig(['fulfillment_order_import_enabled' => true]));
    $source->fetchShipments();

    $items = $source->fetchShipmentItems('gid://shopify/FulfillmentOrder/7001');

    expect($items)->toHaveCount(1);
    expect($items[0]['sku'])->toBe('WIDGET-001');
    expect($items[0]['quantity'])->toBe(3);
    expect($items[0]['value'])->toBe(10.0);
    expect($items[0]['barcode'])->toBe('111111111111');
    expect($items[0]['weight'])->toBe(1.5); // 1.5 lbs (stored as pounds)
});

it('returns empty collection for unknown shipment reference', function (): void {
    $source = new ShopifySource(shopifyConfig(['fulfillment_order_import_enabled' => true]));

    $items = $source->fetchShipmentItems('#9999');

    expect($items)->toBeEmpty();
});

it('filters fulfillment orders to OPEN and IN_PROGRESS only', function (): void {
    Saloon::fake([
        GraphQL::class => answeringShopifyScopes(mockShopifyFulfillmentOrders([
            shopifyFulfillmentOrderNode(['id' => 'gid://shopify/FulfillmentOrder/5001']),
            shopifyFulfillmentOrderNode(['id' => 'gid://shopify/FulfillmentOrder/5002', 'status' => 'CLOSED']),
            shopifyFulfillmentOrderNode(['id' => 'gid://shopify/FulfillmentOrder/5003', 'status' => 'IN_PROGRESS']),
        ])),
    ]);

    $source = new ShopifySource(shopifyConfig(['fulfillment_order_import_enabled' => true]));
    $shipments = $source->fetchShipments();

    expect($shipments->pluck('source_record_id')->all())->toBe([
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

    Saloon::assertSent(function (GraphQL $request): bool {
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
})->throws(PermanentExportException::class, 'fulfillment order ID');

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
        GraphQL::class => answeringShopifyScopes(MockResponse::make([
            'errors' => [
                ['message' => 'Throttled'],
            ],
        ])),
    ]);

    $source = new ShopifySource(shopifyConfig(['fulfillment_order_import_enabled' => true]));
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

    Saloon::assertSent(function (GraphQL $request): bool {
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

    Saloon::assertSent(function (GraphQL $request): bool {
        $body = $request->body()->all();

        return $body['variables']['fulfillment']['notifyCustomer'] === true;
    });
});

it('prefers the order shipping address province code over the destination province name', function (): void {
    // FulfillmentOrderDestination exposes only `province`, the full name. USPS
    // rejects a label whose state is not a two-letter code, so the code is taken
    // from the order's shipping address when it describes the same destination.
    Saloon::fake([
        GraphQL::class => answeringShopifyScopes(mockShopifyFulfillmentOrders([shopifyFulfillmentOrderNode([
            'order' => [
                'id' => 'gid://shopify/Order/1001',
                'name' => '#1001',
                'email' => 'customer@example.com',
                'shippingAddress' => ['provinceCode' => 'AE', 'zip' => '09532'],
            ],
            'destination' => [
                'firstName' => 'John',
                'lastName' => 'Doe',
                'address1' => 'PSC 402 BOX 301',
                'city' => 'FPO',
                'province' => 'Armed Forces Europe',
                'zip' => '09532',
                'countryCode' => 'US',
            ],
        ])])),
    ]);

    $source = new ShopifySource(shopifyConfig(['fulfillment_order_import_enabled' => true]));

    expect($source->fetchShipments()->first()['state_or_province'])->toBe('AE');
});

it('keeps the destination province when the fulfillment order ships somewhere else', function (): void {
    // A fulfillment order can be routed to a different address than the order's,
    // so the order-level province code must not be applied blindly.
    Saloon::fake([
        GraphQL::class => answeringShopifyScopes(mockShopifyFulfillmentOrders([shopifyFulfillmentOrderNode([
            'order' => [
                'id' => 'gid://shopify/Order/1001',
                'name' => '#1001',
                'email' => 'customer@example.com',
                'shippingAddress' => ['provinceCode' => 'WA', 'zip' => '98101'],
            ],
            'destination' => [
                'firstName' => 'John',
                'lastName' => 'Doe',
                'address1' => '123 Main St',
                'city' => 'Portland',
                'province' => 'OR',
                'zip' => '97201',
                'countryCode' => 'US',
            ],
        ])])),
    ]);

    $source = new ShopifySource(shopifyConfig(['fulfillment_order_import_enabled' => true]));

    expect($source->fetchShipments()->first()['state_or_province'])->toBe('OR');
});
