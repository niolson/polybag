<?php

use App\Enums\PackageExportStatus;
use App\Enums\ShipmentStatus;
use App\Http\Integrations\Shopify\Requests\GraphQL;
use App\Models\Carrier;
use App\Models\CarrierAlias;
use App\Models\Channel;
use App\Models\ChannelAlias;
use App\Models\DataSource;
use App\Models\DataSourceLocation;
use App\Models\Location;
use App\Models\Package;
use App\Models\PackageExport;
use App\Models\Shipment;
use App\Services\ShipmentImport\ImportResult;
use App\Services\ShipmentImport\PackageExportService;
use App\Services\ShipmentImport\ShipmentImportService;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Illuminate\Support\Facades\Cache;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

function shopifyFulfillmentOrdersResponse(array $nodes = [], bool $hasNextPage = false, ?string $endCursor = null): MockResponse
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

function fulfillmentUserErrorResponse(string $message): MockResponse
{
    return MockResponse::make([
        'data' => [
            'fulfillmentCreate' => [
                'fulfillment' => null,
                'userErrors' => [[
                    'field' => ['fulfillment'],
                    'message' => $message,
                ]],
            ],
        ],
    ]);
}

function fulfillmentGraphQlErrorResponse(array $error): MockResponse
{
    return MockResponse::make([
        'errors' => [$error],
    ]);
}

function sampleFulfillmentOrder(string $id, string $locationId, int $quantity = 1): array
{
    return [
        'id' => "gid://shopify/FulfillmentOrder/{$id}",
        'status' => 'OPEN',
        'order' => ['id' => "gid://shopify/Order/{$id}", 'name' => "#{$id}", 'email' => 'test@example.com'],
        'destination' => [
            'firstName' => 'Jane', 'lastName' => 'Smith', 'address1' => '456 Oak Ave',
            'city' => 'Seattle', 'province' => 'WA', 'zip' => '98101', 'countryCode' => 'US',
        ],
        'assignedLocation' => [
            'name' => "Warehouse {$locationId}",
            'location' => [
                'id' => "gid://shopify/Location/{$locationId}",
                'name' => "Warehouse {$locationId}",
                'isActive' => true,
                'address' => ['city' => 'Seattle', 'countryCode' => 'US'],
            ],
        ],
        'lineItems' => [
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            'nodes' => [[
                'id' => "gid://shopify/FulfillmentOrderLineItem/{$id}",
                'sku' => "SKU-{$id}", 'productTitle' => 'Widget', 'remainingQuantity' => $quantity,
                'requiresShipping' => true, 'weight' => null,
                'variant' => ['id' => "gid://shopify/ProductVariant/{$id}", 'barcode' => null],
                'lineItem' => ['originalUnitPriceSet' => ['shopMoney' => ['amount' => '10.00']]],
            ]],
        ],
    ];
}

beforeEach(function (): void {
    Cache::put('shopify_access_token_'.md5('test-shop.myshopify.com'), 'shpat_test_token', 3600);

    $this->dataSource = DataSource::factory()->create([
        'source_type' => ShopifySource::class,
        'name' => 'Shopify',
        'settings' => [
            'channel_name' => 'Shopify',
            'notify_customer' => false,
            'shop_domain' => 'test-shop.myshopify.com',
        ],
        'secret_settings' => [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
        ],
    ]);
});

it('reports an actionable error without contacting Shopify before fulfillment-order activation', function (): void {
    $source = new ShopifySource([
        'source_type' => ShopifySource::class,
        'enabled' => true,
        'channel_name' => 'Shopify',
        'shop_domain' => 'test-shop.myshopify.com',
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'shipping_method' => null,
        'notify_customer' => false,
        'export' => ['enabled' => false, 'field_mapping' => []],
    ]);

    $result = ShipmentImportService::forSource($source, $this->dataSource)->import();

    expect($result->shipmentsCreated)->toBe(0)
        ->and($result->errors)->toHaveCount(1)
        ->and($result->errors[0])->toContain('activate fulfillment-order imports')
        ->and(Shipment::count())->toBe(0);
    Saloon::assertNothingSent();
});

