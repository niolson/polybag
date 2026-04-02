<?php

use App\Http\Integrations\Amazon\Requests\ConfirmShipment;
use App\Http\Integrations\Amazon\Requests\SearchOrders;
use App\Models\Channel;
use App\Models\ChannelAlias;
use App\Models\Package;
use App\Models\Setting;
use App\Models\Shipment;
use App\Services\SettingsService;
use App\Services\ShipmentImport\PackageExportService;
use App\Services\ShipmentImport\ShipmentImportService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use Illuminate\Support\Facades\Cache;
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

function amazonConfirmShipmentResponse(): MockResponse
{
    return MockResponse::make([], 200);
}

function sampleAmazonOrder(string $orderId = '111-2222222-3333333'): array
{
    return [
        'orderId' => $orderId,
        'orderStatus' => 'Unshipped',
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
    Setting::updateOrCreate(['key' => 'amazon.client_id'], ['value' => 'test-client-id', 'type' => 'string', 'group' => 'amazon']);
    Setting::updateOrCreate(['key' => 'amazon.client_secret'], ['value' => 'test-client-secret', 'type' => 'string', 'group' => 'amazon']);
    Setting::updateOrCreate(['key' => 'amazon.refresh_token'], ['value' => 'test-refresh-token', 'type' => 'string', 'group' => 'amazon']);
    Setting::updateOrCreate(['key' => 'amazon.marketplace_id'], ['value' => 'ATVPDKIKX0DER', 'type' => 'string', 'group' => 'amazon']);
    app(SettingsService::class)->clearCache();

    config([
        'services.amazon.base_url' => 'https://sellingpartnerapi-na.amazon.com',
        'services.amazon.sandbox_url' => 'https://sandbox.sellingpartnerapi-na.amazon.com',
    ]);

    Cache::put('amazon_sp_api_access_token', 'test-access-token', 3600);
});

it('imports amazon orders into shipments table with metadata', function (): void {
    $channel = tap(Channel::factory()->create(['name' => 'Amazon']), fn ($c) => ChannelAlias::create(['reference' => 'Amazon', 'channel_id' => $c->id]));

    Saloon::fake([
        SearchOrders::class => amazonOrdersResponse([sampleAmazonOrder()]),
    ]);

    $source = new AmazonSource([
        'driver' => AmazonSource::class,
        'enabled' => true,
        'channel_name' => 'Amazon',
        'shipping_method' => null,
        'lookback_days' => 30,
        'export' => ['enabled' => false, 'field_mapping' => []],
    ]);

    $result = ShipmentImportService::forSource($source)->import();

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
    expect($shipment->email)->toBe('test@marketplace.amazon.com');
    expect($shipment->channel_id)->toBe($channel->id);
    expect($shipment->source_record_id)->toBe('111-2222222-3333333');

    // Metadata stored correctly
    expect($shipment->metadata)->toBeArray();
    expect($shipment->metadata['amazon_order_id'])->toBe('111-2222222-3333333');

    // Items created
    expect($shipment->shipmentItems)->toHaveCount(2);
});

it('exports package to amazon as shipment confirmation', function (): void {
    $channel = Channel::factory()->create(['name' => 'Amazon']);

    $shipment = Shipment::factory()->create([
        'channel_id' => $channel->id,
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
    ]);

    Saloon::fake([
        ConfirmShipment::class => amazonConfirmShipmentResponse(),
    ]);

    config([
        'shipment-import.sources.amazon' => [
            'driver' => AmazonSource::class,
            'enabled' => true,
            'channel_name' => 'Amazon',
            'lookback_days' => 30,
            'export' => [
                'enabled' => true,
                'field_mapping' => [
                    'tracking_number' => 'tracking_number',
                    'carrier' => 'carrier',
                    'shipment_reference' => 'shipment_reference',
                    'amazon_order_id' => 'amazon_order_id',
                ],
            ],
        ],
        'shipment-import.export_channel_map' => [
            'Amazon' => ['amazon'],
        ],
    ]);

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    expect($result->success)->toBeTrue();
    expect($result->destinationsAttempted)->toBe(1);
    expect($result->destinationsSucceeded)->toBe(1);
    expect($package->fresh()->exported)->toBeTrue();

    Saloon::assertSent(function (ConfirmShipment $request) {
        $body = $request->body()->all();

        return ($body['packageDetail']['trackingNumber'] ?? '') === 'TRACK123'
            && ($body['packageDetail']['carrierCode'] ?? '') === 'USPS';
    });
});

