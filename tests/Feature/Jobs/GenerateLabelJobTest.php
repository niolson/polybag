<?php

use App\Contracts\BlindPurchaseSource;
use App\Contracts\DirectCarrierAdapter;
use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageShipping\PackageAutoShippingRequest;
use App\DataTransferObjects\Shipping\BlindPurchaseOffer;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\CustomsDocumentDelivery;
use App\Enums\LabelBatchItemStatus;
use App\Enums\PackageStatus;
use App\Enums\ServiceCapability;
use App\Enums\ShippingRuleAction;
use App\Exceptions\Carriers\UnreadablePurchaseResponseException;
use App\Jobs\GenerateLabelJob;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\LabelBatch;
use App\Models\LabelBatchItem;
use App\Models\Package;
use App\Models\PackageItem;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShippingMethod;
use App\Models\ShippingMethodPostageSource;
use App\Models\ShippingOffer;
use App\Models\ShippingRule;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use Mockery\MockInterface;
use Saloon\Exceptions\Request\Statuses\RequestTimeOutException;
use Saloon\Http\Response;

beforeEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

afterEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

function createBatchContext(): array
{
    $user = User::factory()->admin()->create();

    $carrier = Carrier::factory()->create(['name' => 'MockCarrier', 'active' => true]);
    $service = CarrierService::factory()->create([
        'carrier_id' => $carrier->id,
        'name' => 'Test Service',
        'service_code' => 'TEST',
        'active' => true,
    ]);
    $method = ShippingMethod::factory()->create();
    $method->carrierServices()->attach($service->id);

    ShippingRule::factory()->create([
        'shipping_method_id' => $method->id,
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $service->id,
    ]);

    $shipment = Shipment::factory()->create(['shipping_method_id' => $method->id]);
    $shipmentItem = ShipmentItem::factory()->create(['shipment_id' => $shipment->id]);

    $package = Package::factory()->for($shipment)->create();
    PackageItem::factory()->create([
        'package_id' => $package->id,
        'shipment_item_id' => $shipmentItem->id,
        'product_id' => $shipmentItem->product_id,
        'quantity' => $shipmentItem->quantity,
    ]);

    $batch = LabelBatch::factory()->processing()->create([
        'user_id' => $user->id,
        'total_shipments' => 1,
    ]);

    $item = LabelBatchItem::factory()->create([
        'label_batch_id' => $batch->id,
        'shipment_id' => $shipment->id,
        'package_id' => $package->id,
    ]);

    return compact('user', 'batch', 'item', 'package', 'shipment');
}

/**
 * A direct adapter quoting the batch context's service, the rate its *Use*
 * rule selects among (`project-review/18`).
 */
function batchQuotingAdapter(): MockInterface
{
    $adapter = Mockery::mock(DirectCarrierAdapter::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('isConfigured')->andReturnTrue();
    $adapter->shouldReceive('prepareRateRequest')->andReturnNull();
    $adapter->shouldReceive('getRates')->andReturn(collect([
        new RateResponse('MockCarrier', 'TEST', 'Test Service', 7.50, carrierServiceId: CarrierService::where('service_code', 'TEST')->value('id')),
    ]));

    return $adapter;
}

it('updates batch item on successful label generation', function (): void {
    $ctx = createBatchContext();

    $mockResponse = ShipResponse::success(
        trackingNumber: 'BATCH123',
        cost: 7.50,
        carrier: 'MockCarrier',
        service: 'Test Service',
        labelData: base64_encode('fake-label'),
    );

    $mockAdapter = batchQuotingAdapter();
    $mockAdapter->shouldReceive('createShipment')->once()->andReturn($mockResponse);
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $mockAdapter);

    $job = new GenerateLabelJob($ctx['item']->id, 'pdf', null);
    $job->handle();

    $ctx['item']->refresh();
    $ctx['batch']->refresh();
    $ctx['package']->refresh();

    expect($ctx['item']->status)->toBe(LabelBatchItemStatus::Success)
        ->and($ctx['item']->tracking_number)->toBe('BATCH123')
        ->and($ctx['item']->carrier)->toBe('MockCarrier')
        ->and($ctx['item']->service)->toBe('Test Service')
        ->and((float) $ctx['item']->cost)->toBe(7.50)
        ->and($ctx['package']->status)->toBe(PackageStatus::Shipped)
        ->and($ctx['batch']->successful_shipments)->toBe(1)
        ->and((float) $ctx['batch']->total_cost)->toBe(7.50);
});