it('imports mapped fulfillment orders and skips only unmapped locations', function (): void {
    $channel = tap(Channel::factory()->create(['name' => 'Shopify']), fn ($channel) => ChannelAlias::create([
        'reference' => 'Shopify',
        'channel_id' => $channel->id,
    ]));
    $location = Location::factory()->create();
    DataSourceLocation::factory()->create([
        'data_source_id' => $this->dataSource,
        'external_id' => 'gid://shopify/Location/1',
        'location_id' => $location,
    ]);

    $splitFulfillmentOrder = sampleFulfillmentOrder('1003', '1');
    $splitFulfillmentOrder['order'] = [
        'id' => 'gid://shopify/Order/1001',
        'name' => '#1001',
        'email' => 'test@example.com',
    ];

    Saloon::fake([
        GraphQL::class => MockResponse::make([
            'data' => ['fulfillmentOrders' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'nodes' => [sampleFulfillmentOrder('1001', '1'), $splitFulfillmentOrder, sampleFulfillmentOrder('1002', '2')],
            ]],
        ]),
    ]);

    $source = new ShopifySource([
        'channel_name' => 'Shopify',
        'shop_domain' => 'test-shop.myshopify.com',
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'fulfillment_order_import_enabled' => true,
        'shipping_method' => null,
    ]);

    $result = ShipmentImportService::forSource($source, $this->dataSource)->import();

    expect($result->shipmentsCreated)->toBe(2)
        ->and($result->shipmentsSkipped)->toBe(1)
        ->and($result->errors)->toHaveCount(1);
    $shipment = Shipment::where('source_record_id', 'gid://shopify/FulfillmentOrder/1001')->firstOrFail();
    expect($shipment->location_id)->toBe($location->id)
        ->and($shipment->data_source_location_id)->not->toBeNull()
        ->and($shipment->channel_id)->toBe($channel->id)
        ->and($shipment->metadata['shopify_fulfillment_order_id'])->toBe('gid://shopify/FulfillmentOrder/1001');
    expect(Shipment::where('shipment_reference', '#1001')->count())->toBe(2)
        ->and(Shipment::where('source_record_id', 'gid://shopify/FulfillmentOrder/1003')->value('location_id'))->toBe($location->id);
    expect(Shipment::where('source_record_id', 'gid://shopify/FulfillmentOrder/1002')->exists())->toBeFalse();
});

it('updates a fulfillment order reassignment before packing and reports a conflict after packing begins', function (): void {
    tap(Channel::factory()->create(['name' => 'Shopify']), fn ($channel) => ChannelAlias::create([
        'reference' => 'Shopify',
        'channel_id' => $channel->id,
    ]));
    $firstLocation = Location::factory()->create();
    $secondLocation = Location::factory()->create();
    DataSourceLocation::factory()->create([
        'data_source_id' => $this->dataSource,
        'external_id' => 'gid://shopify/Location/1',
        'location_id' => $firstLocation,
    ]);
    DataSourceLocation::factory()->create([
        'data_source_id' => $this->dataSource,
        'external_id' => 'gid://shopify/Location/2',
        'location_id' => $secondLocation,
    ]);
    $settings = $this->dataSource->settings;
    $settings['authoritative_shipment_items'] = true;
    $this->dataSource->update(['settings' => $settings]);
    $sourceConfig = [
        'channel_name' => 'Shopify',
        'shop_domain' => 'test-shop.myshopify.com',
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'fulfillment_order_import_enabled' => true,
    ];

    Saloon::fake([GraphQL::class => MockResponse::make(['data' => ['fulfillmentOrders' => [
        'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
        'nodes' => [sampleFulfillmentOrder('2001', '1', 2)],
    ]]])]);
    ShipmentImportService::forSource(new ShopifySource($sourceConfig), $this->dataSource)->import();

    Saloon::fake([GraphQL::class => MockResponse::make(['data' => ['fulfillmentOrders' => [
        'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
        'nodes' => [sampleFulfillmentOrder('2001', '2', 1)],
    ]]])]);
    ShipmentImportService::forSource(new ShopifySource($sourceConfig), $this->dataSource)->import();

    $shipment = Shipment::where('source_record_id', 'gid://shopify/FulfillmentOrder/2001')->firstOrFail();
    expect($shipment->location_id)->toBe($secondLocation->id)
        ->and($shipment->shipmentItems->first()->quantity)->toBe(1);

    Package::factory()->create(['shipment_id' => $shipment, 'location_id' => $secondLocation]);
    Saloon::fake([GraphQL::class => MockResponse::make(['data' => ['fulfillmentOrders' => [
        'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
        'nodes' => [sampleFulfillmentOrder('2001', '1', 3)],
    ]]])]);
    $result = ShipmentImportService::forSource(new ShopifySource($sourceConfig), $this->dataSource)->import();

    expect($result->errors)->toHaveCount(1)
        ->and($shipment->refresh()->location_id)->toBe($secondLocation->id)
        ->and($shipment->shipmentItems->first()->quantity)->toBe(1);
});

