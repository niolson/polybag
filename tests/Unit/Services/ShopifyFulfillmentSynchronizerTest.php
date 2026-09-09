<?php

use App\Enums\PackageStatus;
use App\Enums\PostageSource;
use App\Enums\TrackingStatus;
use App\Models\AuditLog;
use App\Models\Package;
use App\Models\Product;
use App\Models\Shipment;
use App\Services\ShopifyFulfillmentSynchronizer;
use App\Services\ShopifyGoodsFingerprint;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

beforeEach(function (): void {
    $this->synchronizer = app(ShopifyFulfillmentSynchronizer::class);
});

it('un-ships a package whose label was voided in the Shopify admin', function (): void {
    $package = shippedShopifyPackage();

    Saloon::fake([MockResponse::make(fulfillmentState('LABEL_VOIDED'))]);

    $result = $this->synchronizer->sync();

    $package->refresh();

    expect($result)->toBe(['checked' => 1, 'voided' => 1, 'tracked' => 0, 'failed' => 0])
        ->and($package->status)->toBe(PackageStatus::Unshipped)
        ->and($package->tracking_number)->toBeNull()
        ->and($package->carrier)->toBeNull()
        ->and($package->label_data)->toBeNull();
});

it('records why the package was un-shipped', function (): void {
    $package = shippedShopifyPackage();

    Saloon::fake([MockResponse::make(fulfillmentState('LABEL_VOIDED'))]);

    $this->synchronizer->sync();

    $audit = AuditLog::where('auditable_id', $package->id)->latest('id')->first();

    expect($audit)->not->toBeNull()
        ->and($audit->metadata['reason'])->toBe('Label voided in Shopify')
        ->and($audit->metadata['tracking_number'])->toBe('9400111899223197428490');
});

it('drops the label identifiers so a re-ship buys a new label', function (): void {
    $package = shippedShopifyPackage([
        'metadata' => [
            'shopify_shipping_label_id' => 'gid://shopify/ShippingLabel/1',
            'shopify_purchase_result_id' => 'gid://shopify/ShippingLabelPurchaseResult/1',
            'shopify_label_document_url' => 'https://cdn.shopify.test/labels/1.pdf',
            'packed_by_station' => 'bench-3',
        ],
    ]);

    Saloon::fake([MockResponse::make(fulfillmentState('LABEL_VOIDED'))]);

    $this->synchronizer->sync();

    // Left behind, these would let the purchase path "recover" a voided label.
    $metadata = $package->refresh()->metadata;

    expect($metadata)->not->toHaveKey('shopify_shipping_label_id');
    expect($metadata)->not->toHaveKey('shopify_purchase_result_id');
    expect($metadata)->not->toHaveKey('shopify_label_document_url');
    expect($metadata)->toHaveKey('packed_by_station', 'bench-3');
});

it('leaves a package alone while its label is still live', function (): void {
    $package = shippedShopifyPackage();

    Saloon::fake([MockResponse::make(fulfillmentState('LABEL_PURCHASED'))]);

    $result = $this->synchronizer->sync();

    expect($result['voided'])->toBe(0)
        ->and($package->refresh()->status)->toBe(PackageStatus::Shipped);
});

it('treats a fulfillment belonging to another package as no answer at all', function (): void {
    $package = shippedShopifyPackage();

    // A partially fulfilled order carries fulfillments for other packages;
    // reading one of those as ours would un-ship a parcel that is in transit.
    Saloon::fake([MockResponse::make(fulfillmentState('LABEL_VOIDED', '9999999999999999999999'))]);

    $result = $this->synchronizer->sync();

    expect($result['voided'])->toBe(0)
        ->and($package->refresh()->status)->toBe(PackageStatus::Shipped);
});

it('counts a failed check without touching the package', function (): void {
    $package = shippedShopifyPackage();

    Saloon::fake([MockResponse::make(['errors' => [['message' => 'Throttled']]])]);

    $result = $this->synchronizer->sync();

    expect($result)->toBe(['checked' => 1, 'voided' => 0, 'tracked' => 0, 'failed' => 1])
        ->and($package->refresh()->status)->toBe(PackageStatus::Shipped);
});

