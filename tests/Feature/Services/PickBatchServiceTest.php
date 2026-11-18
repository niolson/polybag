<?php

use App\Enums\PickBatchStatus;
use App\Enums\PickingStatus;
use App\Enums\ShipmentStatus;
use App\Filament\Resources\ShipmentResource\Pages\EditShipment;
use App\Models\Channel;
use App\Models\Client;
use App\Models\Package;
use App\Models\PickBatch;
use App\Models\PickBatchShipment;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\PickBatchService;
use App\Services\SettingsService;
use Livewire\Livewire;

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

it('assigns the shipment client when selected shipments share one client', function (): void {
    $client = Client::factory()->create();
    $shipments = Shipment::factory()->count(2)->create([
        'client_id' => $client->id,
        'picking_status' => PickingStatus::Pending,
    ]);

    $batch = $this->service->createFromShipments($shipments, $this->user);

    expect($batch->client_id)->toBe($client->id);
});

it('rejects selected shipments from multiple clients', function (): void {
    $clientA = Client::factory()->create();
    $clientB = Client::factory()->create();

    $shipments = collect([
        Shipment::factory()->create([
            'client_id' => $clientA->id,
            'picking_status' => PickingStatus::Pending,
        ]),
        Shipment::factory()->create([
            'client_id' => $clientB->id,
            'picking_status' => PickingStatus::Pending,
        ]),
    ]);

    expect(fn () => $this->service->createFromShipments($shipments, $this->user))
        ->toThrow(DomainException::class, 'Pick batches can only include shipments for one client.');

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

it('filters generated batches by client', function (): void {
    $clientA = Client::factory()->create();
    $clientB = Client::factory()->create();

    Shipment::factory()->create([
        'client_id' => $clientA->id,
        'picking_status' => PickingStatus::Pending,
    ]);
    $targetShipment = Shipment::factory()->create([
        'client_id' => $clientB->id,
        'picking_status' => PickingStatus::Pending,
    ]);

    $batch = $this->service->autoGenerate(
        batchSize: 10,
        prioritizeExpedited: false,
        channelId: null,
        shippingMethodId: null,
        user: $this->user,
        clientId: $clientB->id,
    );

    $included = $batch->pickBatchShipments()->pluck('shipment_id')->all();
    expect($batch->client_id)->toBe($clientB->id)
        ->and($included)->toContain($targetShipment->id)
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

// --- removeFromActiveBatches ---

it('removes an unpicked shipment from an in-progress batch', function (): void {
    $shipments = Shipment::factory()->count(2)->create();
    $batch = $this->service->createFromShipments($shipments, $this->user);
    $shipped = $shipments->first();

    $this->service->removeFromActiveBatches($shipped);

    expect($batch->fresh()->total_shipments)->toBe(1)
        ->and($batch->pickBatchShipments()->where('shipment_id', $shipped->id)->exists())->toBeFalse()
        ->and($shipped->fresh()->picking_status)->toBe(PickingStatus::Pending)
        ->and($batch->fresh()->status)->toBe(PickBatchStatus::InProgress);
});

it('cancels an in-progress batch when its last shipment is removed', function (): void {
    $shipment = Shipment::factory()->create();
    $batch = $this->service->createFromShipments(collect([$shipment]), $this->user);

    $this->service->removeFromActiveBatches($shipment);

    expect($batch->fresh()->status)->toBe(PickBatchStatus::Cancelled)
        ->and($batch->fresh()->total_shipments)->toBe(0);
});

it('completes the batch when all remaining shipments are already picked', function (): void {
    $shipments = Shipment::factory()->count(2)->create();
    $batch = $this->service->createFromShipments($shipments, $this->user);

    [$picked, $unpicked] = $shipments;
    $batch->pickBatchShipments()->where('shipment_id', $picked->id)->update(['picked_at' => now()]);

    $this->service->removeFromActiveBatches($unpicked);

    expect($batch->fresh()->status)->toBe(PickBatchStatus::Completed)
        ->and($picked->fresh()->picking_status)->toBe(PickingStatus::Picked);
});

it('does not remove shipments that have already been picked', function (): void {
    $shipment = Shipment::factory()->create();
    $batch = $this->service->createFromShipments(collect([$shipment]), $this->user);
    $batch->pickBatchShipments()->update(['picked_at' => now()]);

    $this->service->removeFromActiveBatches($shipment);

    expect($batch->pickBatchShipments()->where('shipment_id', $shipment->id)->exists())->toBeTrue()
        ->and($batch->fresh()->total_shipments)->toBe(1);
});

it('ignores batches that are not in progress', function (): void {
    $shipment = Shipment::factory()->create(['picking_status' => PickingStatus::Batched]);
    $batch = PickBatch::factory()->completed()->create(['total_shipments' => 1]);
    PickBatchShipment::factory()->create([
        'pick_batch_id' => $batch->id,
        'shipment_id' => $shipment->id,
    ]);

    $this->service->removeFromActiveBatches($shipment);

    expect($batch->pickBatchShipments()->where('shipment_id', $shipment->id)->exists())->toBeTrue()
        ->and($batch->fresh()->total_shipments)->toBe(1);
});

it('removes a batched shipment from its batch when it ships', function (): void {
    app(SettingsService::class)->set('packing_validation_enabled', false);

    $shipments = Shipment::factory()->count(2)->create();
    $batch = $this->service->createFromShipments($shipments, $this->user);
    $shipped = $shipments->first();

    Package::factory()->shipped()->create(['shipment_id' => $shipped->id]);
    $shipped->refresh()->updateShippedStatus();

    expect($shipped->fresh()->status)->toBe(ShipmentStatus::Shipped)
        ->and($shipped->fresh()->picking_status)->toBe(PickingStatus::Pending)
        ->and($batch->fresh()->total_shipments)->toBe(1)
        ->and($batch->pickBatchShipments()->where('shipment_id', $shipped->id)->exists())->toBeFalse();
});

it('drops a Shipment a manager cancels from its in-progress pick batch', function (): void {
    $manager = User::factory()->manager()->create();
    $this->actingAs($manager);
    app(SettingsService::class)->set('picking_enabled', true, 'boolean');

    $product = Product::factory()->create(['name' => 'Widget']);
    $kept = Shipment::factory()->create(['picking_status' => PickingStatus::Pending]);
    $cancelled = Shipment::factory()->create(['picking_status' => PickingStatus::Pending]);
    ShipmentItem::factory()->create(['shipment_id' => $kept->id, 'product_id' => $product->id, 'quantity' => 1]);
    ShipmentItem::factory()->create(['shipment_id' => $cancelled->id, 'product_id' => $product->id, 'quantity' => 5]);

    $batch = $this->service->createFromShipments(collect([$kept, $cancelled]), $manager);

    Livewire::test(EditShipment::class, ['record' => $cancelled->id])
        ->fillForm(['status' => ShipmentStatus::Cancelled->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($cancelled->fresh()->status)->toBe(ShipmentStatus::Cancelled)
        ->and($this->service->summaryRows($batch->fresh())[0]['quantity'])->toBe(1)
        ->and($this->service->packSlipRun($batch->fresh())->shipmentIds)->toBe([$kept->id])
        ->and($batch->fresh()->total_shipments)->toBe(1);
});

it('keeps a Shipment in its batch when an edit leaves it open', function (): void {
    $shipments = Shipment::factory()->count(2)->create();
    $batch = $this->service->createFromShipments($shipments, $this->user);

    $shipments->first()->update(['first_name' => 'Ada']);

    expect($batch->fresh()->total_shipments)->toBe(2);
});