it('exports package to the singular fulfillment order', function (): void {
    $channel = Channel::factory()->create(['name' => 'Shopify']);

    $exportSource = DataSource::factory()->create([
        'source_type' => ShopifySource::class,
        'name' => 'Shopify Export',
        'settings' => [
            'channel_name' => 'Shopify',
            'notify_customer' => false,
            'shop_domain' => 'test-shop.myshopify.com',
            'export_enabled' => true,
            'export_field_mapping' => [
                'tracking_number' => 'tracking_number',
                'carrier' => 'carrier',
                'shipment_reference' => 'shipment_reference',
                'fulfillment_order_id' => 'fulfillment_order_id',
            ],
        ],
        'secret_settings' => [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
        ],
    ]);

    $shipment = Shipment::factory()->create([
        'channel_id' => $channel->id,
        'data_source_id' => $exportSource->id,
        'shipment_reference' => '#1001',
        'metadata' => [
            'shopify_order_id' => 'gid://shopify/Order/1001',
            'shopify_fulfillment_order_id' => 'gid://shopify/FulfillmentOrder/9002',
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

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    expect($result->success)->toBeTrue();
    expect($result->destinationsAttempted)->toBe(1);
    expect($result->destinationsSucceeded)->toBe(1);
    expect($package->fresh()->exported)->toBeTrue();

    Saloon::assertSent(function (GraphQL $request): bool {
        $body = $request->body()->all();
        $fulfillment = $body['variables']['fulfillment'] ?? [];

        return ($fulfillment['trackingInfo']['number'] ?? '') === 'TRACK123'
            && ($fulfillment['trackingInfo']['company'] ?? '') === 'USPS'
            && ($fulfillment['lineItemsByFulfillmentOrder'][0]['fulfillmentOrderId'] ?? '') === 'gid://shopify/FulfillmentOrder/9002';
    });
});

it('fulfills with the normalized carrier of record rather than the source spelling', function (): void {
    $usps = Carrier::factory()->usps()->create();
    CarrierAlias::factory()->for($usps)->create(['alias' => 'US Postal Service']);

    $exportSource = DataSource::factory()->create([
        'source_type' => ShopifySource::class,
        'name' => 'Shopify Export',
        'settings' => [
            'channel_name' => 'Shopify',
            'shop_domain' => 'test-shop.myshopify.com',
            'export_enabled' => true,
            'export_field_mapping' => [
                'tracking_number' => 'tracking_number',
                'carrier' => 'carrier',
                'fulfillment_order_id' => 'fulfillment_order_id',
            ],
        ],
        'secret_settings' => [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
        ],
    ]);

    $shipment = Shipment::factory()->create([
        'data_source_id' => $exportSource->id,
        'metadata' => ['shopify_fulfillment_order_id' => 'gid://shopify/FulfillmentOrder/9002'],
    ]);

    $package = Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'tracking_number' => 'TRACK123',
        'carrier' => 'US Postal Service',
        'normalized_carrier_id' => $usps->id,
        'exported' => false,
    ]);

    Saloon::fake([GraphQL::class => fulfillmentSuccessResponse()]);

    expect((new PackageExportService)->exportPackage($package)->success)->toBeTrue();

    Saloon::assertSent(function (GraphQL $request): bool {
        $fulfillment = $request->body()->all()['variables']['fulfillment'] ?? [];

        return ($fulfillment['trackingInfo']['company'] ?? '') === 'USPS';
    });
});

it('handles package without metadata gracefully in export', function (): void {
    $channel = Channel::factory()->create(['name' => 'Shopify']);

    $exportSource = DataSource::factory()->create([
        'source_type' => ShopifySource::class,
        'name' => 'Shopify Export',
        'settings' => [
            'channel_name' => 'Shopify',
            'notify_customer' => false,
            'shop_domain' => 'test-shop.myshopify.com',
            'export_enabled' => true,
            'export_field_mapping' => [
                'tracking_number' => 'tracking_number',
                'carrier' => 'carrier',
                'shipment_reference' => 'shipment_reference',
                'fulfillment_order_id' => 'fulfillment_order_id',
            ],
        ],
        'secret_settings' => [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
        ],
    ]);

    $shipment = Shipment::factory()->create([
        'channel_id' => $channel->id,
        'data_source_id' => $exportSource->id,
        'shipment_reference' => '#2001',
        'metadata' => null,
    ]);

    $package = Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'tracking_number' => 'TRACK456',
        'carrier' => 'USPS',
        'exported' => false,
    ]);

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    $export = PackageExport::query()->where('package_id', $package->id)->firstOrFail();

    expect($result->success)->toBeFalse();
    expect($result->errors)->not->toBeEmpty();
    expect($result->errors[0])->toContain('fulfillment order ID')
        ->and($export->status)->toBe(PackageExportStatus::PermanentlyFailed);
});