it('records tracking from the same poll that checks for a void', function (): void {
    $package = shippedShopifyPackage();

    Saloon::fake([MockResponse::make(fulfillmentState('IN_TRANSIT', fulfillment: [
        'estimatedDeliveryAt' => '2026-09-05T17:00:00Z',
        'events' => ['nodes' => [[
            'id' => 'gid://shopify/FulfillmentEvent/1',
            'status' => 'IN_TRANSIT',
            'happenedAt' => '2026-09-03T14:02:00Z',
            'message' => 'Arrived at USPS regional facility',
            'city' => 'Des Moines',
            'province' => 'IA',
            'zip' => '50313',
            'country' => 'US',
        ]]],
    ]))]);

    $result = $this->synchronizer->sync();

    $package->refresh();

    expect($result)->toBe(['checked' => 1, 'voided' => 0, 'tracked' => 1, 'failed' => 0])
        ->and($package->status)->toBe(PackageStatus::Shipped)
        ->and($package->tracking_status)->toBe(TrackingStatus::InTransit)
        ->and($package->tracking_checked_at)->not->toBeNull()
        ->and($package->tracking_details['status_label'])->toBe('In transit')
        ->and($package->tracking_details['events'][0]['description'])->toBe('Arrived at USPS regional facility')
        ->and($package->tracking_details['events'][0]['location'])->toBe('Des Moines, IA, 50313, US');

    // One poll, not two: the void check and the tracking read share the request.
    Saloon::assertSentCount(1);
});

it('drops a delivered package out of the poll it was keeping alive', function (): void {
    $package = shippedShopifyPackage();

    Saloon::fake([MockResponse::make(fulfillmentState('DELIVERED', fulfillment: [
        'deliveredAt' => '2026-09-04T16:31:00Z',
    ]))]);

    $this->synchronizer->sync();

    $package->refresh();

    expect($package->tracking_status)->toBe(TrackingStatus::Delivered)
        ->and($package->delivered_at->toIso8601String())->toBe('2026-09-04T16:31:00+00:00')
        ->and($this->synchronizer->candidates())->toBeEmpty();
});

it('stops polling a package Shopify reported delivered without a timestamp', function (): void {
    // `deliveredAt` is nullable on a DELIVERED fulfillment, so the timestamp
    // alone cannot end the poll — the status has to.
    $package = shippedShopifyPackage();

    Saloon::fake([MockResponse::make(fulfillmentState('DELIVERED'))]);

    $this->synchronizer->sync();

    $package->refresh();

    expect($package->tracking_status)->toBe(TrackingStatus::Delivered)
        ->and($package->delivered_at)->toBeNull()
        ->and($this->synchronizer->candidates())->toBeEmpty();
});

it('records no status from a fulfillment belonging to another package', function (): void {
    $package = shippedShopifyPackage();

    Saloon::fake([MockResponse::make(fulfillmentState('DELIVERED', '9999999999999999999999'))]);

    $result = $this->synchronizer->sync();

    $package->refresh();

    expect($result)->toBe(['checked' => 1, 'voided' => 0, 'tracked' => 0, 'failed' => 0])
        ->and($package->tracking_status)->toBeNull()
        ->and($package->delivered_at)->toBeNull();
});

it('never checks packages that were not shipped through Shopify', function (): void {
    Package::factory()->create([
        'shipment_id' => Shipment::factory()->create(),
        'carrier' => 'USPS',
        'tracking_number' => '9400111899223197428490',
        'status' => PackageStatus::Shipped,
    ]);

    expect($this->synchronizer->candidates())->toBeEmpty();
});

it('leaves a delivered package alone, since its label was already used', function (): void {
    shippedShopifyPackage(['delivered_at' => now()]);

    expect($this->synchronizer->candidates())->toBeEmpty();
});

it('stops asking about labels too old to be voided', function (): void {
    // These packages never report as delivered — Shopify Shipping sends no
    // tracking updates back — so without the ship-date bound every label ever
    // bought would stay in the poll for good.
    shippedShopifyPackage(['shipped_at' => now()->subDays(31)]);

    expect($this->synchronizer->candidates())->toBeEmpty();

    shippedShopifyPackage(['shipped_at' => now()->subDays(29)]);

    expect($this->synchronizer->candidates())->toHaveCount(1);
});

it('honours a configured void-check window', function (): void {
    config(['services.shopify.label_void_check_days' => 2]);
    shippedShopifyPackage(['shipped_at' => now()->subDays(3)]);

    expect($this->synchronizer->candidates())->toBeEmpty();
});