it('handles label generation failure', function (): void {
    $ctx = createBatchContext();

    $mockAdapter = batchQuotingAdapter();
    $mockAdapter->shouldReceive('createShipment')->once()->andReturn(
        ShipResponse::failure('Address validation failed')
    );
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $mockAdapter);

    $job = new GenerateLabelJob($ctx['item']->id, 'pdf', null);
    $job->handle();

    $ctx['item']->refresh();
    $ctx['batch']->refresh();

    expect($ctx['item']->status)->toBe(LabelBatchItemStatus::Failed)
        ->and($ctx['item']->error_message)->toBe('Address validation failed')
        ->and($ctx['item']->package_id)->toBeNull()
        ->and($ctx['batch']->failed_shipments)->toBe(1);

    // Package should be cleaned up
    expect(Package::find($ctx['package']->id))->toBeNull();
});

it('keeps the package and its unresolved offer when the purchase goes unanswered', function (): void {
    // project-review/01: the job deleted every unshipped package on failure,
    // and the cascade took the offer recording that a label may exist — so
    // the shipment was eligible for the next batch, which bought again.
    $ctx = createBatchContext();

    $mockAdapter = batchQuotingAdapter();
    $mockAdapter->shouldReceive('createShipment')->once()
        ->andThrow(new RequestTimeOutException(Mockery::mock(Response::class), 'timed out'));
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $mockAdapter);

    (new GenerateLabelJob($ctx['item']->id, 'pdf', null))->handle();

    $ctx['item']->refresh();

    expect($ctx['item']->status)->toBe(LabelBatchItemStatus::Failed)
        ->and($ctx['item']->error_message)->toContain('A label may already exist')
        ->and($ctx['item']->package_id)->toBe($ctx['package']->id)
        ->and(Package::find($ctx['package']->id))->not->toBeNull()
        ->and(ShippingOffer::whereNotNull('consumed_at')->sole()->isAwaitingPurchaseConfirmation())->toBeTrue();
});

it('keeps the package and its unresolved offer when the carrier accepted but its reply could not be read', function (): void {
    // project-review/11: a 2xx the adapter cannot read is a label that exists
    // and is paid for, so the batch must not clean the package up either.
    $ctx = createBatchContext();

    $mockAdapter = batchQuotingAdapter();
    $mockAdapter->shouldReceive('createShipment')->once()
        ->andThrow(new UnreadablePurchaseResponseException('MockCarrier', 'response missing label data'));
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $mockAdapter);

    (new GenerateLabelJob($ctx['item']->id, 'pdf', null))->handle();

    $ctx['item']->refresh();

    expect($ctx['item']->status)->toBe(LabelBatchItemStatus::Failed)
        ->and($ctx['item']->package_id)->toBe($ctx['package']->id)
        ->and(Package::find($ctx['package']->id))->not->toBeNull()
        ->and(ShippingOffer::whereNotNull('consumed_at')->sole()->isAwaitingPurchaseConfirmation())->toBeTrue();
});

/**
 * An unshipped package whose Shopify method is the sole choice, with a Shopify
 * blind source whose purchase writes `$markers` onto the package, as
 * ShopifyShippingLabelService does, and then fails.
 *
 * @param  array<string, string>  $markers
 * @return array{package: Package, item: LabelBatchItem}
 */
