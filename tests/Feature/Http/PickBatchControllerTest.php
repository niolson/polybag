<?php

use App\Enums\PickingStatus;
use App\Models\PickBatch;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\PickBatchService;

beforeEach(function (): void {
    $this->user = User::factory()->manager()->create();
});

it('requires authentication for the summary route', function (): void {
    $batch = PickBatch::factory()->create();

    $this->get(route('pick-batches.summary', $batch))
        ->assertRedirect('/login');
});

it('requires authentication for the pack-slips route', function (): void {
    $batch = PickBatch::factory()->create();

    $this->get(route('pick-batches.pack-slips', $batch))
        ->assertRedirect('/login');
});

it('returns the picking summary with aggregated rows', function (): void {
    $this->actingAs($this->user);

    $productA = Product::factory()->create(['sku' => 'SKU-A', 'bin_location' => 'A1']);
    $productB = Product::factory()->create(['sku' => 'SKU-B', 'bin_location' => null]);

    $shipments = Shipment::factory()->count(2)->create(['picking_status' => PickingStatus::Pending]);

    ShipmentItem::factory()->create(['shipment_id' => $shipments[0]->id, 'product_id' => $productA->id, 'quantity' => 2]);
    ShipmentItem::factory()->create(['shipment_id' => $shipments[1]->id, 'product_id' => $productA->id, 'quantity' => 1]);
    ShipmentItem::factory()->create(['shipment_id' => $shipments[1]->id, 'product_id' => $productB->id, 'quantity' => 3]);

    $batch = app(PickBatchService::class)->createFromShipments($shipments, $this->user);

    $response = $this->get(route('pick-batches.summary', $batch));

    $response->assertOk()
        ->assertViewIs('pick-batches.summary')
        ->assertViewHas('rows');

    $rows = $response->viewData('rows');
    $skus = array_column($rows, 'sku');

    expect($skus)->toContain('SKU-A')
        ->and($skus)->toContain('SKU-B');

    $rowA = collect($rows)->firstWhere('sku', 'SKU-A');
    expect($rowA['quantity'])->toBe(3);
});

it('sorts bin-location items before no-bin-location items in summary', function (): void {
    $this->actingAs($this->user);

    $located = Product::factory()->create(['sku' => 'LOC', 'bin_location' => 'B3']);
    $unlocated = Product::factory()->create(['sku' => 'UNL', 'bin_location' => null]);

    $shipment = Shipment::factory()->create(['picking_status' => PickingStatus::Pending]);
    ShipmentItem::factory()->create(['shipment_id' => $shipment->id, 'product_id' => $located->id, 'quantity' => 1]);
    ShipmentItem::factory()->create(['shipment_id' => $shipment->id, 'product_id' => $unlocated->id, 'quantity' => 1]);

    $batch = app(PickBatchService::class)->createFromShipments(collect([$shipment]), $this->user);

    $rows = $this->get(route('pick-batches.summary', $batch))->viewData('rows');

    expect($rows[0]['sku'])->toBe('LOC')
        ->and($rows[1]['sku'])->toBe('UNL');
});

it('returns the pack slips view', function (): void {
    $this->actingAs($this->user);

    $shipment = Shipment::factory()->create(['picking_status' => PickingStatus::Pending]);
    $batch = app(PickBatchService::class)->createFromShipments(collect([$shipment]), $this->user);

    $response = $this->get(route('pick-batches.pack-slips', $batch));

    $response->assertOk()
        ->assertViewIs('pick-batches.pack-slips')
        ->assertViewHas('pivotRows');
});

it('shows tote codes on pack slips', function (): void {
    $this->actingAs($this->user);

    $shipments = Shipment::factory()->count(2)->create(['picking_status' => PickingStatus::Pending]);
    $batch = app(PickBatchService::class)->createFromShipments($shipments, $this->user);

    $response = $this->get(route('pick-batches.pack-slips', $batch));
    $response->assertOk()->assertSee('T01')->assertSee('T02');
});