/**
 * The goods every fixture here is for, in Shopify's own line-item shape.
 *
 * @return array<int, array<string, mixed>>
 */
function shopifyLineItems(string $sku = 'SKU-700', int $quantity = 1): array
{
    return [[
        'sku' => $sku,
        'remainingQuantity' => $quantity,
        'requiresShipping' => true,
        'variant' => ['id' => 'gid://shopify/ProductVariant/700'],
    ]];
}

function shopifyGoodsFingerprint(string $sku = 'SKU-700', int $quantity = 1): string
{
    return app(ShopifyGoodsFingerprint::class)
        ->forLineItems(shopifyLineItems($sku, $quantity), 'remainingQuantity');
}

/**
 * A shipped package whose shipment carries everything a re-resolution needs:
 * the order the fulfillment order belongs to, the location it is assigned to,
 * and the goods it is for. `shippedShopifyPackage()` deliberately carries none
 * of them, so the void path there asks Shopify nothing.
 */
function shopifyPackageWithOrderMetadata(array $metadata = []): Package
{
    $source = createShopifyDataSource([], ['oauth_access_token' => 'shpat_test_token']);

    $shipment = Shipment::factory()->create([
        'data_source_id' => $source->id,
        'source_record_id' => 'gid://shopify/FulfillmentOrder/12345',
        'metadata' => array_merge([
            'shopify_order_id' => 'gid://shopify/Order/700',
            'shopify_fulfillment_order_id' => 'gid://shopify/FulfillmentOrder/12345',
            'shopify_location_id' => 'gid://shopify/Location/1',
            ShopifyGoodsFingerprint::METADATA_KEY => shopifyGoodsFingerprint(),
        ], $metadata),
    ]);

    return Package::factory()->create([
        'shipment_id' => $shipment->id,
        'carrier' => 'USPS',
        'service' => 'Ground Advantage',
        'postage_source' => PostageSource::PostageDataSource,
        'postage_data_source_id' => $source->id,
        'tracking_number' => '9400111899223197428490',
        'status' => PackageStatus::Shipped,
        'shipped_at' => now(),
        'label_data' => base64_encode('LABEL-BYTES'),
        'metadata' => ['shopify_shipping_label_id' => 'gid://shopify/ShippingLabel/1'],
    ]);
}

/**
 * The order's fulfillment orders, as Shopify answers after a void: the one the
 * label was bought against is gone from an `includeClosed: false` list, and
 * whatever replaced it is here in its place.
 *
 * @param  array<int, array<string, mixed>>  $orders  each {id, location?, fulfillable?, items?}
 */
function orderFulfillmentOrders(array $orders, bool $hasNextPage = false): array
{
    return [
        'data' => [
            'order' => [
                'id' => 'gid://shopify/Order/700',
                'fulfillmentOrders' => [
                    'pageInfo' => ['hasNextPage' => $hasNextPage],
                    'nodes' => array_map(fn (array $order): array => [
                        'id' => $order['id'],
                        'status' => 'OPEN',
                        'supportedActions' => ($order['fulfillable'] ?? true)
                            ? [['action' => 'CREATE_FULFILLMENT'], ['action' => 'HOLD']]
                            : [],
                        'assignedLocation' => [
                            'location' => ['id' => $order['location'] ?? 'gid://shopify/Location/1'],
                        ],
                        'lineItems' => [
                            'pageInfo' => ['hasNextPage' => $order['truncated'] ?? false],
                            'nodes' => $order['items'] ?? shopifyLineItems(),
                        ],
                    ], $orders),
                ],
            ],
        ],
    ];
}

it('re-points the shipment at the fulfillment order that replaced the voided one', function (): void {
    $package = shopifyPackageWithOrderMetadata();

    Saloon::fake([
        MockResponse::make(fulfillmentState('LABEL_VOIDED')),
        MockResponse::make(orderFulfillmentOrders([
            ['id' => 'gid://shopify/FulfillmentOrder/54321'],
        ])),
    ]);

    $result = $this->synchronizer->sync();

    $shipment = $package->refresh()->shipment->refresh();

    expect($result['voided'])->toBe(1)
        ->and($shipment->metadata['shopify_fulfillment_order_id'])->toBe('gid://shopify/FulfillmentOrder/54321')
        // The import key moves too, or the next run reads the replacement as a
        // fulfillment order it has never seen and imports the order twice.
        ->and($shipment->source_record_id)->toBe('gid://shopify/FulfillmentOrder/54321');
});

