<?php

use App\Contracts\DirectCarrierAdapter;
use App\Contracts\PackageLabelWorkflow;
use App\DataTransferObjects\Shipping\CancelResponse;
use App\Enums\PackageStatus;
use App\Http\Integrations\Shopify\Requests\GraphQL;
use App\Models\AuditLog;
use App\Models\Package;
use App\Models\PackageLabel;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use App\Services\ShipmentImport\PackageExportService;
use App\Services\ShopifyGoodsFingerprint;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

/*
 * A direct Label on a Shopify order is exported as a fulfillment, and the void
 * has to cancel it, or Shopify keeps the dead tracking number and the
 * fulfillment order stays closed to the next Label (`project-review/09`).
 */

beforeEach(function (): void {
    app(CarrierRegistry::class)->reset();

    $adapter = Mockery::mock(DirectCarrierAdapter::class);
    $adapter->shouldReceive('cancelShipment')->andReturn(CancelResponse::success('Label voided successfully.'));
    app(CarrierRegistry::class)->registerInstance('USPS', $adapter);
});

afterEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

/**
 * @return list<array<string, mixed>>
 */
function cancelOnVoidLineItems(): array
{
    return [[
        'sku' => 'SKU-700',
        'remainingQuantity' => 1,
        'requiresShipping' => true,
        'variant' => ['id' => 'gid://shopify/ProductVariant/700'],
    ]];
}

/**
 * A package shipped on our own USPS account for a Shopify order, whose export
 * created the fulfillment given.
 */
function directlyShippedShopifyPackage(?string $fulfillmentId = 'gid://shopify/Fulfillment/1'): Package
{
    $source = createShopifyDataSource(['export_enabled' => true], ['oauth_access_token' => 'shpat_test_token']);

    $shipment = Shipment::factory()->create([
        'data_source_id' => $source->id,
        'source_record_id' => 'gid://shopify/FulfillmentOrder/12345',
        'metadata' => [
            'shopify_order_id' => 'gid://shopify/Order/700',
            'shopify_fulfillment_order_id' => 'gid://shopify/FulfillmentOrder/12345',
            'shopify_location_id' => 'gid://shopify/Location/1',
            ShopifyGoodsFingerprint::METADATA_KEY => app(ShopifyGoodsFingerprint::class)
                ->forLineItems(cancelOnVoidLineItems(), 'remainingQuantity'),
        ],
    ]);

    $package = Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'carrier' => 'USPS',
        'tracking_number' => '9400111899223456789012',
        'exported' => $fulfillmentId !== null,
    ]);

    $package->labels()->update(['shopify_fulfillment_id' => $fulfillmentId]);

    return $package;
}

function fulfillmentCancelled(): MockResponse
{
    return MockResponse::make(['data' => ['fulfillmentCancel' => [
        'fulfillment' => ['id' => 'gid://shopify/Fulfillment/1', 'status' => 'CANCELLED'],
        'userErrors' => [],
    ]]]);
}

/**
 * The order's fulfillable fulfillment orders after the cancel: the goods are
 * back, on the one given.
 */
function reopenedOn(string $fulfillmentOrderId): MockResponse
{
    return MockResponse::make(['data' => ['order' => [
        'id' => 'gid://shopify/Order/700',
        'fulfillmentOrders' => [
            'pageInfo' => ['hasNextPage' => false],
            'nodes' => [[
                'id' => $fulfillmentOrderId,
                'status' => 'OPEN',
                'supportedActions' => [['action' => 'CREATE_FULFILLMENT']],
                'assignedLocation' => ['location' => ['id' => 'gid://shopify/Location/1']],
                'lineItems' => ['pageInfo' => ['hasNextPage' => false], 'nodes' => cancelOnVoidLineItems()],
            ]],
        ],
    ]]]);
}

function sentQuery(GraphQL $request): string
{
    return (string) ($request->body()->all()['query'] ?? '');
}

it('cancels the Shopify fulfillment the voided label was exported as', function (): void {
    $package = directlyShippedShopifyPackage();
    Saloon::fake([fulfillmentCancelled(), reopenedOn('gid://shopify/FulfillmentOrder/54321')]);

    $result = app(PackageLabelWorkflow::class)->voidLabel($package, User::factory()->manager()->create());

    Saloon::assertSent(fn (GraphQL $request): bool => str_contains(sentQuery($request), 'fulfillmentCancel')
        && $request->body()->all()['variables']['id'] === 'gid://shopify/Fulfillment/1');

    expect($result->success)->toBeTrue()
        ->and($result->warning)->toBeNull()
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped)
        ->and(AuditLog::query()->where('auditable_type', Shipment::class)
            ->where('metadata->shopify_fulfillment_id', 'gid://shopify/Fulfillment/1')
            ->exists())->toBeTrue();
});

it('moves the shipment onto the fulfillment order the cancel reopened the goods on', function (): void {
    $package = directlyShippedShopifyPackage();
    Saloon::fake([fulfillmentCancelled(), reopenedOn('gid://shopify/FulfillmentOrder/54321')]);

    app(PackageLabelWorkflow::class)->voidLabel($package, User::factory()->manager()->create());

    $shipment = $package->shipment->refresh();

    expect($shipment->metadata['shopify_fulfillment_order_id'])->toBe('gid://shopify/FulfillmentOrder/54321')
        ->and($shipment->source_record_id)->toBe('gid://shopify/FulfillmentOrder/54321');
});

