<?php

use App\Enums\PickBatchStatus;
use App\Enums\PickingStatus;
use App\Enums\ShipmentStatus;
use App\Models\Channel;
use App\Models\PickBatch;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\PickBatchService;

use function Pest\Laravel\assertDatabaseCount;

beforeEach(function (): void {
    $this->service = app(PickBatchService::class);
    $this->user = User::factory()->create();
});

// --- createFromShipments ---

it('creates a pick batch and assigns sequential tote codes', function (): void {
    $shipments = Shipment::factory()->count(3)->create();

    $batch = $this->service->createFromShipments($shipments, $this->user);

    expect($batch)->toBeInstanceOf(PickBatch::class)
        ->and($batch->total_shipments)->toBe(3)
        ->and($batch->status)->toBe(PickBatchStatus::InProgress);

    $toteCodes = $batch->pickBatchShipments()->orderBy('id')->pluck('tote_code')->all();
    expect($toteCodes)->toBe(['T01', 'T02', 'T03']);
});

it('sets linked shipments to batched status', function (): void {
    $shipments = Shipment::factory()->count(2)->create();

    $this->service->createFromShipments($shipments, $this->user);

    foreach ($shipments as $shipment) {
        expect($shipment->fresh()->picking_status)->toBe(PickingStatus::Batched);
    }
});

it('skips shipments that are not pending', function (): void {
    $pending = Shipment::factory()->create(['picking_status' => PickingStatus::Pending]);
    $batched = Shipment::factory()->create(['picking_status' => PickingStatus::Batched]);
    $picked = Shipment::factory()->create(['picking_status' => PickingStatus::Picked]);

    $batch = $this->service->createFromShipments(collect([$pending, $batched, $picked]), $this->user);

    expect($batch->total_shipments)->toBe(1)
        ->and($batch->pickBatchShipments)->toHaveCount(1);
});

it('does not create a batch when no selected shipments are eligible', function (): void {
    $batched = Shipment::factory()->create(['picking_status' => PickingStatus::Batched]);
    $shipped = Shipment::factory()->create([
        'picking_status' => PickingStatus::Pending,
        'status' => ShipmentStatus::Shipped,
    ]);

    $batch = $this->service->createFromShipments(collect([$batched, $shipped]), $this->user);

    expect($batch)->toBeNull();
    assertDatabaseCount('pick_batches', 0);
});

// --- autoGenerate ---

it('auto-generates a batch of the requested size', function (): void {
    Shipment::factory()->count(10)->create(['picking_status' => PickingStatus::Pending]);

    $batch = $this->service->autoGenerate(
        batchSize: 5,
        prioritizeExpedited: false,
        channelId: null,
        shippingMethodId: null,
        user: $this->user,
    );

    expect($batch->total_shipments)->toBe(5);
});

it('does not create a batch when auto-generation finds no eligible shipments', function (): void {
    Shipment::factory()->create([
        'picking_status' => PickingStatus::Pending,
        'status' => ShipmentStatus::Shipped,
    ]);

    $batch = $this->service->autoGenerate(
        batchSize: 5,
        prioritizeExpedited: false,
        channelId: null,
        shippingMethodId: null,
        user: $this->user,
    );

    expect($batch)->toBeNull();
    assertDatabaseCount('pick_batches', 0);
});

it('selects oldest shipments first when not prioritizing expedited', function (): void {
    $old = Shipment::factory()->create(['picking_status' => PickingStatus::Pending, 'created_at' => now()->subDays(5)]);
    $new = Shipment::factory()->create(['picking_status' => PickingStatus::Pending, 'created_at' => now()]);

    $batch = $this->service->autoGenerate(
        batchSize: 1,
        prioritizeExpedited: false,
        channelId: null,
        shippingMethodId: null,
        user: $this->user,
    );

    $included = $batch->pickBatchShipments()->pluck('shipment_id')->all();
    expect($included)->toContain($old->id)
        ->and($included)->not->toContain($new->id);
});