it('records what the fulfillment order was re-pointed from', function (): void {
    $package = shopifyPackageWithOrderMetadata();

    Saloon::fake([
        MockResponse::make(fulfillmentState('LABEL_VOIDED')),
        MockResponse::make(orderFulfillmentOrders([
            ['id' => 'gid://shopify/FulfillmentOrder/54321'],
        ])),
    ]);

    $this->synchronizer->sync();

    $audit = AuditLog::where('auditable_type', Shipment::class)
        ->where('auditable_id', $package->shipment_id)
        ->latest('id')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->metadata['superseded_fulfillment_order_id'])->toBe('gid://shopify/FulfillmentOrder/12345')
        ->and($audit->metadata['fulfillment_order_id'])->toBe('gid://shopify/FulfillmentOrder/54321');
});

it('clears the stored fulfillment order when the order can no longer be shipped through Shopify', function (): void {
    $package = shopifyPackageWithOrderMetadata();

    // Nothing fulfillable left: a closed fulfillment order that no replacement
    // followed, and one held for a third-party fulfillment service.
    Saloon::fake([
        MockResponse::make(fulfillmentState('LABEL_VOIDED')),
        MockResponse::make(orderFulfillmentOrders([
            ['id' => 'gid://shopify/FulfillmentOrder/54321', 'fulfillable' => false],
        ])),
    ]);

    $this->synchronizer->sync();

    // Left in place it would keep `canPurchaseFor()` true and fail the purchase
    // with FULFILLMENT_ORDER_INVALID after the box is taped shut.
    expect($package->refresh()->shipment->refresh()->metadata)
        ->not->toHaveKey('shopify_fulfillment_order_id');
});

it('leaves the stored fulfillment order alone when several could be the replacement', function (): void {
    $package = shopifyPackageWithOrderMetadata();

    Saloon::fake([
        MockResponse::make(fulfillmentState('LABEL_VOIDED')),
        MockResponse::make(orderFulfillmentOrders([
            ['id' => 'gid://shopify/FulfillmentOrder/54321'],
            ['id' => 'gid://shopify/FulfillmentOrder/54322'],
        ])),
    ]);

    $this->synchronizer->sync();

    $shipment = $package->refresh()->shipment->refresh();

    expect($shipment->metadata['shopify_fulfillment_order_id'])->toBe('gid://shopify/FulfillmentOrder/12345')
        ->and($shipment->source_record_id)->toBe('gid://shopify/FulfillmentOrder/12345');
});

it('ignores a replacement assigned to another location', function (): void {
    $package = shopifyPackageWithOrderMetadata();

    Saloon::fake([
        MockResponse::make(fulfillmentState('LABEL_VOIDED')),
        MockResponse::make(orderFulfillmentOrders([
            ['id' => 'gid://shopify/FulfillmentOrder/54321', 'location' => 'gid://shopify/Location/2'],
        ])),
    ]);

    $this->synchronizer->sync();

    expect($package->refresh()->shipment->refresh()->metadata)
        ->not->toHaveKey('shopify_fulfillment_order_id');
});

it('still un-ships the package when the replacement cannot be resolved', function (): void {
    $package = shopifyPackageWithOrderMetadata();

    Saloon::fake([
        MockResponse::make(fulfillmentState('LABEL_VOIDED')),
        MockResponse::make(['errors' => [['message' => 'Throttled']]]),
    ]);

    $result = $this->synchronizer->sync();

    expect($result)->toBe(['checked' => 1, 'voided' => 1, 'tracked' => 0, 'failed' => 0])
        ->and($package->refresh()->status)->toBe(PackageStatus::Unshipped)
        ->and($package->shipment->refresh()->metadata['shopify_fulfillment_order_id'])
        ->toBe('gid://shopify/FulfillmentOrder/12345');
});

it('never re-points at a fulfillment order that is for different goods', function (): void {
    $package = shopifyPackageWithOrderMetadata();

    // Fulfillable, at the right location, and not this shipment's work: an
    // order split at one location leaves siblings that pass both other tests.
    Saloon::fake([
        MockResponse::make(fulfillmentState('LABEL_VOIDED')),
        MockResponse::make(orderFulfillmentOrders([
            ['id' => 'gid://shopify/FulfillmentOrder/54321', 'items' => shopifyLineItems('SKU-OTHER')],
        ])),
    ]);

    $this->synchronizer->sync();

    // Cleared rather than moved onto the sibling — buying against that one
    // would ship another parcel's goods under this shipment's label.
    expect($package->refresh()->shipment->refresh()->metadata)
        ->not->toHaveKey('shopify_fulfillment_order_id');
});