it('still records the void, and warns, when Shopify refuses the cancel', function (): void {
    $package = directlyShippedShopifyPackage();
    Saloon::fake([MockResponse::make(['data' => ['fulfillmentCancel' => [
        'fulfillment' => null,
        'userErrors' => [['field' => ['id'], 'message' => 'Fulfillment cannot be cancelled.']],
    ]]])]);

    $result = app(PackageLabelWorkflow::class)->voidLabel($package, User::factory()->manager()->create());

    // The carrier has voided the label, so the void stands either way.
    expect($result->success)->toBeTrue()
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped)
        ->and($result->warning)->toContain('Fulfillment cannot be cancelled.')
        ->and($result->warning)->toContain('Cancel the fulfillment in the Shopify admin')
        ->and($package->shipment->refresh()->metadata['shopify_fulfillment_order_id'])
        ->toBe('gid://shopify/FulfillmentOrder/12345');
});

it('warns when Shopify does not confirm the fulfillment was cancelled', function (array $reply): void {
    $package = directlyShippedShopifyPackage();
    Saloon::fake([MockResponse::make($reply)]);

    $result = app(PackageLabelWorkflow::class)->voidLabel($package, User::factory()->manager()->create());

    expect($result->success)->toBeTrue()
        ->and($result->warning)->toContain('did not confirm the fulfillment was cancelled')
        ->and($package->shipment->refresh()->metadata['shopify_fulfillment_order_id'])
        ->toBe('gid://shopify/FulfillmentOrder/12345');
})->with([
    'no mutation payload' => [['data' => ['fulfillmentCancel' => null]]],
    'no fulfillment' => [['data' => ['fulfillmentCancel' => ['fulfillment' => null, 'userErrors' => []]]]],
    'another fulfillment' => [['data' => ['fulfillmentCancel' => [
        'fulfillment' => ['id' => 'gid://shopify/Fulfillment/999', 'status' => 'CANCELLED'],
        'userErrors' => [],
    ]]]],
    'not cancelled' => [['data' => ['fulfillmentCancel' => [
        'fulfillment' => ['id' => 'gid://shopify/Fulfillment/1', 'status' => 'SUCCESS'],
        'userErrors' => [],
    ]]]],
]);

it('warns rather than cancelling through nothing when the Shopify connection is inactive', function (): void {
    $package = directlyShippedShopifyPackage();
    $package->shipment->dataSource->update(['active' => false]);
    Saloon::fake([]);

    $result = app(PackageLabelWorkflow::class)->voidLabel($package, User::factory()->manager()->create());

    Saloon::assertNothingSent();

    expect($result->success)->toBeTrue()
        ->and($result->warning)->toContain('inactive');
});

it('asks Shopify nothing for a label that never became a fulfillment', function (): void {
    $package = directlyShippedShopifyPackage(fulfillmentId: null);
    Saloon::fake([]);

    $result = app(PackageLabelWorkflow::class)->voidLabel($package, User::factory()->manager()->create());

    Saloon::assertNothingSent();

    expect($result->success)->toBeTrue()
        ->and($result->warning)->toBeNull();
});

it('cancels the fulfillment when a void is recorded too', function (): void {
    $package = directlyShippedShopifyPackage();
    Saloon::fake([fulfillmentCancelled(), reopenedOn('gid://shopify/FulfillmentOrder/54321')]);

    $result = app(PackageLabelWorkflow::class)->recordVoid($package, User::factory()->manager()->create());

    Saloon::assertSent(fn (GraphQL $request): bool => str_contains(sentQuery($request), 'fulfillmentCancel'));

    expect($result->success)->toBeTrue()
        ->and($result->warning)->toBeNull();
});

it('exports the re-shipped label to the reopened fulfillment order, and keeps each label\'s fulfillment', function (): void {
    $package = directlyShippedShopifyPackage();
    Saloon::fake([fulfillmentCancelled(), reopenedOn('gid://shopify/FulfillmentOrder/54321')]);
    app(PackageLabelWorkflow::class)->voidLabel($package, User::factory()->manager()->create());

    $package->refresh()->update([
        'status' => PackageStatus::Shipped,
        'carrier' => 'USPS',
        'tracking_number' => '9400111899223456789999',
        'postage_source' => $package->labels()->sole()->postage_source,
        'shipped_at' => now(),
    ]);
    PackageLabel::createFromPackage($package->fresh());

    Saloon::fake([MockResponse::make(['data' => ['fulfillmentCreate' => [
        'fulfillment' => ['id' => 'gid://shopify/Fulfillment/2', 'status' => 'SUCCESS', 'trackingInfo' => []],
        'userErrors' => [],
    ]]])]);

    $result = app(PackageExportService::class)->exportPackage($package->fresh());

    Saloon::assertSent(function (GraphQL $request): bool {
        $fulfillment = $request->body()->all()['variables']['fulfillment'] ?? [];

        return ($fulfillment['lineItemsByFulfillmentOrder'][0]['fulfillmentOrderId'] ?? null) === 'gid://shopify/FulfillmentOrder/54321'
            && ($fulfillment['trackingInfo']['number'] ?? null) === '9400111899223456789999';
    });

    expect($result->success)->toBeTrue()
        ->and($package->labels()->orderBy('id')->pluck('shopify_fulfillment_id')->all())
        ->toBe(['gid://shopify/Fulfillment/1', 'gid://shopify/Fulfillment/2']);
});
