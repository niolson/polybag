<?php

use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\PackageExportStatus;
use App\Enums\PostageSource;
use App\Enums\ServiceEvidence;
use App\Enums\ShipmentStatus;
use App\Events\PackageShipped;
use App\Http\Integrations\Amazon\AmazonSpApiConnector;
use App\Http\Integrations\Amazon\Requests\ConfirmShipment;
use App\Http\Integrations\Amazon\Requests\SearchCatalogItems;
use App\Http\Integrations\Amazon\Requests\SearchOrders;
use App\Models\Carrier;
use App\Models\CarrierAlias;
use App\Models\Channel;
use App\Models\ChannelAlias;
use App\Models\DataSource;
use App\Models\Package;
use App\Models\PackageExport;
use App\Models\PackageItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShippingMethod;
use App\Models\ShippingMethodAlias;
use App\Services\Carriers\AmazonBuyShippingAdapter;
use App\Services\SettingsService;
use App\Services\ShipmentImport\DataSourceFactory;
use App\Services\ShipmentImport\PackageExportService;
use App\Services\ShipmentImport\ShipmentImportService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

function amazonOrdersResponse(array $orders = [], ?string $nextToken = null): MockResponse
{
    $body = [
        'orders' => $orders,
    ];

    if ($nextToken !== null) {
        $body['pagination'] = ['nextToken' => $nextToken];
    }

    return MockResponse::make($body);
}

function amazonSourceForTest(array $overrides = []): AmazonSource
{
    return new AmazonSource(array_merge([
        'source_type' => AmazonSource::class,
        'enabled' => true,
        'channel_name' => 'Amazon',
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'refresh_token' => 'test-refresh-token',
        'marketplace_id' => 'ATVPDKIKX0DER',
        'shipping_method' => null,
        'lookback_days' => 30,
        'export' => ['enabled' => false, 'field_mapping' => []],
    ], $overrides));
}

function amazonConfirmShipmentResponse(): MockResponse
{
    return MockResponse::make([], 204);
}

function amazonExportDestination(): DataSource
{
    return DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'name' => 'Amazon Export',
        'settings' => [
            'channel_name' => 'Amazon',
            'marketplace_id' => 'ATVPDKIKX0DER',
            'export_enabled' => true,
            'export_field_mapping' => [
                'tracking_number' => 'tracking_number',
                'carrier' => 'carrier',
                'shipment_reference' => 'shipment_reference',
                'amazon_order_id' => 'amazon_order_id',
            ],
        ],
        'secret_settings' => [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'refresh_token' => 'test-refresh-token',
        ],
    ]);
}

/**
 * An Amazon-order shipment with one packed, confirmable line, ready to export
 * through $exportSource.
 *
 * @param  array<string, mixed>  $packageAttributes
 */
function amazonExportPackage(DataSource $exportSource, array $packageAttributes = []): Package
{
    $shipment = Shipment::factory()->create([
        'data_source_id' => $exportSource->id,
        'shipment_reference' => '111-2222222-3333333',
        'metadata' => ['amazon_order_id' => '111-2222222-3333333'],
    ]);

    $package = Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'tracking_number' => 'TRACK123',
        'exported' => false,
        'shipped_at' => '2026-08-07 15:30:00',
        ...$packageAttributes,
    ]);

    $product = Product::factory()->create();
    $shipmentItem = $shipment->shipmentItems()->create([
        'product_id' => $product->id,
        'source_item_id' => 'AMAZON-ITEM-123',
        'quantity' => 1,
    ]);
    PackageItem::factory()->create([
        'package_id' => $package->id,
        'shipment_item_id' => $shipmentItem->id,
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    return $package;
}

function amazonCatalogResponse(array $items = [], array $headers = []): MockResponse
{
    return MockResponse::make([
        'numberOfResults' => count($items),
        'items' => $items,
    ], 200, $headers);
}

function sampleAmazonOrder(string $orderId = '111-2222222-3333333'): array
{
    return [
        'orderId' => $orderId,
        'fulfillment' => [
            'fulfillmentStatus' => 'UNSHIPPED',
            'fulfilledBy' => 'MERCHANT',
            'fulfillmentServiceLevel' => 'STANDARD',
            'shipByWindow' => [
                'earliestDateTime' => '2026-08-12T15:00:00Z',
                'latestDateTime' => '2026-08-13T03:00:00Z',
            ],
            'deliverByWindow' => [
                'earliestDateTime' => '2026-08-14T15:00:00Z',
                // 2026-08-15 23:59:59 in America/New_York, the default location timezone.
                'latestDateTime' => '2026-08-16T03:59:59Z',
            ],
        ],
        'recipient' => [
            'deliveryAddress' => [
                'name' => 'Jane Smith',
                'addressLine1' => '456 Oak Ave',
                'addressLine2' => null,
                'city' => 'Seattle',
                'stateOrRegion' => 'WA',
                'postalCode' => '98101',
                'countryCode' => 'US',
                'phone' => '2065551234',
            ],
        ],
        'buyer' => [
            'buyerEmail' => 'test@marketplace.amazon.com',
        ],
        'orderItems' => sampleAmazonOrderItems(),
    ];
}

function sampleAmazonOrderItems(): array
{
    return [
        [
            'orderItemId' => 'AMAZON-ITEM-100',
            'product' => [
                'sellerSku' => 'SKU-100',
                'title' => 'Test Product',
            ],
            'quantityOrdered' => 3,
            'fulfillment' => ['quantityFulfilled' => 0],
            'proceeds' => [
                'breakdowns' => [
                    [
                        'type' => 'ITEM',
                        'subtotal' => ['amount' => '75.00', 'currencyCode' => 'USD'],
                    ],
                ],
            ],
        ],
        [
            'orderItemId' => 'AMAZON-ITEM-200',
            'product' => [
                'sellerSku' => 'SKU-200',
                'title' => 'Another Product',
            ],
            'quantityOrdered' => 1,
            'fulfillment' => ['quantityFulfilled' => 0],
            'proceeds' => [
                'breakdowns' => [
                    [
                        'type' => 'ITEM',
                        'subtotal' => ['amount' => '10.00', 'currencyCode' => 'USD'],
                    ],
                ],
            ],
        ],
    ];
}

beforeEach(function (): void {
    Setting::updateOrCreate(['key' => 'require_mfa'], ['value' => '1', 'type' => 'boolean', 'group' => 'general']);
    app(SettingsService::class)->clearCache();

    // Key format: amazon_sp_api_access_token_ + md5(refresh_token)
    Cache::put('amazon_sp_api_access_token_'.md5('test-refresh-token'), 'test-access-token', 3600);

    $this->dataSource = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'name' => 'Amazon',
        'settings' => ['channel_name' => 'Amazon', 'marketplace_id' => 'ATVPDKIKX0DER'],
        'secret_settings' => [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'refresh_token' => 'test-refresh-token',
        ],
    ]);
});

it('imports amazon orders into shipments table with metadata', function (): void {
    $channel = tap(Channel::factory()->create(['name' => 'Amazon']), fn ($c) => ChannelAlias::create(['reference' => 'Amazon', 'channel_id' => $c->id]));

    Saloon::fake([
        SearchOrders::class => amazonOrdersResponse([sampleAmazonOrder()]),
    ]);

    $source = new AmazonSource([
        'source_type' => AmazonSource::class,
        'enabled' => true,
        'channel_name' => 'Amazon',
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'refresh_token' => 'test-refresh-token',
        'marketplace_id' => 'ATVPDKIKX0DER',
        'shipping_method' => null,
        'lookback_days' => 30,
        'export' => ['enabled' => false, 'field_mapping' => []],
    ]);

    $result = ShipmentImportService::forSource($source, $this->dataSource)->import();

    expect($result->shipmentsCreated)->toBe(1);
    expect($result->itemsCreated)->toBe(2);
    expect($result->errors)->toBeEmpty();

    $shipment = Shipment::where('shipment_reference', '111-2222222-3333333')->first();
    expect($shipment)->not->toBeNull();
    expect($shipment->first_name)->toBe('Jane');
    expect($shipment->last_name)->toBe('Smith');
    expect($shipment->city)->toBe('Seattle');
    expect($shipment->state_or_province)->toBe('WA');
    expect($shipment->postal_code)->toBe('98101');
    expect($shipment->country)->toBe('US');
    expect($shipment->email)->toBeNull();
    expect($shipment->channel_id)->toBe($channel->id);
    expect($shipment->source_record_id)->toBe('111-2222222-3333333');

    // Metadata stored correctly
    expect($shipment->metadata)->toBeArray();
    expect($shipment->metadata['amazon_order_id'])->toBe('111-2222222-3333333');

    // Items created
    expect($shipment->shipmentItems)->toHaveCount(2);
    expect($shipment->shipmentItems->pluck('source_item_id')->all())
        ->toBe(['AMAZON-ITEM-100', 'AMAZON-ITEM-200']);

    Saloon::assertSent(function (SearchOrders $request): bool {
        $includedData = (string) ($request->query()->all()['includedData'] ?? '');

        return str_contains($includedData, 'RECIPIENT')
            && ! str_contains($includedData, 'BUYER');
    });
    Saloon::assertNotSent(SearchCatalogItems::class);
});

