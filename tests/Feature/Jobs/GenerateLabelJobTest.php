<?php

use App\Contracts\CarrierAdapterInterface;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\LabelBatchItemStatus;
use App\Enums\PackageStatus;
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
use App\Models\ShippingOffer;
use App\Models\ShippingRule;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
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

it('updates batch item on successful label generation', function (): void {
    $ctx = createBatchContext();

    $mockResponse = ShipResponse::success(
        trackingNumber: 'BATCH123',
        cost: 7.50,
        carrier: 'MockCarrier',
        service: 'Test Service',
        labelData: base64_encode('fake-label'),
    );

    $mockAdapter = Mockery::mock(CarrierAdapterInterface::class);
    $mockAdapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $mockAdapter->shouldReceive('resolvePreSelectedRate')->once()->andReturnUsing(fn ($rate) => $rate);
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

    $mockAdapter = Mockery::mock(CarrierAdapterInterface::class);
    $mockAdapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $mockAdapter->shouldReceive('resolvePreSelectedRate')->once()->andReturnUsing(fn ($rate) => $rate);
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

    $mockAdapter = Mockery::mock(CarrierAdapterInterface::class);
    $mockAdapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $mockAdapter->shouldReceive('resolvePreSelectedRate')->once()->andReturnUsing(fn ($rate) => $rate);
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

    $mockAdapter = Mockery::mock(CarrierAdapterInterface::class);
    $mockAdapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $mockAdapter->shouldReceive('resolvePreSelectedRate')->once()->andReturnUsing(fn ($rate) => $rate);
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

it('handles exceptions during label generation', function (): void {
    $ctx = createBatchContext();

    $mockAdapter = Mockery::mock(CarrierAdapterInterface::class);
    $mockAdapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $mockAdapter->shouldReceive('resolvePreSelectedRate')->once()->andReturnUsing(fn ($rate) => $rate);
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