it('reports a safe shopify package reference when shipment reference is not mapped', function (): void {
    $exportSource = DataSource::factory()->create([
        'source_type' => ShopifySource::class,
        'name' => 'Shopify Export',
        'settings' => [
            'shop_domain' => 'test-shop.myshopify.com',
            'export_enabled' => true,
            'export_field_mapping' => ['carrier' => 'carrier'],
        ],
        'secret_settings' => ['client_id' => 'test-client-id', 'client_secret' => 'test-client-secret'],
    ]);
    $shipment = Shipment::factory()->create([
        'data_source_id' => $exportSource,
        'shipment_reference' => '#2001',
        'metadata' => null,
    ]);
    $package = Package::factory()->shipped()->create([
        'shipment_id' => $shipment,
        'exported' => false,
    ]);

    $result = (new PackageExportService)->exportPackage($package);
    $export = PackageExport::query()->where('package_id', $package->id)->firstOrFail();

    expect($result->errors[0])->toContain("package {$package->id}")
        ->and($result->errors[0])->toContain('fulfillment order ID')
        ->and($result->errors[0])->not->toContain('Undefined array key')
        ->and($export->status)->toBe(PackageExportStatus::PermanentlyFailed);
});

it('treats an already fulfilled shopify response as idempotent success', function (): void {
    $exportSource = DataSource::factory()->create([
        'source_type' => ShopifySource::class,
        'name' => 'Shopify Export',
        'settings' => [
            'shop_domain' => 'test-shop.myshopify.com',
            'export_enabled' => true,
        ],
        'secret_settings' => ['client_id' => 'test-client-id', 'client_secret' => 'test-client-secret'],
    ]);
    $shipment = Shipment::factory()->create([
        'data_source_id' => $exportSource->id,
        'shipment_reference' => '#2002',
        'metadata' => ['shopify_fulfillment_order_id' => 'gid://shopify/FulfillmentOrder/2002'],
    ]);
    $package = Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'tracking_number' => 'TRACK-2002',
        'carrier' => 'USPS',
    ]);
    Saloon::fake([GraphQL::class => fulfillmentUserErrorResponse('Fulfillment order is already fulfilled.')]);

    $result = (new PackageExportService)->exportPackage($package);
    $export = PackageExport::query()->where('package_id', $package->id)->firstOrFail();

    expect($result->success)->toBeTrue()
        ->and($export->status)->toBe(PackageExportStatus::Succeeded)
        ->and($package->fresh()->exported)->toBeTrue();
});

it('skips the shopify fulfillment for a package shopify sold the label for', function (): void {
    $exportSource = DataSource::factory()->create([
        'source_type' => ShopifySource::class,
        'name' => 'Shopify Export',
        'settings' => [
            'shop_domain' => 'test-shop.myshopify.com',
            'export_enabled' => true,
        ],
        'secret_settings' => ['client_id' => 'test-client-id', 'client_secret' => 'test-client-secret'],
    ]);
    $shipment = Shipment::factory()->create([
        'data_source_id' => $exportSource->id,
        'shipment_reference' => '#2010',
        'metadata' => ['shopify_fulfillment_order_id' => 'gid://shopify/FulfillmentOrder/2010'],
    ]);
    $package = Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'tracking_number' => 'TRACK-2010',
        'carrier' => 'USPS',
        'exported' => false,
        'metadata' => ['shopify_shipping_label_id' => 'gid://shopify/ShippingLabel/2010'],
    ]);

    // Shopify closed the fulfillment order when it sold the label, so this is
    // what the mutation would answer if it were sent at all.
    Saloon::fake([GraphQL::class => fulfillmentUserErrorResponse(
        'Fulfillment order 2010 has an unfulfillable status= closed.'
    )]);

    $result = (new PackageExportService)->exportPackage($package);
    $export = PackageExport::query()->where('package_id', $package->id)->firstOrFail();

    Saloon::assertNothingSent();

    expect($result->success)->toBeTrue()
        ->and($result->errors)->toBeEmpty()
        ->and($export->status)->toBe(PackageExportStatus::Succeeded)
        ->and($package->fresh()->exported)->toBeTrue();
});