function blindPurchaseLeavingMarkers(array $markers, string $error): array
{
    $source = createShopifyDataSource([], ['oauth_access_token' => 'shpat_test_token']);
    $method = ShippingMethod::factory()->create();
    ShippingMethodPostageSource::factory()->shopify()->any()->for($method)->create();
    $shipment = Shipment::factory()->for($method)->create([
        'data_source_id' => $source->id,
        'metadata' => ['shopify_fulfillment_order_id' => 'gid://shopify/FulfillmentOrder/12345'],
    ]);
    $package = Package::factory()->for($shipment)->create(['status' => PackageStatus::Unshipped]);
    allowBlindPurchase($package);

    $shopify = Mockery::mock(BlindPurchaseSource::class);
    $shopify->shouldReceive('getCarrierName')->andReturn('Shopify');
    $shopify->shouldReceive('isConfigured')->andReturnTrue();
    $shopify->shouldReceive('offerCapability')->andReturn(ServiceCapability::Unguaranteed);
    $shopify->shouldReceive('offerDeclaredValueCap')->andReturnNull();
    $shopify->shouldReceive('customsDocumentDelivery')->andReturn(CustomsDocumentDelivery::Separate);
    $shopify->shouldReceive('blindPurchaseOffers')->andReturn(collect([new BlindPurchaseOffer(
        source: 'Shopify', sourceLabel: 'Shopify Shipping', serviceCode: 'auto', selectionLabel: "Shopify's choice",
    )]));
    $shopify->shouldReceive('createShipment')->once()->andReturnUsing(function (ShipRequest $request) use ($markers, $error): ShipResponse {
        // The adapter writes through its own copy of the package.
        $stored = Package::find($request->packageId);
        $stored->update(['metadata' => [...($stored->metadata ?? []), ...$markers]]);

        return ShipResponse::failure($error);
    });
    app(CarrierRegistry::class)->registerInstance('Shopify', $shopify);

    $batch = LabelBatch::factory()->create(['user_id' => User::factory()->admin()->create()->id]);
    $item = LabelBatchItem::factory()->create([
        'label_batch_id' => $batch->id,
        'shipment_id' => $shipment->id,
        'package_id' => $package->id,
        'status' => LabelBatchItemStatus::Pending,
    ]);

    return ['package' => $package, 'item' => $item];
}

it('keeps a package whose Shopify purchase may still be running or may have sold a label', function (array $markers): void {
    // release-polish/02: a blind purchase has no offer, so the markers on the
    // package are its only record. Deleting it left a paid label PolyBag never
    // recorded, and the shipment eligible to be bought again.
    $ctx = blindPurchaseLeavingMarkers($markers, 'Shopify stopped reporting on the label purchase before it finished.');

    (new GenerateLabelJob($ctx['item']->id, 'pdf', null))->handle();

    $ctx['item']->refresh();

    expect($ctx['item']->status)->toBe(LabelBatchItemStatus::Failed)
        ->and($ctx['item']->error_message)->toContain('Shopify stopped reporting')
        ->and($ctx['item']->error_message)->toContain('A label may already exist')
        ->and($ctx['item']->package_id)->toBe($ctx['package']->id)
        ->and(Package::find($ctx['package']->id)?->metadata)->toMatchArray($markers);
})->with([
    'purchase in flight' => [['shopify_purchase_result_id' => 'gid://shopify/ShippingLabelPurchaseResult/1']],
    'label sold' => [['shopify_shipping_label_id' => 'gid://shopify/ShippingLabel/1']],
]);

it('still cleans up a package whose Shopify purchase was declined outright', function (): void {
    $ctx = blindPurchaseLeavingMarkers([], 'Shopify could not buy the label and gave no reason.');

    (new GenerateLabelJob($ctx['item']->id, 'pdf', null))->handle();

    expect($ctx['item']->fresh()->package_id)->toBeNull()
        ->and(Package::find($ctx['package']->id))->toBeNull();
});

it('keeps a package holding a Shopify purchase when auto-ship cleans up after itself', function (): void {
    $ctx = blindPurchaseLeavingMarkers(
        ['shopify_purchase_result_id' => 'gid://shopify/ShippingLabelPurchaseResult/1'],
        'Shopify is still buying the label.',
    );

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $ctx['package'],
        new PackageAutoShippingRequest(userId: $ctx['item']->labelBatch->user_id),
    );

    expect($result->success)->toBeFalse()
        ->and(Package::find($ctx['package']->id))->not->toBeNull();
});

it('handles exceptions during label generation', function (): void {
    $ctx = createBatchContext();

    $mockAdapter = batchQuotingAdapter();
    $mockAdapter->shouldReceive('createShipment')->once()->andThrow(new RuntimeException('Carrier API timeout'));
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $mockAdapter);

    $job = new GenerateLabelJob($ctx['item']->id, 'pdf', null);
    $job->handle();

    $ctx['item']->refresh();
    $ctx['batch']->refresh();

    // The exception came after the offer was claimed, so nobody knows whether
    // a label was bought: the package is kept rather than cleaned up
    // (project-review/01).
    expect($ctx['item']->status)->toBe(LabelBatchItemStatus::Failed)
        ->and($ctx['item']->error_message)->toStartWith('Carrier API timeout')
        ->and($ctx['batch']->failed_shipments)->toBe(1)
        ->and(Package::find($ctx['package']->id))->not->toBeNull();
});

it('does nothing when batch item not found', function (): void {
    $job = new GenerateLabelJob(999999, 'pdf', null);
    $job->handle();

    // Should not throw
    expect(true)->toBeTrue();
});
