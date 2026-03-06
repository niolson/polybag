<?php

use App\Enums\PackageStatus;
use App\Contracts\CarrierAdapterInterface;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\LabelBatchItemStatus;
use App\Enums\ShippingRuleAction;
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
use App\Models\ShippingRule;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;

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

it('handles exceptions during label generation', function (): void {
    $ctx = createBatchContext();

    $mockAdapter = Mockery::mock(CarrierAdapterInterface::class);
    $mockAdapter->shouldReceive('resolvePreSelectedRate')->once()->andReturnUsing(fn ($rate) => $rate);
    $mockAdapter->shouldReceive('createShipment')->once()->andThrow(new RuntimeException('Carrier API timeout'));
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $mockAdapter);

    $job = new GenerateLabelJob($ctx['item']->id, 'pdf', null);
    $job->handle();

    $ctx['item']->refresh();
    $ctx['batch']->refresh();

    expect($ctx['item']->status)->toBe(LabelBatchItemStatus::Failed)
        ->and($ctx['item']->error_message)->toBe('Carrier API timeout')
        ->and($ctx['batch']->failed_shipments)->toBe(1);

    expect(Package::find($ctx['package']->id))->toBeNull();
});

it('does nothing when batch item not found', function (): void {
    $job = new GenerateLabelJob(999999, 'pdf', null);
    $job->handle();

    // Should not throw
    expect(true)->toBeTrue();
});