it('permanently fails a closed fulfillment order for a package shopify did not sell', function (): void {
    $exportSource = DataSource::factory()->create([
        'source_type' => ShopifySource::class,
        'name' => 'Shopify Export',
        'settings' => [
            'shop_domain' => 'test-shop.myshopify.com',
            'export_enabled' => true,
        ],
        'secret_settings' => ['client_id' => 'test-client-id', 'client_secret' => 'test-client-secret'],
    ]);
    $shipment = Shipment::factory()->create([
        'data_source_id' => $exportSource->id,
        'shipment_reference' => '#2011',
        'metadata' => ['shopify_fulfillment_order_id' => 'gid://shopify/FulfillmentOrder/2011'],
    ]);
    // No `shopify_shipping_label_id`: this label was bought on one of our own
    // carrier accounts, and the stored fulfillment order has since been closed
    // and replaced (issue `18`). Shopify has genuinely not been told what
    // shipped, so the identical message must still fail.
    $package = Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'tracking_number' => 'TRACK-2011',
        'carrier' => 'USPS',
        'exported' => false,
    ]);
    Saloon::fake([GraphQL::class => fulfillmentUserErrorResponse(
        'Fulfillment order 2011 has an unfulfillable status= closed.'
    )]);

    $result = (new PackageExportService)->exportPackage($package);
    $export = PackageExport::query()->where('package_id', $package->id)->firstOrFail();

    expect($result->success)->toBeFalse()
        ->and($result->shouldRetry())->toBeFalse()
        ->and($export->status)->toBe(PackageExportStatus::PermanentlyFailed)
        ->and($package->fresh()->exported)->toBeFalse();
});

it('permanently fails shopify fulfillment user errors', function (): void {
    $exportSource = DataSource::factory()->create([
        'source_type' => ShopifySource::class,
        'name' => 'Shopify Export',
        'settings' => [
            'shop_domain' => 'test-shop.myshopify.com',
            'export_enabled' => true,
        ],
        'secret_settings' => ['client_id' => 'test-client-id', 'client_secret' => 'test-client-secret'],
    ]);
    $shipment = Shipment::factory()->create([
        'data_source_id' => $exportSource->id,
        'shipment_reference' => '#2003',
        'metadata' => ['shopify_fulfillment_order_id' => 'gid://shopify/FulfillmentOrder/2003'],
    ]);
    $package = Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'tracking_number' => 'TRACK-2003',
        'carrier' => 'USPS',
    ]);
    Saloon::fake([GraphQL::class => fulfillmentUserErrorResponse('Fulfillment order is on hold and is not fulfillable.')]);

    $result = (new PackageExportService)->exportPackage($package);
    $export = PackageExport::query()->where('package_id', $package->id)->firstOrFail();

    expect($result->shouldRetry())->toBeFalse()
        ->and($export->status)->toBe(PackageExportStatus::PermanentlyFailed);
});

it('permanently fails shopify top-level graphql errors without assuming a message', function (): void {
    $exportSource = DataSource::factory()->create([
        'source_type' => ShopifySource::class,
        'name' => 'Shopify Export',
        'settings' => [
            'shop_domain' => 'test-shop.myshopify.com',
            'export_enabled' => true,
        ],
        'secret_settings' => ['client_id' => 'test-client-id', 'client_secret' => 'test-client-secret'],
    ]);
    $shipment = Shipment::factory()->create([
        'data_source_id' => $exportSource,
        'shipment_reference' => '#2004',
        'metadata' => ['shopify_fulfillment_order_id' => 'gid://shopify/FulfillmentOrder/2004'],
    ]);
    $package = Package::factory()->shipped()->create([
        'shipment_id' => $shipment,
        'tracking_number' => 'TRACK-2004',
        'carrier' => 'USPS',
    ]);
    Saloon::fake([GraphQL::class => fulfillmentGraphQlErrorResponse(['extensions' => ['code' => 'INVALID']])]);

    $result = (new PackageExportService)->exportPackage($package);
    $export = PackageExport::query()->where('package_id', $package->id)->firstOrFail();

    expect($result->shouldRetry())->toBeFalse()
        ->and($result->errors[0])->not->toContain('Undefined array key')
        ->and($export->status)->toBe(PackageExportStatus::PermanentlyFailed);
});