it('maps the Amazon fulfillment service level to a shipping method alias', function (): void {
    tap(Channel::factory()->create(['name' => 'Amazon']), fn ($c) => ChannelAlias::create(['reference' => 'Amazon', 'channel_id' => $c->id]));
    $method = ShippingMethod::factory()->create(['name' => 'Ground']);
    ShippingMethodAlias::create(['reference' => 'STANDARD', 'shipping_method_id' => $method->id]);

    Saloon::fake([SearchOrders::class => amazonOrdersResponse([sampleAmazonOrder()])]);

    ShipmentImportService::forSource(amazonSourceForTest(), $this->dataSource)->import();

    $shipment = Shipment::where('shipment_reference', '111-2222222-3333333')->firstOrFail();

    expect($shipment->shipping_method_id)->toBe($method->id)
        ->and($shipment->shipping_method_reference)->toBe('STANDARD')
        ->and($shipment->metadata['amazon_fulfillment_service_level'])->toBe('STANDARD');
});

it('falls back to the source default shipping method when the service level has no alias', function (): void {
    tap(Channel::factory()->create(['name' => 'Amazon']), fn ($c) => ChannelAlias::create(['reference' => 'Amazon', 'channel_id' => $c->id]));
    $default = ShippingMethod::factory()->create(['name' => 'Default Ground']);

    Saloon::fake([SearchOrders::class => amazonOrdersResponse([sampleAmazonOrder()])]);

    ShipmentImportService::forSource(amazonSourceForTest(['shipping_method' => (string) $default->id]), $this->dataSource)->import();

    $shipment = Shipment::where('shipment_reference', '111-2222222-3333333')->firstOrFail();

    // The unmapped service level is still recorded so it surfaces for mapping.
    expect($shipment->shipping_method_id)->toBe($default->id)
        ->and($shipment->shipping_method_reference)->toBe('STANDARD');
});

it('leaves the shipping method unset when Amazon omits the service level and no default is configured', function (): void {
    tap(Channel::factory()->create(['name' => 'Amazon']), fn ($c) => ChannelAlias::create(['reference' => 'Amazon', 'channel_id' => $c->id]));

    $order = sampleAmazonOrder();
    unset($order['fulfillment']['fulfillmentServiceLevel']);

    Saloon::fake([SearchOrders::class => amazonOrdersResponse([$order])]);

    ShipmentImportService::forSource(amazonSourceForTest(), $this->dataSource)->import();

    $shipment = Shipment::where('shipment_reference', '111-2222222-3333333')->firstOrFail();

    expect($shipment->shipping_method_id)->toBeNull()
        ->and($shipment->shipping_method_reference)->toBeNull();
});

it('records the deliver-by date and ship-by window from the Amazon fulfillment block', function (): void {
    tap(Channel::factory()->create(['name' => 'Amazon']), fn ($c) => ChannelAlias::create(['reference' => 'Amazon', 'channel_id' => $c->id]));

    Saloon::fake([SearchOrders::class => amazonOrdersResponse([sampleAmazonOrder()])]);

    ShipmentImportService::forSource(amazonSourceForTest(), $this->dataSource)->import();

    $shipment = Shipment::where('shipment_reference', '111-2222222-3333333')->firstOrFail();

    // 2026-08-16T03:59:59Z is still 2026-08-15 in the default location's timezone.
    expect($shipment->deliver_by->toDateString())->toBe('2026-08-15')
        ->and($shipment->metadata['amazon_ship_by_window']['latestDateTime'])->toBe('2026-08-13T03:00:00Z')
        ->and($shipment->metadata['amazon_deliver_by_window']['latestDateTime'])->toBe('2026-08-16T03:59:59Z');
});

it('leaves deliver_by null when Amazon returns no deliver-by window', function (): void {
    tap(Channel::factory()->create(['name' => 'Amazon']), fn ($c) => ChannelAlias::create(['reference' => 'Amazon', 'channel_id' => $c->id]));

    $order = sampleAmazonOrder();
    unset($order['fulfillment']['deliverByWindow']);

    Saloon::fake([SearchOrders::class => amazonOrdersResponse([$order])]);

    ShipmentImportService::forSource(amazonSourceForTest(), $this->dataSource)->import();

    expect(Shipment::where('shipment_reference', '111-2222222-3333333')->firstOrFail()->deliver_by)->toBeNull();
});

it('exports package to amazon as shipment confirmation', function (): void {
    $channel = Channel::factory()->create(['name' => 'Amazon']);

    $exportSource = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'name' => 'Amazon Export',
        'settings' => [
            'channel_name' => 'Amazon',
            'marketplace_id' => 'ATVPDKIKX0DER',
            'export_enabled' => true,
            'export_field_mapping' => [
                'tracking_number' => 'tracking_number',
                'carrier' => 'carrier',
                'shipment_reference' => 'shipment_reference',
                'amazon_order_id' => 'amazon_order_id',
            ],
        ],
        'secret_settings' => [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'refresh_token' => 'test-refresh-token',
        ],
    ]);

    $shipment = Shipment::factory()->create([
        'channel_id' => $channel->id,
        'data_source_id' => $exportSource->id,
        'shipment_reference' => '111-2222222-3333333',
        'metadata' => [
            'amazon_order_id' => '111-2222222-3333333',
        ],
    ]);

    $package = Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'tracking_number' => 'TRACK123',
        'carrier' => 'USPS',
        'service' => 'Priority Mail',
        'exported' => false,
        'shipped_at' => '2026-08-07 15:30:00',
    ]);

    $product = Product::factory()->create();
    $shipmentItem = $shipment->shipmentItems()->create([
        'product_id' => $product->id,
        'source_item_id' => 'AMAZON-ITEM-123',
        'quantity' => 2,
    ]);
    PackageItem::factory()->create([
        'package_id' => $package->id,
        'shipment_item_id' => $shipmentItem->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'transparency_codes' => ['AZ:TRANSPARENCY'],
    ]);

    Saloon::fake([
        ConfirmShipment::class => amazonConfirmShipmentResponse(),
    ]);

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    expect($result->success)->toBeTrue();
    expect($result->destinationsAttempted)->toBe(1);
    expect($result->destinationsSucceeded)->toBe(1);
    expect($package->fresh()->exported)->toBeTrue();

    Saloon::assertSent(function (ConfirmShipment $request) use ($package): bool {
        $body = $request->body()->all();

        assertMatchesSpApiSchema($body, 'ConfirmShipmentRequest');

        return $request->resolveEndpoint() === '/orders/v0/orders/111-2222222-3333333/shipmentConfirmation'
            && $body === [
                'marketplaceId' => 'ATVPDKIKX0DER',
                'packageDetail' => [
                    'packageReferenceId' => (string) $package->id,
                    'carrierCode' => 'USPS',
                    'shippingMethod' => 'Priority Mail',
                    'trackingNumber' => 'TRACK123',
                    'shipDate' => '2026-08-07T15:30:00+00:00',
                    'orderItems' => [[
                        'orderItemId' => 'AMAZON-ITEM-123',
                        'quantity' => 2,
                        'transparencyCodes' => ['AZ:TRANSPARENCY'],
                    ]],
                ],
            ];
    });

    $secondPackage = Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'tracking_number' => 'TRACK456',
        'carrier' => 'USPS',
        'service' => 'Priority Mail',
        'exported' => false,
    ]);
    PackageItem::factory()->create([
        'package_id' => $secondPackage->id,
        'shipment_item_id' => $shipmentItem->id,
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    $secondResult = $service->exportPackage($secondPackage);

    expect($secondResult->success)->toBeTrue();
    Saloon::assertSent(function (ConfirmShipment $request) use ($secondPackage): bool {
        assertMatchesSpApiSchema($request->body()->all(), 'ConfirmShipmentRequest');

        $packageDetail = $request->body()->all()['packageDetail'] ?? [];

        return ($packageDetail['packageReferenceId'] ?? null) === (string) $secondPackage->id
            && ($packageDetail['trackingNumber'] ?? null) === 'TRACK456';
    });
    Saloon::assertSentCount(2);
});

it('confirms a Shopify-bought label under the carrier that carries it', function (): void {
    $usps = Carrier::factory()->usps()->create();
    CarrierAlias::factory()->for($usps)->create(['alias' => 'United States Postal Service']);

    $exportSource = amazonExportDestination();
    $shopifySource = DataSource::factory()->shopify()->create();
    $package = amazonExportPackage($exportSource, [
        // Shipped below instead, so the purchase writes the carrier of record.
        'status' => 'unshipped',
        'tracking_number' => null,
    ]);

    // Export explicitly below rather than through the ship-time listener.
    Event::fake([PackageShipped::class]);

    // Shopify picks the carrier itself and reports it in its own spelling. What
    // it bought is never reported, so only the preference is on record.
    $package->markShipped(new ShipResponse(
        success: true,
        trackingNumber: 'TRACK123',
        carrier: 'United States Postal Service',
        service: null,
        postageSource: PostageSource::PostageDataSource,
        postageDataSourceId: $shopifySource->id,
        requestedService: 'Standard',
        serviceEvidence: ServiceEvidence::Unknown,
    ), PostageSource::PostageDataSource);

    Saloon::fake([ConfirmShipment::class => amazonConfirmShipmentResponse()]);

    expect((new PackageExportService)->exportPackage($package)->success)->toBeTrue();

    Saloon::assertSent(function (ConfirmShipment $request): bool {
        $body = $request->body()->all();
        $packageDetail = $body['packageDetail'] ?? [];

        assertMatchesSpApiSchema($body, 'ConfirmShipmentRequest');

        return ($packageDetail['carrierCode'] ?? null) === 'USPS'
            && ! array_key_exists('carrierName', $packageDetail)
            // A preference is not a purchase: Amazon is told the carrier and
            // the tracking number, and nothing about the service.
            && ! array_key_exists('shippingMethod', $packageDetail)
            && ($packageDetail['trackingNumber'] ?? null) === 'TRACK123';
    });
});