it('puts expedited shipments first when prioritizing expedited', function (): void {
    $standard = ShippingMethod::factory()->create(['is_expedited' => false]);
    $expedited = ShippingMethod::factory()->create(['is_expedited' => true]);

    $standardShipment = Shipment::factory()->create([
        'picking_status' => PickingStatus::Pending,
        'shipping_method_id' => $standard->id,
        'created_at' => now()->subDays(10),
    ]);
    $expeditedShipment = Shipment::factory()->create([
        'picking_status' => PickingStatus::Pending,
        'shipping_method_id' => $expedited->id,
        'created_at' => now(),
    ]);

    $batch = $this->service->autoGenerate(
        batchSize: 1,
        prioritizeExpedited: true,
        channelId: null,
        shippingMethodId: null,
        user: $this->user,
    );

    $included = $batch->pickBatchShipments()->pluck('shipment_id')->all();
    expect($included)->toContain($expeditedShipment->id)
        ->and($included)->not->toContain($standardShipment->id);
});

it('filters by channel', function (): void {
    $channelA = Channel::factory()->create();
    $channelB = Channel::factory()->create();

    Shipment::factory()->create(['picking_status' => PickingStatus::Pending, 'channel_id' => $channelA->id]);
    $targetShipment = Shipment::factory()->create(['picking_status' => PickingStatus::Pending, 'channel_id' => $channelB->id]);

    $batch = $this->service->autoGenerate(
        batchSize: 10,
        prioritizeExpedited: false,
        channelId: $channelB->id,
        shippingMethodId: null,
        user: $this->user,
    );

    $included = $batch->pickBatchShipments()->pluck('shipment_id')->all();
    expect($included)->toContain($targetShipment->id)
        ->and($included)->toHaveCount(1);
});

it('filters by shipping method', function (): void {
    $methodA = ShippingMethod::factory()->create();
    $methodB = ShippingMethod::factory()->create();

    Shipment::factory()->create(['picking_status' => PickingStatus::Pending, 'shipping_method_id' => $methodA->id]);
    $targetShipment = Shipment::factory()->create(['picking_status' => PickingStatus::Pending, 'shipping_method_id' => $methodB->id]);

    $batch = $this->service->autoGenerate(
        batchSize: 10,
        prioritizeExpedited: false,
        channelId: null,
        shippingMethodId: $methodB->id,
        user: $this->user,
    );

    $included = $batch->pickBatchShipments()->pluck('shipment_id')->all();
    expect($included)->toContain($targetShipment->id)
        ->and($included)->toHaveCount(1);
});

// --- cancel ---

it('cancels a batch and resets shipments to pending', function (): void {
    $shipments = Shipment::factory()->count(3)->create(['picking_status' => PickingStatus::Pending]);
    $batch = $this->service->createFromShipments($shipments, $this->user);

    $this->service->cancel($batch);

    expect($batch->fresh()->status)->toBe(PickBatchStatus::Cancelled);

    foreach ($shipments as $shipment) {
        expect($shipment->fresh()->picking_status)->toBe(PickingStatus::Pending);
    }
});

it('does not cancel a completed batch', function (): void {
    $batch = PickBatch::factory()->completed()->create();

    $this->service->cancel($batch);

    expect($batch->fresh()->status)->toBe(PickBatchStatus::Completed);
});

// --- complete ---

it('completes a batch and sets shipments to picked', function (): void {
    $shipments = Shipment::factory()->count(2)->create(['picking_status' => PickingStatus::Pending]);
    $batch = $this->service->createFromShipments($shipments, $this->user);

    $this->service->complete($batch);

    expect($batch->fresh()->status)->toBe(PickBatchStatus::Completed)
        ->and($batch->fresh()->completed_at)->not->toBeNull();

    foreach ($shipments as $shipment) {
        expect($shipment->fresh()->picking_status)->toBe(PickingStatus::Picked);
    }
});

it('sets row-level picked timestamps when completing a batch', function (): void {
    $shipments = Shipment::factory()->count(2)->create(['picking_status' => PickingStatus::Pending]);
    $batch = $this->service->createFromShipments($shipments, $this->user);

    $this->service->complete($batch);

    expect($batch->pickBatchShipments()->whereNull('picked_at')->exists())->toBeFalse();
});