it('retries transient shopify top-level graphql errors', function (string $code, string $message): void {
    $exportSource = DataSource::factory()->create([
        'source_type' => ShopifySource::class,
        'name' => 'Shopify Export',
        'settings' => [
            'shop_domain' => 'test-shop.myshopify.com',
            'export_enabled' => true,
        ],
        'secret_settings' => ['client_id' => 'test-client-id', 'client_secret' => 'test-client-secret'],
    ]);
    $shipment = Shipment::factory()->create([
        'data_source_id' => $exportSource,
        'shipment_reference' => '#2006',
        'metadata' => ['shopify_fulfillment_order_id' => 'gid://shopify/FulfillmentOrder/2006'],
    ]);
    $package = Package::factory()->shipped()->create([
        'shipment_id' => $shipment,
        'tracking_number' => 'TRACK-2006',
        'carrier' => 'USPS',
    ]);
    Saloon::fake([GraphQL::class => fulfillmentGraphQlErrorResponse([
        'message' => $message,
        'extensions' => ['code' => $code],
    ])]);

    $result = (new PackageExportService)->exportPackage($package);
    $export = PackageExport::query()->where('package_id', $package->id)->firstOrFail();

    expect($result->shouldRetry())->toBeTrue()
        ->and($result->errors[0])->toContain($message)
        ->and($export->status)->toBe(PackageExportStatus::RetryableFailed);
})->with([
    'throttled' => ['THROTTLED', 'Throttled'],
    'internal server error' => ['INTERNAL_SERVER_ERROR', 'Internal error'],
    'service unavailable' => ['SERVICE_UNAVAILABLE', 'Service unavailable'],
]);

it('permanently fails shopify user errors without assuming a message', function (): void {
    $exportSource = DataSource::factory()->create([
        'source_type' => ShopifySource::class,
        'name' => 'Shopify Export',
        'settings' => [
            'shop_domain' => 'test-shop.myshopify.com',
            'export_enabled' => true,
        ],
        'secret_settings' => ['client_id' => 'test-client-id', 'client_secret' => 'test-client-secret'],
    ]);
    $shipment = Shipment::factory()->create([
        'data_source_id' => $exportSource,
        'shipment_reference' => '#2005',
        'metadata' => ['shopify_fulfillment_order_id' => 'gid://shopify/FulfillmentOrder/2005'],
    ]);
    $package = Package::factory()->shipped()->create([
        'shipment_id' => $shipment,
        'tracking_number' => 'TRACK-2005',
        'carrier' => 'USPS',
    ]);
    Saloon::fake([GraphQL::class => MockResponse::make([
        'data' => [
            'fulfillmentCreate' => [
                'fulfillment' => null,
                'userErrors' => [['field' => ['fulfillment']]],
            ],
        ],
    ])]);

    $result = (new PackageExportService)->exportPackage($package);
    $export = PackageExport::query()->where('package_id', $package->id)->firstOrFail();

    expect($result->shouldRetry())->toBeFalse()
        ->and($result->errors[0])->not->toContain('Undefined array key')
        ->and($export->status)->toBe(PackageExportStatus::PermanentlyFailed);
});

it('imports multiple pages of fulfillment orders', function (): void {
    $channel = tap(Channel::factory()->create(['name' => 'Shopify']), fn ($c) => ChannelAlias::create(['reference' => 'Shopify', 'channel_id' => $c->id]));
    foreach (['1', '2'] as $locationId) {
        DataSourceLocation::factory()->create([
            'data_source_id' => $this->dataSource,
            'external_id' => "gid://shopify/Location/{$locationId}",
            'location_id' => Location::factory()->create(),
        ]);
    }

    Saloon::fake([
        shopifyFulfillmentOrdersResponse(
            [sampleFulfillmentOrder('1001', '1')],
            hasNextPage: true,
            endCursor: 'cursor_page1'
        ),
        shopifyFulfillmentOrdersResponse(
            [sampleFulfillmentOrder('1002', '2')],
        ),
    ]);

    $source = new ShopifySource([
        'source_type' => ShopifySource::class,
        'enabled' => true,
        'channel_name' => 'Shopify',
        'shop_domain' => 'test-shop.myshopify.com',
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'shipping_method' => null,
        'notify_customer' => false,
        'fulfillment_order_import_enabled' => true,
        'export' => ['enabled' => false, 'field_mapping' => []],
    ]);

    $result = ShipmentImportService::forSource($source, $this->dataSource)->import();

    expect($result->shipmentsCreated)->toBe(2);
    expect(Shipment::where('shipment_reference', '#1001')->exists())->toBeTrue();
    expect(Shipment::where('shipment_reference', '#1002')->exists())->toBeTrue();
});