it('confirms an unmapped carrier of record as Other under its own name', function (): void {
    $package = amazonExportPackage(amazonExportDestination(), [
        'carrier' => 'Poste Italiane',
        'normalized_carrier_id' => null,
    ]);

    Saloon::fake([ConfirmShipment::class => amazonConfirmShipmentResponse()]);

    expect((new PackageExportService)->exportPackage($package)->success)->toBeTrue();

    Saloon::assertSent(function (ConfirmShipment $request): bool {
        $body = $request->body()->all();
        $packageDetail = $body['packageDetail'] ?? [];

        assertMatchesSpApiSchema($body, 'ConfirmShipmentRequest');

        return ($packageDetail['carrierCode'] ?? null) === 'Other'
            && ($packageDetail['carrierName'] ?? null) === 'Poste Italiane';
    });
});

it('retries amazon shipment confirmation authentication failures', function (int $status): void {
    $exportSource = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'settings' => [
            'marketplace_id' => 'ATVPDKIKX0DER',
            'export_enabled' => true,
        ],
        'secret_settings' => [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'refresh_token' => 'test-refresh-token',
        ],
    ]);
    $shipment = Shipment::factory()->create([
        'data_source_id' => $exportSource,
        'shipment_reference' => '111-2222222-3333333',
        'metadata' => ['amazon_order_id' => '111-2222222-3333333'],
    ]);
    $package = Package::factory()->shipped()->create([
        'shipment_id' => $shipment,
        'tracking_number' => 'TRACK-AUTH',
        'carrier' => 'USPS',
        'exported' => false,
        'shipped_at' => now(),
    ]);
    $item = ShipmentItem::factory()->create([
        'shipment_id' => $shipment,
        'source_item_id' => 'AMAZON-AUTH-ITEM',
    ]);
    PackageItem::factory()->create([
        'package_id' => $package,
        'shipment_item_id' => $item,
        'product_id' => $item->product_id,
        'quantity' => 1,
    ]);
    Saloon::fake([
        ConfirmShipment::class => MockResponse::make([
            'errors' => [['code' => 'Unauthorized', 'message' => 'Try again']],
        ], $status),
    ]);

    $result = (new PackageExportService)->exportPackage($package);
    $export = PackageExport::query()->where('package_id', $package->id)->firstOrFail();

    expect($result->shouldRetry())->toBeTrue()
        ->and($export->status)->toBe(PackageExportStatus::RetryableFailed);
})->with([401, 403]);

it('treats an already shipped amazon response as idempotent success', function (): void {
    $exportSource = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'settings' => ['marketplace_id' => 'ATVPDKIKX0DER', 'export_enabled' => true],
        'secret_settings' => [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'refresh_token' => 'test-refresh-token',
        ],
    ]);
    $shipment = Shipment::factory()->create([
        'data_source_id' => $exportSource,
        'shipment_reference' => '111-2222222-3333333',
        'metadata' => ['amazon_order_id' => '111-2222222-3333333'],
    ]);
    $package = Package::factory()->shipped()->create([
        'shipment_id' => $shipment,
        'tracking_number' => 'TRACK-DUPLICATE',
        'carrier' => 'USPS',
        'exported' => false,
    ]);
    $item = ShipmentItem::factory()->create([
        'shipment_id' => $shipment,
        'source_item_id' => 'AMAZON-DUPLICATE-ITEM',
    ]);
    PackageItem::factory()->create([
        'package_id' => $package,
        'shipment_item_id' => $item,
        'product_id' => $item->product_id,
        'quantity' => 1,
    ]);
    Saloon::fake([
        ConfirmShipment::class => MockResponse::make([
            'errors' => [['code' => 'InvalidInput', 'message' => 'The order has already been shipped.']],
        ], 400),
    ]);

    $result = (new PackageExportService)->exportPackage($package);
    $export = PackageExport::query()->where('package_id', $package->id)->firstOrFail();

    expect($result->errors)->toBeEmpty()
        ->and($result->success)->toBeTrue()
        ->and($export->status)->toBe(PackageExportStatus::Succeeded)
        ->and($package->fresh()->exported)->toBeTrue();
});

it('reports a safe amazon package reference when shipment reference is not mapped', function (): void {
    $exportSource = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'settings' => [
            'marketplace_id' => 'ATVPDKIKX0DER',
            'export_enabled' => true,
            'export_field_mapping' => [
                'amazon_order_id' => 'amazon_order_id',
                'carrier' => 'carrier',
            ],
        ],
        'secret_settings' => [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'refresh_token' => 'test-refresh-token',
        ],
    ]);
    $shipment = Shipment::factory()->create([
        'data_source_id' => $exportSource,
        'shipment_reference' => '111-2222222-3333333',
        'metadata' => ['amazon_order_id' => '111-2222222-3333333'],
    ]);
    $package = Package::factory()->shipped()->create([
        'shipment_id' => $shipment,
        'tracking_number' => 'TRACK-MISSING-MAPPING',
        'carrier' => 'USPS',
        'exported' => false,
    ]);
    $item = ShipmentItem::factory()->create([
        'shipment_id' => $shipment,
        'source_item_id' => 'AMAZON-MISSING-MAPPING-ITEM',
    ]);
    PackageItem::factory()->create([
        'package_id' => $package,
        'shipment_item_id' => $item,
        'product_id' => $item->product_id,
        'quantity' => 1,
    ]);

    $result = (new PackageExportService)->exportPackage($package);

    expect($result->errors[0])->toContain("package {$package->id}")
        ->and($result->errors[0])->toContain('tracking_number is missing')
        ->and($result->errors[0])->not->toContain('Undefined array key');
});

it('handles package without amazon metadata gracefully in export', function (): void {
    $channel = Channel::factory()->create(['name' => 'Amazon']);

    $exportSource = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'name' => 'Amazon Export',
        'settings' => [
            'channel_name' => 'Amazon',
            'marketplace_id' => 'ATVPDKIKX0DER',
            'export_enabled' => true,
            'export_field_mapping' => [
                'tracking_number' => 'tracking_number',
                'carrier' => 'carrier',
                'shipment_reference' => 'shipment_reference',
                'amazon_order_id' => 'amazon_order_id',
            ],
        ],
        'secret_settings' => [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'refresh_token' => 'test-refresh-token',
        ],
    ]);

    $shipment = Shipment::factory()->create([
        'channel_id' => $channel->id,
        'data_source_id' => $exportSource->id,
        'shipment_reference' => '111-0000000-0000000',
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

    // Should fail gracefully (no Amazon order ID)
    expect($result->success)->toBeFalse();
    expect($result->errors)->not->toBeEmpty();
    expect($result->errors[0])->toContain('Amazon order ID');
});

it('does not send an amazon confirmation without packed order item identifiers', function (): void {
    $exportSource = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'name' => 'Amazon Export',
        'settings' => [
            'marketplace_id' => 'ATVPDKIKX0DER',
            'export_enabled' => true,
        ],
        'secret_settings' => [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'refresh_token' => 'test-refresh-token',
        ],
    ]);
    $shipment = Shipment::factory()->create([
        'data_source_id' => $exportSource->id,
        'shipment_reference' => '111-2222222-3333333',
        'metadata' => ['amazon_order_id' => '111-2222222-3333333'],
    ]);
    $package = Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'tracking_number' => 'TRACK123',
        'carrier' => 'USPS',
        'exported' => false,
    ]);

    Saloon::fake([ConfirmShipment::class => amazonConfirmShipmentResponse()]);

    $service = new PackageExportService;
    $result = $service->exportPackage($package);
    $export = PackageExport::query()->where('package_id', $package->id)->firstOrFail();

    expect($result->success)->toBeFalse()
        ->and($result->errors[0])->toContain('no Amazon order items were packed')
        ->and($package->fresh()->exported)->toBeFalse()
        ->and($export->status)->toBe(PackageExportStatus::PermanentlyFailed);
    $this->travel(5)->minutes();
    expect($service->exportUnexported())->not->toHaveKey($package->id);
    Saloon::assertNotSent(ConfirmShipment::class);
});