it('handles package without amazon metadata gracefully in export', function (): void {
    $channel = Channel::factory()->create(['name' => 'Amazon']);

    $shipment = Shipment::factory()->create([
        'channel_id' => $channel->id,
        'shipment_reference' => '111-0000000-0000000',
        'metadata' => null,
    ]);

    $package = Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'tracking_number' => 'TRACK456',
        'carrier' => 'USPS',
        'exported' => false,
    ]);

    config([
        'shipment-import.sources.amazon' => [
            'driver' => AmazonSource::class,
            'enabled' => true,
            'channel_name' => 'Amazon',
            'lookback_days' => 30,
            'export' => [
                'enabled' => true,
                'field_mapping' => [
                    'tracking_number' => 'tracking_number',
                    'carrier' => 'carrier',
                    'shipment_reference' => 'shipment_reference',
                    'amazon_order_id' => 'amazon_order_id',
                ],
            ],
        ],
        'shipment-import.export_channel_map' => [
            'Amazon' => ['amazon'],
        ],
    ]);

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    // Should fail gracefully (no Amazon order ID)
    expect($result->success)->toBeFalse();
    expect($result->errors)->not->toBeEmpty();
    expect($result->errors[0])->toContain('Amazon order ID');
});

it('imports multiple pages of amazon orders', function (): void {
    $channel = tap(Channel::factory()->create(['name' => 'Amazon']), fn ($c) => ChannelAlias::create(['reference' => 'Amazon', 'channel_id' => $c->id]));

    // Sequential mocks: SearchOrders(page1) → SearchOrders(page2)
    // Items are embedded in the order response — no separate fetch needed
    Saloon::fake([
        amazonOrdersResponse(
            [sampleAmazonOrder('111-1111111-1111111')],
            nextToken: 'token_page2'
        ),
        amazonOrdersResponse(
            [sampleAmazonOrder('111-2222222-2222222')],
        ),
    ]);

    $source = new AmazonSource([
        'driver' => AmazonSource::class,
        'enabled' => true,
        'channel_name' => 'Amazon',
        'shipping_method' => null,
        'lookback_days' => 30,
        'export' => ['enabled' => false, 'field_mapping' => []],
    ]);

    $result = ShipmentImportService::forSource($source)->import();

    expect($result->shipmentsCreated)->toBe(2);
    expect(Shipment::where('shipment_reference', '111-1111111-1111111')->exists())->toBeTrue();
    expect(Shipment::where('shipment_reference', '111-2222222-2222222')->exists())->toBeTrue();
});

it('validates amazon configuration requires credentials', function (): void {
    Setting::where('key', 'amazon.client_id')->delete();
    app(SettingsService::class)->clearCache();

    $source = new AmazonSource([
        'driver' => AmazonSource::class,
        'enabled' => true,
        'channel_name' => 'Amazon',
        'lookback_days' => 30,
        'export' => ['enabled' => false, 'field_mapping' => []],
    ]);

    expect(fn () => $source->validateConfiguration())
        ->toThrow(InvalidArgumentException::class, 'client ID');
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
        'driver' => AmazonSource::class,
        'enabled' => true,
        'channel_name' => 'Amazon',
        'shipping_method' => null,
        'lookback_days' => 30,
        'export' => ['enabled' => false, 'field_mapping' => []],
    ]);

    $result = ShipmentImportService::forSource($source)->import();

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
        'driver' => AmazonSource::class,
        'enabled' => true,
        'channel_name' => 'Amazon',
        'shipping_method' => null,
        'lookback_days' => 30,
        'export' => ['enabled' => false, 'field_mapping' => []],
    ]);

    $result = ShipmentImportService::forSource($source)->import();

    expect($result->shipmentsCreated)->toBe(1);

    $shipment = Shipment::where('shipment_reference', '111-2222222-3333333')->first();
    // Unit price = 40/4 = 10, qty remaining = 3, total = 30
    expect((float) $shipment->value)->toBe(30.0);

    $lineItem = $shipment->shipmentItems->first();
    expect($lineItem->quantity)->toBe(3);
    expect((float) $lineItem->value)->toBe(10.0);
});