it('deduplicates shopify imports by fulfillment-order id instead of displayed order name', function (): void {
    $channel = tap(Channel::factory()->create(['name' => 'Shopify']), fn ($c) => ChannelAlias::create(['reference' => 'Shopify', 'channel_id' => $c->id]));
    DataSourceLocation::factory()->create([
        'data_source_id' => $this->dataSource,
        'external_id' => 'gid://shopify/Location/1',
        'location_id' => Location::factory()->create(),
    ]);

    $firstFulfillmentOrder = sampleFulfillmentOrder('1001', '1');
    $secondFulfillmentOrder = sampleFulfillmentOrder('1001', '1');
    $secondFulfillmentOrder['order']['name'] = '#1001-RENAMED';

    Saloon::fake([
        GraphQL::class => shopifyFulfillmentOrdersResponse([$firstFulfillmentOrder]),
    ]);

    $source = new ShopifySource([
        'source_type' => ShopifySource::class,
        'enabled' => true,
        'channel_name' => 'Shopify',
        'shop_domain' => 'test-shop.myshopify.com',
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'shipping_method' => null,
        'notify_customer' => false,
        'fulfillment_order_import_enabled' => true,
        'export' => ['enabled' => false, 'field_mapping' => []],
    ]);

    $result1 = ShipmentImportService::forSource($source, $this->dataSource)->import();

    Saloon::fake([
        GraphQL::class => shopifyFulfillmentOrdersResponse([$secondFulfillmentOrder]),
    ]);

    $result2 = ShipmentImportService::forSource($source, $this->dataSource)->import();

    expect($result1->shipmentsCreated)->toBe(1)
        ->and($result2->shipmentsUpdated)->toBe(1)
        ->and($result2->shipmentsCreated)->toBe(0)
        ->and(Shipment::count())->toBe(1);

    $shipment = Shipment::first();
    expect($shipment->source_record_id)->toBe('gid://shopify/FulfillmentOrder/1001')
        ->and($shipment->shipment_reference)->toBe('#1001-RENAMED')
        ->and($shipment->channel_id)->toBe($channel->id)
        ->and($shipment->data_source_id)->toBe($this->dataSource->id);
});

/**
 * The fulfillment order Shopify creates when a label bought against another
 * one is voided: a GID nothing has seen, describing the same order, the same
 * location and the same goods as the one it replaced.
 */
function replacementFulfillmentOrder(string $id, string $replacing, string $locationId, int $quantity = 1): array
{
    $fulfillmentOrder = sampleFulfillmentOrder($replacing, $locationId, $quantity);
    $fulfillmentOrder['id'] = "gid://shopify/FulfillmentOrder/{$id}";
    $fulfillmentOrder['lineItems']['nodes'][0]['id'] = "gid://shopify/FulfillmentOrderLineItem/{$id}";

    return $fulfillmentOrder;
}

/**
 * @param  array<int, array<string, mixed>>  $nodes
 */
function importFulfillmentOrders(DataSource $dataSource, array $nodes): ImportResult
{
    Saloon::fake([GraphQL::class => MockResponse::make(['data' => ['fulfillmentOrders' => [
        'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
        'nodes' => $nodes,
    ]]])]);

    return ShipmentImportService::forSource(new ShopifySource([
        'channel_name' => 'Shopify',
        'shop_domain' => 'test-shop.myshopify.com',
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'fulfillment_order_import_enabled' => true,
    ]), $dataSource)->import();
}

function mapShopifyLocation(DataSource $dataSource, string $externalId = 'gid://shopify/Location/1'): Location
{
    tap(Channel::factory()->create(['name' => 'Shopify']), fn ($channel) => ChannelAlias::firstOrCreate([
        'reference' => 'Shopify',
    ], ['channel_id' => $channel->id]));

    $location = Location::factory()->create();

    DataSourceLocation::factory()->create([
        'data_source_id' => $dataSource,
        'external_id' => $externalId,
        'location_id' => $location,
    ]);

    return $location;
}

it('re-points a shipment at the fulfillment order that replaced it rather than importing the order twice', function (): void {
    mapShopifyLocation($this->dataSource);

    importFulfillmentOrders($this->dataSource, [sampleFulfillmentOrder('4001', '1')]);

    // The void closed 4001 for good; 4001 is gone from the import query and
    // 4002 stands in its place, for the same order, location and goods.
    $result = importFulfillmentOrders($this->dataSource, [
        replacementFulfillmentOrder('4002', '4001', '1'),
    ]);

    $shipment = Shipment::sole();

    expect($result->shipmentsCreated)->toBe(0)
        ->and($shipment->source_record_id)->toBe('gid://shopify/FulfillmentOrder/4002')
        ->and($shipment->metadata['shopify_fulfillment_order_id'])->toBe('gid://shopify/FulfillmentOrder/4002')
        ->and($shipment->shipment_reference)->toBe('#4001');
});