it('exports legacy amazon packages in sandbox without production item context', function (): void {
    app(SettingsService::class)->set('sandbox_mode', true, 'boolean');
    $exportSource = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'settings' => ['marketplace_id' => 'ATVPDKIKX0DER', 'export_enabled' => true],
        'secret_settings' => [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'refresh_token' => 'test-refresh-token',
        ],
    ]);
    $shipment = Shipment::factory()->create([
        'data_source_id' => $exportSource,
        'metadata' => ['amazon_order_id' => '111-2222222-3333333'],
    ]);
    $package = Package::factory()->shipped()->create([
        'shipment_id' => $shipment,
        'exported' => false,
    ]);
    $legacyItem = ShipmentItem::factory()->create([
        'shipment_id' => $shipment,
        'source_item_id' => null,
    ]);
    PackageItem::factory()->create([
        'package_id' => $package,
        'shipment_item_id' => $legacyItem,
        'product_id' => $legacyItem->product_id,
    ]);
    Saloon::fake([ConfirmShipment::class => amazonConfirmShipmentResponse()]);

    $result = (new PackageExportService)->exportPackage($package);

    expect($result->success)->toBeTrue()
        ->and($package->fresh()->exported)->toBeTrue();
    Saloon::assertSent(ConfirmShipment::class);
});

// The sandbox import and the sandbox export are unrelated fixtures, not two halves of
// one order: the import drives Amazon's JP Orders v2026 test case on the FE host, while
// the export posts Amazon's US confirmShipment test case, which only the NA host serves.
// Asserted on the URL the export actually sends, because the pairing reads the other way.
it('sends a sandbox shipment confirmation to the North America host', function (): void {
    app(SettingsService::class)->set('sandbox_mode', true, 'boolean');
    $package = amazonExportPackage(amazonExportDestination());

    Saloon::fake([ConfirmShipment::class => amazonConfirmShipmentResponse()]);

    expect((new PackageExportService)->exportPackage($package)->success)->toBeTrue();

    Saloon::assertSent(fn (ConfirmShipment $request, $response): bool => $response->getPendingRequest()->getUrl()
        === 'https://sandbox.sellingpartnerapi-na.amazon.com/orders/v0/orders/902-1106328-1059050/shipmentConfirmation');
});

it('sends a sandbox order search to the Far East host', function (): void {
    tap(Channel::factory()->create(['name' => 'Amazon']), fn ($c) => ChannelAlias::create(['reference' => 'Amazon', 'channel_id' => $c->id]));
    app(SettingsService::class)->set('sandbox_mode', true, 'boolean');

    Saloon::fake([SearchOrders::class => amazonOrdersResponse([sampleAmazonOrder()])]);

    $source = new AmazonSource([
        'source_type' => AmazonSource::class,
        'enabled' => true,
        'channel_name' => 'Amazon',
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'refresh_token' => 'test-refresh-token',
        'marketplace_id' => 'ATVPDKIKX0DER',
        'shipping_method' => null,
        'lookback_days' => 30,
        'export' => ['enabled' => false, 'field_mapping' => []],
    ]);

    ShipmentImportService::forSource($source, $this->dataSource)->import();

    Saloon::assertSent(fn (SearchOrders $request, $response): bool => $response->getPendingRequest()->getUrl()
        === 'https://sandbox.sellingpartnerapi-fe.amazon.com/orders/2026-01-01/orders');
});

it('does not send a partial amazon shipment confirmation', function (): void {
    $exportSource = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'settings' => ['marketplace_id' => 'ATVPDKIKX0DER', 'export_enabled' => true],
        'secret_settings' => [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'refresh_token' => 'test-refresh-token',
        ],
    ]);
    $shipment = Shipment::factory()->create([
        'data_source_id' => $exportSource->id,
        'shipment_reference' => '111-2222222-3333333',
        'metadata' => ['amazon_order_id' => '111-2222222-3333333'],
    ]);
    $package = Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'tracking_number' => 'TRACK123',
        'carrier' => 'USPS',
        'exported' => false,
    ]);
    $mappedItem = ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'source_item_id' => 'AMAZON-ITEM-123',
    ]);
    $unmappedItem = ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'source_item_id' => null,
    ]);
    PackageItem::factory()->create([
        'package_id' => $package->id,
        'shipment_item_id' => $mappedItem->id,
        'product_id' => $mappedItem->product_id,
    ]);
    PackageItem::factory()->create([
        'package_id' => $package->id,
        'shipment_item_id' => $unmappedItem->id,
        'product_id' => $unmappedItem->product_id,
    ]);

    Saloon::fake([ConfirmShipment::class => amazonConfirmShipmentResponse()]);

    $result = (new PackageExportService)->exportPackage($package);

    expect($result->success)->toBeFalse()
        ->and($result->errors[0])->toContain('every packed item')
        ->and($package->fresh()->exported)->toBeFalse();
    Saloon::assertNotSent(ConfirmShipment::class);
});

it('omits zero quantity package rows from amazon shipment confirmation', function (): void {
    $exportSource = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'settings' => ['marketplace_id' => 'ATVPDKIKX0DER', 'export_enabled' => true],
        'secret_settings' => [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'refresh_token' => 'test-refresh-token',
        ],
    ]);
    $shipment = Shipment::factory()->create([
        'data_source_id' => $exportSource->id,
        'shipment_reference' => '111-2222222-3333333',
        'metadata' => ['amazon_order_id' => '111-2222222-3333333'],
    ]);
    $package = Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'tracking_number' => 'TRACK123',
        'carrier' => 'USPS',
    ]);
    $packedItem = ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'source_item_id' => 'PACKED-LINE',
    ]);
    $unpackedItem = ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'source_item_id' => 'UNPACKED-LINE',
    ]);
    PackageItem::factory()->create([
        'package_id' => $package->id,
        'shipment_item_id' => $packedItem->id,
        'product_id' => $packedItem->product_id,
        'quantity' => 1,
    ]);
    PackageItem::factory()->create([
        'package_id' => $package->id,
        'shipment_item_id' => $unpackedItem->id,
        'product_id' => $unpackedItem->product_id,
        'quantity' => 0,
    ]);
    Saloon::fake([ConfirmShipment::class => amazonConfirmShipmentResponse()]);

    $result = (new PackageExportService)->exportPackage($package);

    expect($result->success)->toBeTrue();
    Saloon::assertSent(function (ConfirmShipment $request): bool {
        assertMatchesSpApiSchema($request->body()->all(), 'ConfirmShipmentRequest');

        return ($request->body()->all()['packageDetail']['orderItems'] ?? []) === [[
            'orderItemId' => 'PACKED-LINE',
            'quantity' => 1,
        ]];
    });
});

it('imports multiple pages of amazon orders', function (): void {
    $channel = tap(Channel::factory()->create(['name' => 'Amazon']), fn ($c) => ChannelAlias::create(['reference' => 'Amazon', 'channel_id' => $c->id]));

    // Sequential mocks: SearchOrders(page1) → SearchOrders(page2)
    // Items are embedded in the order response — no separate fetch needed
    $mockClient = Saloon::fake([
        amazonOrdersResponse(
            [sampleAmazonOrder('111-1111111-1111111')],
            nextToken: 'token_page2'
        ),
        amazonOrdersResponse(
            [sampleAmazonOrder('111-2222222-2222222')],
        ),
    ]);

    $source = new AmazonSource([
        'source_type' => AmazonSource::class,
        'enabled' => true,
        'channel_name' => 'Amazon',
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'refresh_token' => 'test-refresh-token',
        'marketplace_id' => 'ATVPDKIKX0DER',
        'shipping_method' => null,
        'lookback_days' => 30,
        'export' => ['enabled' => false, 'field_mapping' => []],
    ]);

    $result = ShipmentImportService::forSource($source, $this->dataSource)->import();

    expect($result->shipmentsCreated)->toBe(2);
    expect(Shipment::where('shipment_reference', '111-1111111-1111111')->exists())->toBeTrue();
    expect(Shipment::where('shipment_reference', '111-2222222-2222222')->exists())->toBeTrue();

    $searchRequests = collect($mockClient->getRecordedResponses())
        ->map(fn ($response) => $response->getPendingRequest()->getRequest())
        ->filter(fn ($request): bool => $request instanceof SearchOrders)
        ->values();
    $firstQuery = $searchRequests[0]->query()->all();
    $secondQuery = $searchRequests[1]->query()->all();

    expect($secondQuery['paginationToken'])->toBe('token_page2')
        ->and($secondQuery['lastUpdatedAfter'])->toBe($firstQuery['lastUpdatedAfter'])
        ->and($secondQuery['fulfillmentStatuses'])->toBe($firstQuery['fulfillmentStatuses'])
        ->and($secondQuery['maxResultsPerPage'])->toBe(100);
});