it('never re-points at a fulfillment order another shipment already holds', function (): void {
    $package = shopifyPackageWithOrderMetadata();

    Shipment::factory()->create([
        'data_source_id' => $package->shipment->data_source_id,
        'source_record_id' => 'gid://shopify/FulfillmentOrder/54321',
        'metadata' => [
            'shopify_order_id' => 'gid://shopify/Order/700',
            'shopify_fulfillment_order_id' => 'gid://shopify/FulfillmentOrder/54321',
        ],
    ]);

    // Same goods this time, so only the other shipment's claim disqualifies it.
    Saloon::fake([
        MockResponse::make(fulfillmentState('LABEL_VOIDED')),
        MockResponse::make(orderFulfillmentOrders([
            ['id' => 'gid://shopify/FulfillmentOrder/54321'],
        ])),
    ]);

    $this->synchronizer->sync();

    expect($package->refresh()->shipment->refresh()->metadata)
        ->not->toHaveKey('shopify_fulfillment_order_id');
});

it('re-points on the shipment items when the fingerprint predates them', function (): void {
    // A shipment imported before the fingerprint existed still has evidence,
    // as long as its items were imported.
    $package = shopifyPackageWithOrderMetadata([ShopifyGoodsFingerprint::METADATA_KEY => null]);
    $shipment = $package->shipment;
    $shipment->shipmentItems()->create([
        'product_id' => Product::factory()->create(['sku' => 'SKU-700'])->id,
        'quantity' => 1,
    ]);

    Saloon::fake([
        MockResponse::make(fulfillmentState('LABEL_VOIDED')),
        MockResponse::make(orderFulfillmentOrders([
            ['id' => 'gid://shopify/FulfillmentOrder/54321'],
        ])),
    ]);

    $this->synchronizer->sync();

    expect($shipment->refresh()->metadata['shopify_fulfillment_order_id'])
        ->toBe('gid://shopify/FulfillmentOrder/54321');
});

it('clears rather than guesses when the shipment records no goods at all', function (): void {
    $package = shopifyPackageWithOrderMetadata([ShopifyGoodsFingerprint::METADATA_KEY => null]);

    Saloon::fake([
        MockResponse::make(fulfillmentState('LABEL_VOIDED')),
        MockResponse::make(orderFulfillmentOrders([
            ['id' => 'gid://shopify/FulfillmentOrder/54321'],
        ])),
    ]);

    $this->synchronizer->sync();

    expect($package->refresh()->shipment->refresh()->metadata)
        ->not->toHaveKey('shopify_fulfillment_order_id');
});

it('cannot read the goods of a fulfillment order whose line items did not all fit', function (): void {
    $package = shopifyPackageWithOrderMetadata();

    Saloon::fake([
        MockResponse::make(fulfillmentState('LABEL_VOIDED')),
        MockResponse::make(orderFulfillmentOrders([
            ['id' => 'gid://shopify/FulfillmentOrder/54321', 'truncated' => true],
        ])),
    ]);

    $this->synchronizer->sync();

    expect($package->refresh()->shipment->refresh()->metadata)
        ->not->toHaveKey('shopify_fulfillment_order_id');
});

it('gives no answer when the order holds more fulfillment orders than the query asked for', function (): void {
    $package = shopifyPackageWithOrderMetadata();

    // The one on this page matches, but the replacement — or a second matching
    // candidate that would make this ambiguous — may be on the next.
    Saloon::fake([
        MockResponse::make(fulfillmentState('LABEL_VOIDED')),
        MockResponse::make(orderFulfillmentOrders([
            ['id' => 'gid://shopify/FulfillmentOrder/54321'],
        ], hasNextPage: true)),
    ]);

    $result = $this->synchronizer->sync();

    // Left exactly as it was, cleared no more than re-pointed: the import
    // paginates and resolves it on the next run.
    expect($result['voided'])->toBe(1)
        ->and($package->refresh()->shipment->refresh()->metadata['shopify_fulfillment_order_id'])
        ->toBe('gid://shopify/FulfillmentOrder/12345');
});