it('leaves a sibling fulfillment order to import as its own shipment while the first is still on offer', function (): void {
    mapShopifyLocation($this->dataSource);

    importFulfillmentOrders($this->dataSource, [sampleFulfillmentOrder('4101', '1')]);

    // Same order, same location, same goods — but 4101 is still offered, so
    // 4102 is a split, not a replacement. Merging them would lose a parcel.
    importFulfillmentOrders($this->dataSource, [
        sampleFulfillmentOrder('4101', '1'),
        replacementFulfillmentOrder('4102', '4101', '1'),
    ]);

    expect(Shipment::count())->toBe(2)
        ->and(Shipment::where('source_record_id', 'gid://shopify/FulfillmentOrder/4101')->exists())->toBeTrue()
        ->and(Shipment::where('source_record_id', 'gid://shopify/FulfillmentOrder/4102')->exists())->toBeTrue();
});

it('imports a fulfillment order for different goods as its own shipment', function (): void {
    mapShopifyLocation($this->dataSource);

    importFulfillmentOrders($this->dataSource, [sampleFulfillmentOrder('4201', '1')]);

    // Everything a replacement has except the line items, which is what a
    // split sibling that closed for its own reasons looks like.
    $differentGoods = sampleFulfillmentOrder('4202', '1');
    $differentGoods['order'] = ['id' => 'gid://shopify/Order/4201', 'name' => '#4201', 'email' => 'test@example.com'];

    importFulfillmentOrders($this->dataSource, [$differentGoods]);

    expect(Shipment::count())->toBe(2)
        ->and(Shipment::where('source_record_id', 'gid://shopify/FulfillmentOrder/4201')->value('metadata'))
        ->toMatchArray(['shopify_fulfillment_order_id' => 'gid://shopify/FulfillmentOrder/4201']);
});

it('never re-points a shipped shipment out from under its package', function (): void {
    $location = mapShopifyLocation($this->dataSource);

    importFulfillmentOrders($this->dataSource, [sampleFulfillmentOrder('4301', '1')]);

    $shipment = Shipment::sole();
    Package::factory()->shipped()->create(['shipment_id' => $shipment, 'location_id' => $location]);
    $shipment->update(['status' => ShipmentStatus::Shipped]);

    importFulfillmentOrders($this->dataSource, [
        replacementFulfillmentOrder('4302', '4301', '1'),
    ]);

    // The duplicate is the lesser harm and it is temporary: the synchronizer
    // un-ships the package when it reads the void, and the run after that finds
    // an open shipment to re-point.
    expect($shipment->refresh()->source_record_id)->toBe('gid://shopify/FulfillmentOrder/4301')
        ->and(Shipment::count())->toBe(2);
});

it('re-points a replacement even when shipment item import is switched off', function (): void {
    mapShopifyLocation($this->dataSource);
    $settings = $this->dataSource->settings;
    $settings['shipment_items_enabled'] = false;
    $this->dataSource->update(['settings' => $settings]);

    importFulfillmentOrders($this->dataSource, [sampleFulfillmentOrder('4401', '1')]);

    expect(Shipment::sole()->shipmentItems)->toBeEmpty();

    // The goods are recorded on the shipment at import time, so recognising a
    // replacement never depends on there being items to compare.
    importFulfillmentOrders($this->dataSource, [
        replacementFulfillmentOrder('4402', '4401', '1'),
    ]);

    expect(Shipment::sole()->source_record_id)->toBe('gid://shopify/FulfillmentOrder/4402');
});

it('imports a fulfillment order for different goods as its own shipment with item import off', function (): void {
    mapShopifyLocation($this->dataSource);
    $settings = $this->dataSource->settings;
    $settings['shipment_items_enabled'] = false;
    $this->dataSource->update(['settings' => $settings]);

    importFulfillmentOrders($this->dataSource, [sampleFulfillmentOrder('4501', '1')]);

    $differentGoods = sampleFulfillmentOrder('4502', '1');
    $differentGoods['order'] = ['id' => 'gid://shopify/Order/4501', 'name' => '#4501', 'email' => 'test@example.com'];

    importFulfillmentOrders($this->dataSource, [$differentGoods]);

    expect(Shipment::count())->toBe(2);
});