it('imports a bounded historical shipped-order sample with full quantities', function (): void {
    $channel = tap(Channel::factory()->create(['name' => 'Amazon']), fn ($c) => ChannelAlias::create(['reference' => 'Amazon', 'channel_id' => $c->id]));

    $orders = collect(range(1, 4))->map(function (int $number): array {
        $order = sampleAmazonOrder("111-0000000-000000{$number}");
        $order['createdTime'] = '2025-12-01T12:00:00Z';
        $order['salesChannel'] = 'Amazon.com';
        $order['fulfillment'] = [
            'fulfillmentStatus' => 'SHIPPED',
            'fulfilledBy' => 'MERCHANT',
            'fulfillmentServiceLevel' => 'EXPEDITED',
        ];
        $order['orderItems'][0]['product']['asin'] = 'B000TEST01';
        $order['orderItems'][0]['fulfillment']['quantityFulfilled'] = 3;
        $order['orderItems'][1]['fulfillment']['quantityFulfilled'] = 1;

        return $order;
    })->all();

    Saloon::fake([
        SearchOrders::class => amazonOrdersResponse($orders),
        SearchCatalogItems::class => amazonCatalogResponse([
            [
                'asin' => 'B000TEST01',
                'identifiers' => [
                    [
                        'marketplaceId' => 'ATVPDKIKX0DER',
                        'identifiers' => [
                            ['identifierType' => 'EAN', 'identifier' => '0012345678905'],
                            ['identifierType' => 'GTIN', 'identifier' => '00012345678905'],
                            ['identifierType' => 'UPC', 'identifier' => '012345678905'],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $result = ShipmentImportService::forRecord($this->dataSource, [
        '_historical_import' => true,
        '_historical_created_after' => '2025-12-01T00:00:00Z',
        '_historical_created_before' => '2025-12-08T23:59:59Z',
        '_historical_max_orders' => 2,
    ])->import();

    expect($result->shipmentsCreated)->toBe(2)
        ->and($result->itemsCreated)->toBe(4)
        ->and(Shipment::count())->toBe(2);

    $shipment = Shipment::where('shipment_reference', '111-0000000-0000001')->firstOrFail();

    expect($shipment->status)->toBe(ShipmentStatus::Shipped)
        ->and($shipment->shipmentItems)->toHaveCount(2)
        ->and($shipment->shipmentItems->sum('quantity'))->toBe(4)
        ->and($shipment->metadata['amazon_order_status'])->toBe('SHIPPED')
        ->and($shipment->metadata['amazon_created_time'])->toBe('2025-12-01T12:00:00Z')
        ->and($shipment->metadata['amazon_sales_channel'])->toBe('Amazon.com')
        ->and($shipment->metadata['amazon_fulfilled_by'])->toBe('MERCHANT')
        ->and($shipment->metadata['amazon_fulfillment_service_level'])->toBe('EXPEDITED')
        ->and($shipment->metadata['amazon_asins'])->toBe(['B000TEST01'])
        ->and($shipment->metadata['amazon_recipient_available'])->toBeTrue();

    $product = Product::where('sku', 'SKU-100')->firstOrFail();

    expect($product->barcode)->toBe('012345678905');

    Saloon::assertSent(function ($request): bool {
        if (! $request instanceof SearchOrders) {
            return false;
        }

        $query = $request->query()->all();

        return $query['fulfillmentStatuses'] === 'SHIPPED'
            && $query['createdAfter'] === '2025-12-01T00:00:00Z'
            && $query['createdBefore'] === '2025-12-08T23:59:59Z'
            && $query['maxResultsPerPage'] === 2
            && str_contains($query['includedData'], 'PACKAGES')
            && ! str_contains($query['includedData'], 'BUYER');
    });
});

it('does not replace a manually assigned product barcode during catalog enrichment', function (): void {
    tap(Channel::factory()->create(['name' => 'Amazon']), fn ($channel) => ChannelAlias::create(['reference' => 'Amazon', 'channel_id' => $channel->id]));

    Product::factory()->create([
        'client_id' => $this->dataSource->client_id,
        'sku' => 'SKU-100',
        'barcode' => 'MANUAL-BARCODE',
    ]);

    $order = sampleAmazonOrder();
    $order['orderItems'][0]['product']['asin'] = 'B000TEST01';

    Saloon::fake([
        SearchOrders::class => amazonOrdersResponse([$order]),
        SearchCatalogItems::class => amazonCatalogResponse([[
            'asin' => 'B000TEST01',
            'identifiers' => [[
                'marketplaceId' => 'ATVPDKIKX0DER',
                'identifiers' => [
                    ['identifierType' => 'UPC', 'identifier' => '012345678905'],
                ],
            ]],
        ]]),
    ]);

    ShipmentImportService::forRecord($this->dataSource, [
        '_historical_import' => true,
        '_historical_created_after' => '2025-12-01T00:00:00Z',
        '_historical_created_before' => '2025-12-08T23:59:59Z',
        '_historical_max_orders' => 1,
    ])->import();

    expect(Product::where('sku', 'SKU-100')->firstOrFail()->barcode)->toBe('MANUAL-BARCODE');
});

it('imports up to one thousand historical orders in pages of one hundred', function (): void {
    $responses = collect(range(0, 9))->map(function (int $page): MockResponse {
        $orders = collect(range(1, 100))->map(fn (int $number): array => [
            'orderId' => sprintf('111-%07d-%07d', $page, $number),
            'fulfillment' => ['fulfillmentStatus' => 'SHIPPED'],
            'orderItems' => [],
        ])->all();

        return amazonOrdersResponse($orders, $page < 9 ? 'page-'.($page + 2) : null);
    })->all();

    $mockClient = Saloon::fake($responses);

    $source = new AmazonSource([
        'channel_name' => 'Amazon',
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'refresh_token' => 'test-refresh-token',
        'marketplace_id' => 'ATVPDKIKX0DER',
        '_historical_import' => true,
        '_historical_created_after' => '2025-12-01T00:00:00Z',
        '_historical_created_before' => '2025-12-08T23:59:59Z',
        '_historical_max_orders' => 1000,
    ]);

    expect($source->fetchShipments())->toHaveCount(1000);

    $searchRequests = collect($mockClient->getRecordedResponses())
        ->map(fn ($response) => $response->getPendingRequest()->getRequest())
        ->filter(fn ($request): bool => $request instanceof SearchOrders)
        ->values();

    expect($searchRequests)->toHaveCount(10)
        ->and($searchRequests->every(
            fn (SearchOrders $request): bool => $request->query()->all()['maxResultsPerPage'] === 100
                && $request->query()->all()['fulfillmentStatuses'] === 'SHIPPED'
                && $request->query()->all()['createdAfter'] === '2025-12-01T00:00:00Z'
                && $request->query()->all()['createdBefore'] === '2025-12-08T23:59:59Z'
        ))->toBeTrue();
});

it('rejects historical Amazon imports over one thousand orders', function (): void {
    $source = new AmazonSource([
        'channel_name' => 'Amazon',
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'refresh_token' => 'test-refresh-token',
        'marketplace_id' => 'ATVPDKIKX0DER',
        '_historical_import' => true,
        '_historical_created_after' => '2025-12-01T00:00:00Z',
        '_historical_created_before' => '2025-12-08T23:59:59Z',
        '_historical_max_orders' => 1001,
    ]);

    expect(fn () => $source->validateConfiguration())
        ->toThrow(InvalidArgumentException::class, '1–1000 orders');
});

it('retains an old shipped order when Amazon no longer returns its recipient address', function (): void {
    tap(Channel::factory()->create(['name' => 'Amazon']), fn ($channel) => ChannelAlias::create(['reference' => 'Amazon', 'channel_id' => $channel->id]));

    $order = sampleAmazonOrder();
    $order['fulfillment']['fulfillmentStatus'] = 'SHIPPED';
    $order['recipient'] = [];
    $order['orderItems'][0]['fulfillment']['quantityFulfilled'] = 3;
    $order['orderItems'][1]['fulfillment']['quantityFulfilled'] = 1;

    Saloon::fake([
        SearchOrders::class => amazonOrdersResponse([$order]),
        SearchCatalogItems::class => amazonCatalogResponse(),
    ]);

    $source = new AmazonSource([
        'channel_name' => 'Amazon',
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'refresh_token' => 'test-refresh-token',
        'marketplace_id' => 'ATVPDKIKX0DER',
        '_historical_import' => true,
        '_historical_created_after' => '2025-12-01T00:00:00Z',
        '_historical_created_before' => '2025-12-08T23:59:59Z',
        '_historical_max_orders' => 1,
    ]);

    $result = ShipmentImportService::forSource($source, $this->dataSource)->import();
    $shipment = Shipment::firstOrFail();

    expect($result->shipmentsCreated)->toBe(1)
        ->and($shipment->address1)->toBe('[Unavailable from Amazon]')
        ->and($shipment->city)->toBe('[Unavailable]')
        ->and($shipment->metadata['amazon_recipient_available'])->toBeFalse();
});

it('preserves existing recipient data while marking a historical Amazon order shipped', function (): void {
    tap(Channel::factory()->create(['name' => 'Amazon']), fn ($channel) => ChannelAlias::create(['reference' => 'Amazon', 'channel_id' => $channel->id]));

    Shipment::factory()->create([
        'client_id' => $this->dataSource->client_id,
        'data_source_id' => $this->dataSource->id,
        'source_record_id' => '111-2222222-3333333',
        'shipment_reference' => '111-2222222-3333333',
        'status' => ShipmentStatus::Open,
        'first_name' => 'Original',
        'last_name' => 'Recipient',
        'address1' => '123 Existing St',
        'city' => 'Portland',
        'state_or_province' => 'OR',
        'postal_code' => '97201',
        'country' => 'US',
        'email' => 'preserve@example.com',
    ]);

    $order = sampleAmazonOrder();
    $order['fulfillment']['fulfillmentStatus'] = 'SHIPPED';
    $order['recipient'] = [];

    Saloon::fake([
        SearchOrders::class => amazonOrdersResponse([$order]),
        SearchCatalogItems::class => amazonCatalogResponse(),
    ]);

    $result = ShipmentImportService::forRecord($this->dataSource, [
        '_historical_import' => true,
        '_historical_created_after' => '2025-12-01T00:00:00Z',
        '_historical_created_before' => '2025-12-08T23:59:59Z',
        '_historical_max_orders' => 1,
    ])->import();

    $shipment = Shipment::where('source_record_id', '111-2222222-3333333')->firstOrFail();

    expect($result->shipmentsUpdated)->toBe(1)
        ->and($shipment->status)->toBe(ShipmentStatus::Shipped)
        ->and($shipment->first_name)->toBe('Original')
        ->and($shipment->address1)->toBe('123 Existing St')
        ->and($shipment->city)->toBe('Portland')
        ->and($shipment->email)->toBe('preserve@example.com');
});

it('preserves an existing buyer email when Amazon omits buyer data', function (): void {
    tap(Channel::factory()->create(['name' => 'Amazon']), fn ($channel) => ChannelAlias::create(['reference' => 'Amazon', 'channel_id' => $channel->id]));

    Shipment::factory()->create([
        'client_id' => $this->dataSource->client_id,
        'data_source_id' => $this->dataSource->id,
        'source_record_id' => '111-2222222-3333333',
        'shipment_reference' => '111-2222222-3333333',
        'status' => ShipmentStatus::Open,
        'email' => 'preserve@example.com',
    ]);

    Saloon::fake([
        SearchOrders::class => amazonOrdersResponse([sampleAmazonOrder()]),
    ]);

    ShipmentImportService::forRecord($this->dataSource)->import();

    expect(Shipment::where('source_record_id', '111-2222222-3333333')->value('email'))
        ->toBe('preserve@example.com');
});

it('does not log historical Amazon response payloads', function (): void {
    $order = sampleAmazonOrder();
    $order['orderItems'][0]['product']['asin'] = 'B000TEST01';

    Saloon::fake([
        SearchOrders::class => amazonOrdersResponse([$order]),
        SearchCatalogItems::class => amazonCatalogResponse(),
    ]);

    $log = Log::spy();

    $source = new AmazonSource([
        'channel_name' => 'Amazon',
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'refresh_token' => 'test-refresh-token',
        'marketplace_id' => 'ATVPDKIKX0DER',
        '_data_source_id' => $this->dataSource->id,
        '_historical_import' => true,
        '_historical_created_after' => '2025-12-01T00:00:00Z',
        '_historical_created_before' => '2025-12-08T23:59:59Z',
        '_historical_max_orders' => 1,
    ]);

    expect($source->fetchShipments())->toHaveCount(1);

    $log->shouldNotHaveReceived('info');
});

it('batches Amazon catalog barcode lookups into at most twenty ASINs', function (): void {
    Sleep::fake();

    $order = sampleAmazonOrder();
    $order['orderItems'] = collect(range(1, 21))->map(fn (int $number): array => [
        'product' => [
            'asin' => 'B'.str_pad((string) $number, 9, '0', STR_PAD_LEFT),
            'sellerSku' => 'SKU-'.$number,
            'title' => 'Product '.$number,
        ],
        'quantityOrdered' => 1,
        'fulfillment' => ['quantityFulfilled' => 0],
    ])->all();

    $mockClient = Saloon::fake([
        SearchOrders::class => amazonOrdersResponse([$order]),
        SearchCatalogItems::class => amazonCatalogResponse(headers: ['x-amzn-RateLimit-Limit' => '4']),
    ]);

    $source = new AmazonSource([
        'channel_name' => 'Amazon',
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'refresh_token' => 'test-refresh-token',
        'marketplace_id' => 'ATVPDKIKX0DER',
        '_historical_import' => true,
        '_historical_created_after' => '2025-12-01T00:00:00Z',
        '_historical_created_before' => '2025-12-08T23:59:59Z',
        '_historical_max_orders' => 100,
    ]);

    expect($source->fetchShipments())->toHaveCount(1);

    $catalogRequests = collect($mockClient->getRecordedResponses())
        ->map(fn ($response) => $response->getPendingRequest()->getRequest())
        ->filter(fn ($request): bool => $request instanceof SearchCatalogItems)
        ->values();
    $firstQuery = $catalogRequests[0]->query()->all();

    expect($catalogRequests)->toHaveCount(2)
        ->and($catalogRequests[0]->resolveEndpoint())->toBe('/catalog/2022-04-01/items')
        ->and($firstQuery['marketplaceIds'])->toBe('ATVPDKIKX0DER')
        ->and($firstQuery['identifiersType'])->toBe('ASIN')
        ->and($firstQuery['includedData'])->toBe('identifiers')
        ->and(explode(',', $firstQuery['identifiers']))->toHaveCount(20)
        ->and(explode(',', $catalogRequests[1]->query()->all()['identifiers']))->toHaveCount(1);

    Sleep::assertSlept(
        fn ($duration): bool => $duration->totalMilliseconds === 250.0,
        times: 1,
    );
});

it('continues an Amazon order import when catalog barcode lookup fails', function (): void {
    tap(Channel::factory()->create(['name' => 'Amazon']), fn ($channel) => ChannelAlias::create(['reference' => 'Amazon', 'channel_id' => $channel->id]));

    $order = sampleAmazonOrder();
    $order['orderItems'][0]['product']['asin'] = 'B000TEST01';

    Saloon::fake([
        SearchOrders::class => amazonOrdersResponse([$order]),
        SearchCatalogItems::class => MockResponse::make(['errors' => [['code' => 'Unauthorized']]], 403),
    ]);

    $source = new AmazonSource([
        'channel_name' => 'Amazon',
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'refresh_token' => 'test-refresh-token',
        'marketplace_id' => 'ATVPDKIKX0DER',
        '_historical_import' => true,
        '_historical_created_after' => '2025-12-01T00:00:00Z',
        '_historical_created_before' => '2025-12-08T23:59:59Z',
        '_historical_max_orders' => 100,
    ]);

    $result = ShipmentImportService::forSource($source, $this->dataSource)->import();

    expect($result->shipmentsCreated)->toBe(1)
        ->and($result->itemsCreated)->toBe(2)
        ->and($result->errors)->toBeEmpty()
        ->and(Product::where('sku', 'SKU-100')->firstOrFail()->barcode)->toBeNull();
});

it('backs off and recovers when Amazon rate limits a catalog barcode lookup', function (): void {
    tap(Channel::factory()->create(['name' => 'Amazon']), fn ($channel) => ChannelAlias::create(['reference' => 'Amazon', 'channel_id' => $channel->id]));
    Sleep::fake();

    $order = sampleAmazonOrder();
    $order['orderItems'][0]['product']['asin'] = 'B000TEST01';

    Saloon::fake([
        amazonOrdersResponse([$order]),
        MockResponse::make(['errors' => [['code' => 'QuotaExceeded']]], 429, ['x-amzn-RateLimit-Limit' => '2', 'Retry-After' => '3']),
        MockResponse::make(['errors' => [['code' => 'QuotaExceeded']]], 429, ['x-amzn-RateLimit-Limit' => '2']),
        MockResponse::make(['errors' => [['code' => 'QuotaExceeded']]], 429, ['x-amzn-RateLimit-Limit' => '2']),
        amazonCatalogResponse([
            [
                'asin' => 'B000TEST01',
                'identifiers' => [
                    [
                        'marketplaceId' => 'ATVPDKIKX0DER',
                        'identifiers' => [
                            ['identifierType' => 'UPC', 'identifier' => '012345678905'],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $source = new AmazonSource([
        'channel_name' => 'Amazon',
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'refresh_token' => 'test-refresh-token',
        'marketplace_id' => 'ATVPDKIKX0DER',
        '_historical_import' => true,
        '_historical_created_after' => '2025-12-01T00:00:00Z',
        '_historical_created_before' => '2025-12-08T23:59:59Z',
        '_historical_max_orders' => 100,
    ]);

    $result = ShipmentImportService::forSource($source, $this->dataSource)->import();

    expect($result->shipmentsCreated)->toBe(1)
        ->and($result->errors)->toBeEmpty()
        ->and(Product::where('sku', 'SKU-100')->firstOrFail()->barcode)->toBe('012345678905');

    Sleep::assertSequence([
        Sleep::for(3)->seconds(),
        Sleep::for(1)->second(),
        Sleep::for(2)->seconds(),
    ]);
});

it('accepts a historical Amazon import date range longer than thirty one days', function (): void {
    $source = new AmazonSource([
        'channel_name' => 'Amazon',
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'refresh_token' => 'test-refresh-token',
        'marketplace_id' => 'ATVPDKIKX0DER',
        '_historical_import' => true,
        '_historical_created_after' => '2025-01-01T00:00:00Z',
        '_historical_created_before' => '2025-03-01T00:00:00Z',
        '_historical_max_orders' => 11,
    ]);

    expect(fn () => $source->validateConfiguration())
        ->not->toThrow(InvalidArgumentException::class);
});

it('rejects historical production-order imports while sandbox mode is enabled', function (): void {
    app(SettingsService::class)->set('sandbox_mode', true);

    $source = new AmazonSource([
        'channel_name' => 'Amazon',
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'refresh_token' => 'test-refresh-token',
        'marketplace_id' => 'ATVPDKIKX0DER',
        '_historical_import' => true,
        '_historical_created_after' => '2025-12-01T00:00:00Z',
        '_historical_created_before' => '2025-12-08T23:59:59Z',
        '_historical_max_orders' => 1,
    ]);

    expect(fn () => $source->validateConfiguration())
        ->toThrow(RuntimeException::class, 'Disable sandbox mode');
});

it('validates amazon configuration requires credentials', function (): void {
    $source = new AmazonSource([
        'source_type' => AmazonSource::class,
        'enabled' => true,
        'channel_name' => 'Amazon',
        'lookback_days' => 30,
        'export' => ['enabled' => false, 'field_mapping' => []],
    ]);

    expect(fn () => $source->validateConfiguration())
        ->toThrow(InvalidArgumentException::class, 'client credentials');
});

it('validates amazon configuration requires mfa to be enabled', function (): void {
    Setting::where('key', 'require_mfa')->delete();
    app(SettingsService::class)->clearCache();

    $source = new AmazonSource([
        'source_type' => AmazonSource::class,
        'enabled' => true,
        'channel_name' => 'Amazon',
        'lookback_days' => 30,
        'export' => ['enabled' => false, 'field_mapping' => []],
    ]);

    expect(fn () => $source->validateConfiguration())
        ->toThrow(RuntimeException::class, 'Multi-factor authentication must be enabled');
});

it('imports sandbox order with full quantities even when already fulfilled', function (): void {
    $channel = tap(Channel::factory()->create(['name' => 'Amazon']), fn ($c) => ChannelAlias::create(['reference' => 'Amazon', 'channel_id' => $c->id]));

    app(SettingsService::class)->set('sandbox_mode', true);

    // Sandbox order where items are already fulfilled
    $order = sampleAmazonOrder();
    $order['orderItems'] = [
        [
            'product' => [
                'sellerSku' => 'SKU-100',
                'title' => 'Fulfilled Item',
            ],
            'quantityOrdered' => 3,
            'fulfillment' => ['quantityFulfilled' => 3],
            'proceeds' => [
                'breakdowns' => [
                    [
                        'type' => 'ITEM',
                        'subtotal' => ['amount' => '30.00', 'currencyCode' => 'USD'],
                    ],
                ],
            ],
        ],
    ];

    Saloon::fake([
        SearchOrders::class => amazonOrdersResponse([$order]),
    ]);

    $source = new AmazonSource([
        'source_type' => AmazonSource::class,
        'enabled' => true,
        'channel_name' => 'Amazon',
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'refresh_token' => 'test-refresh-token',
        'marketplace_id' => 'ATVPDKIKX0DER',
        'shipping_method' => null,
        'lookback_days' => 30,
        'export' => ['enabled' => false, 'field_mapping' => []],
    ]);

    $result = ShipmentImportService::forSource($source, $this->dataSource)->import();

    expect($result->shipmentsCreated)->toBe(1);

    $shipment = Shipment::where('shipment_reference', '111-2222222-3333333')->first();
    // In sandbox mode, full quantityOrdered (3) is used, not 0
    expect((float) $shipment->value)->toBe(30.0);

    $lineItem = $shipment->shipmentItems->first();
    expect($lineItem->quantity)->toBe(3);
    expect((float) $lineItem->value)->toBe(10.0);
});

it('calculates item unit prices correctly from proceeds breakdowns', function (): void {
    $channel = tap(Channel::factory()->create(['name' => 'Amazon']), fn ($c) => ChannelAlias::create(['reference' => 'Amazon', 'channel_id' => $c->id]));

    $order = sampleAmazonOrder();
    $order['orderItems'] = [
        [
            'product' => [
                'sellerSku' => 'SKU-300',
                'title' => 'Bulk Item',
            ],
            'quantityOrdered' => 4,
            'fulfillment' => ['quantityFulfilled' => 1],
            'proceeds' => [
                'breakdowns' => [
                    [
                        'type' => 'ITEM',
                        'subtotal' => ['amount' => '40.00', 'currencyCode' => 'USD'],
                    ],
                ],
            ],
        ],
    ];

    Saloon::fake([
        SearchOrders::class => amazonOrdersResponse([$order]),
    ]);

    $source = new AmazonSource([
        'source_type' => AmazonSource::class,
        'enabled' => true,
        'channel_name' => 'Amazon',
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'refresh_token' => 'test-refresh-token',
        'marketplace_id' => 'ATVPDKIKX0DER',
        'shipping_method' => null,
        'lookback_days' => 30,
        'export' => ['enabled' => false, 'field_mapping' => []],
    ]);

    $result = ShipmentImportService::forSource($source, $this->dataSource)->import();

    expect($result->shipmentsCreated)->toBe(1);

    $shipment = Shipment::where('shipment_reference', '111-2222222-3333333')->first();
    // Unit price = 40/4 = 10, qty remaining = 3, total = 30
    expect((float) $shipment->value)->toBe(30.0);

    $lineItem = $shipment->shipmentItems->first();
    expect($lineItem->quantity)->toBe(3);
    expect((float) $lineItem->value)->toBe(10.0);
});

it('validates when per-source client_id and client_secret are present without tenant credentials', function (): void {
    $source = new AmazonSource([
        'client_id' => 'per_source_client_id',
        'client_secret' => 'per_source_client_secret',
        'refresh_token' => 'per_source_refresh_token',
        'marketplace_id' => 'ATVPDKIKX0DER',
        'channel_name' => 'Amazon',
        'export' => ['enabled' => false, 'field_mapping' => []],
    ]);

    $source->validateConfiguration();
    expect(true)->toBeTrue();
});

it('refreshes an OAuth-connected Amazon source through polybag-connect', function (): void {
    config([
        'services.oauth.broker_url' => 'https://connect.polybag.app',
        'services.oauth.broker_secret' => 'broker-secret',
        'services.oauth.instance_id' => 'test-instance',
    ]);
    Cache::forget('amazon_sp_api_access_token_'.md5('oauth-refresh-token'));

    Http::fake([
        'connect.polybag.app/oauth/sp-api/refresh' => Http::response([
            'access_token' => 'broker-access-token',
            'expires_in' => 3600,
        ]),
    ]);
    Saloon::fake([
        SearchOrders::class => amazonOrdersResponse(),
    ]);

    $source = new AmazonSource([
        'auth_mode' => 'authorization_code',
        'refresh_token' => 'oauth-refresh-token',
        'marketplace_id' => 'ATVPDKIKX0DER',
        'channel_name' => 'Amazon',
        'lookback_days' => 30,
    ]);

    $source->validateConfiguration();
    $source->fetchShipments();

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://connect.polybag.app/oauth/sp-api/refresh'
            && $request['refresh_token'] === 'oauth-refresh-token'
            && $request['instance_id'] === 'test-instance'
            && $request['signature'] === hash_hmac('sha256', 'oauth-refresh-token', 'broker-secret');
    });
});

it('persists a rotated Amazon refresh token returned by polybag-connect', function (): void {
    config([
        'services.oauth.broker_url' => 'https://connect.polybag.app',
        'services.oauth.broker_secret' => 'broker-secret',
        'services.oauth.instance_id' => 'test-instance',
    ]);
    $dataSource = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'settings' => [
            'auth_mode' => 'authorization_code',
            'marketplace_id' => 'ATVPDKIKX0DER',
            'channel_name' => 'Amazon',
        ],
        'secret_settings' => ['refresh_token' => 'old-refresh-token'],
    ]);
    Cache::forget('amazon_sp_api_access_token_'.md5('old-refresh-token'));
    Cache::forget('amazon_sp_api_access_token_'.md5('rotated-refresh-token'));

    Http::fake([
        'connect.polybag.app/oauth/sp-api/refresh' => Http::response([
            'access_token' => 'broker-access-token',
            'refresh_token' => 'rotated-refresh-token',
            'expires_in' => 3600,
        ]),
    ]);
    Saloon::fake([
        amazonOrdersResponse(nextToken: 'second-page'),
        amazonOrdersResponse(),
    ]);

    $source = app(DataSourceFactory::class)->make($dataSource);
    $source->fetchShipments();

    expect($dataSource->refresh()->secret('refresh_token'))->toBe('rotated-refresh-token')
        ->and(Cache::get('amazon_sp_api_access_token_'.md5('old-refresh-token')))->toBeNull()
        ->and(Cache::get('amazon_sp_api_access_token_'.md5('rotated-refresh-token')))->toBe('broker-access-token');
    Http::assertSentCount(1);
    Saloon::assertSentCount(2);
});

it('reuses the rotated-token cache when Amazon headers are resolved again', function (): void {
    config([
        'services.oauth.broker_url' => 'https://connect.polybag.app',
        'services.oauth.broker_secret' => 'broker-secret',
        'services.oauth.instance_id' => 'test-instance',
    ]);
    $dataSource = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'settings' => ['auth_mode' => 'authorization_code'],
        'secret_settings' => ['refresh_token' => 'old-refresh-token'],
    ]);
    Cache::forget('amazon_sp_api_access_token_'.md5('old-refresh-token'));
    Cache::forget('amazon_sp_api_access_token_'.md5('rotated-refresh-token'));

    Http::fake([
        'connect.polybag.app/oauth/sp-api/refresh' => Http::response([
            'access_token' => 'broker-access-token',
            'refresh_token' => 'rotated-refresh-token',
            'expires_in' => 3600,
        ]),
    ]);

    $connector = new class(baseUrl: 'https://sellingpartnerapi-na.amazon.com', sandboxUrl: 'https://sandbox.sellingpartnerapi-na.amazon.com', clientId: '', clientSecret: '', refreshToken: 'old-refresh-token', authMode: 'authorization_code', dataSourceId: $dataSource->id) extends AmazonSpApiConnector
    {
        public function accessTokenHeader(): string
        {
            return $this->defaultHeaders()['x-amz-access-token'];
        }
    };

    expect($connector->accessTokenHeader())->toBe('broker-access-token')
        ->and($connector->accessTokenHeader())->toBe('broker-access-token');
    Http::assertSentCount(1);
});

it('fails validation when only per-source client_id but no client_secret and no tenant credentials', function (): void {
    $source = new AmazonSource([
        'client_id' => 'per_source_client_id',
        'refresh_token' => 'per_source_refresh_token',
        'marketplace_id' => 'ATVPDKIKX0DER',
        'channel_name' => 'Amazon',
        'export' => ['enabled' => false, 'field_mapping' => []],
    ]);

    // client_secret is absent, so the source has no usable credentials.
    expect(fn () => $source->validateConfiguration())
        ->toThrow(InvalidArgumentException::class, 'client credentials');
});

// ── FBA (Amazon-fulfilled) orders ─────────────────────────────────────────────

it('skips Amazon-fulfilled orders by default', function (): void {
    tap(Channel::factory()->create(['name' => 'Amazon']), fn ($c) => ChannelAlias::create(['reference' => 'Amazon', 'channel_id' => $c->id]));

    $fba = sampleAmazonOrder('111-4444444-4444444');
    $fba['fulfillment']['fulfilledBy'] = 'AMAZON';

    Saloon::fake([
        SearchOrders::class => amazonOrdersResponse([sampleAmazonOrder(), $fba]),
    ]);

    $result = ShipmentImportService::forSource(amazonSourceForTest(), $this->dataSource)->import();

    expect($result->shipmentsCreated)->toBe(1)
        ->and($result->errors)->toBeEmpty()
        ->and(Shipment::where('shipment_reference', '111-4444444-4444444')->exists())->toBeFalse()
        ->and(Shipment::where('shipment_reference', '111-2222222-3333333')->exists())->toBeTrue();
});

it('imports Amazon-fulfilled orders when the source opts in, and marks them', function (): void {
    tap(Channel::factory()->create(['name' => 'Amazon']), fn ($c) => ChannelAlias::create(['reference' => 'Amazon', 'channel_id' => $c->id]));

    $fba = sampleAmazonOrder('111-4444444-4444444');
    $fba['fulfillment']['fulfilledBy'] = 'AMAZON';

    Saloon::fake([SearchOrders::class => amazonOrdersResponse([$fba])]);

    $result = ShipmentImportService::forSource(
        amazonSourceForTest(['import_fba_orders' => true]),
        $this->dataSource,
    )->import();

    expect($result->shipmentsCreated)->toBe(1);

    $shipment = Shipment::where('shipment_reference', '111-4444444-4444444')->firstOrFail();

    expect($shipment->metadata['amazon_fulfilled_by'])->toBe('AMAZON')
        ->and($shipment->isAmazonFulfilled())->toBeTrue();
});

it('counts only imported orders against the historical max, not discarded FBA ones', function (): void {
    tap(Channel::factory()->create(['name' => 'Amazon']), fn ($c) => ChannelAlias::create(['reference' => 'Amazon', 'channel_id' => $c->id]));

    // Two FBA orders sit in front of the merchant-fulfilled ones. If the cap
    // counted fetched rather than kept orders it would fill up on the FBA pair
    // and import almost nothing.
    $orders = [];

    foreach (['111-0000000-0000001', '111-0000000-0000002'] as $orderId) {
        $order = sampleAmazonOrder($orderId);
        $order['fulfillment']['fulfilledBy'] = 'AMAZON';
        $orders[] = $order;
    }

    $orders[] = sampleAmazonOrder('111-0000000-0000003');
    $orders[] = sampleAmazonOrder('111-0000000-0000004');
    // A third merchant-fulfilled order, past the cap, so the cap is still
    // proven to apply rather than the assertion just counting the non-FBA ones.
    $orders[] = sampleAmazonOrder('111-0000000-0000005');

    Saloon::fake([
        SearchOrders::class => amazonOrdersResponse($orders),
        SearchCatalogItems::class => amazonCatalogResponse([]),
    ]);

    $result = ShipmentImportService::forRecord($this->dataSource, [
        '_historical_import' => true,
        '_historical_created_after' => '2025-12-01T00:00:00Z',
        '_historical_created_before' => '2025-12-08T23:59:59Z',
        '_historical_max_orders' => 2,
    ])->import();

    expect($result->shipmentsCreated)->toBe(2)
        ->and(Shipment::pluck('shipment_reference')->sort()->values()->all())
        ->toBe(['111-0000000-0000003', '111-0000000-0000004']);
});

/**
 * A shipped, unexported package on an FBA-flagged Amazon order, wired to an
 * export destination. Returns the package.
 */
function amazonFbaPackageForExport(array $sourceSettings = []): Package
{
    $channel = Channel::factory()->create(['name' => 'Amazon']);

    $exportSource = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'name' => 'Amazon Export',
        'settings' => array_merge([
            'channel_name' => 'Amazon',
            'marketplace_id' => 'ATVPDKIKX0DER',
            'export_enabled' => true,
            'export_field_mapping' => [
                'tracking_number' => 'tracking_number',
                'carrier' => 'carrier',
                'shipment_reference' => 'shipment_reference',
                'amazon_order_id' => 'amazon_order_id',
            ],
        ], $sourceSettings),
        'secret_settings' => [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'refresh_token' => 'test-refresh-token',
        ],
    ]);

    $shipment = Shipment::factory()->create([
        'channel_id' => $channel->id,
        'data_source_id' => $exportSource->id,
        'shipment_reference' => '111-4444444-4444444',
        'metadata' => [
            'amazon_order_id' => '111-4444444-4444444',
            'amazon_fulfilled_by' => 'AMAZON',
        ],
    ]);

    $package = Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'tracking_number' => 'TRACK-FBA',
        'carrier' => 'USPS',
        'service' => 'Priority Mail',
        'exported' => false,
        'shipped_at' => '2026-08-07 15:30:00',
    ]);

    $product = Product::factory()->create();
    $shipmentItem = $shipment->shipmentItems()->create([
        'product_id' => $product->id,
        'source_item_id' => 'AMAZON-ITEM-FBA',
        'quantity' => 1,
    ]);
    PackageItem::factory()->create([
        'package_id' => $package->id,
        'shipment_item_id' => $shipmentItem->id,
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    return $package;
}

it('refuses to confirm a shipment for an Amazon-fulfilled order', function (): void {
    $package = amazonFbaPackageForExport();

    Saloon::fake([ConfirmShipment::class => amazonConfirmShipmentResponse()]);

    $result = (new PackageExportService)->exportPackage($package);

    expect($result->success)->toBeFalse()
        ->and($result->errors[0])->toContain('fulfilled by Amazon (FBA)');

    // Permanent, so it is not left to retry against an order Amazon will never accept.
    expect($package->fresh()->exported)->toBeFalse();
    Saloon::assertNotSent(ConfirmShipment::class);
});

it('refuses an FBA order permanently even when the source credentials are missing', function (): void {
    // Credentials are absent, so validateExportConfiguration() would throw a
    // retryable InvalidArgumentException. The FBA refusal has to win, or the
    // export retries 32 times for a confirmation that can never be valid.
    $package = amazonFbaPackageForExport();
    $package->shipment->dataSource->update(['secret_settings' => []]);

    $result = (new PackageExportService)->exportPackage($package);

    expect($result->success)->toBeFalse()
        ->and($result->errors[0])->toContain('fulfilled by Amazon (FBA)')
        ->and($result->errors[0])->not->toContain('credentials are not configured');

    $export = PackageExport::where('package_id', $package->id)->firstOrFail();

    expect($export->status)->toBe(PackageExportStatus::PermanentlyFailed);
});

it('refuses an FBA order rather than silently passing it as a Buy Shipping label', function (): void {
    // Both flags set is a contradiction: Amazon does not fulfill an order we
    // bought postage for. It must not be recorded as a successful export.
    $package = amazonFbaPackageForExport();
    $package->update([
        'metadata' => array_merge($package->metadata ?? [], [
            AmazonBuyShippingAdapter::SHIPMENT_ID_KEY => 'amzn-shipment-id',
        ]),
    ]);

    $result = (new PackageExportService)->exportPackage($package);

    expect($result->success)->toBeFalse()
        ->and($result->errors[0])->toContain('fulfilled by Amazon (FBA)')
        ->and($package->fresh()->exported)->toBeFalse();
});
