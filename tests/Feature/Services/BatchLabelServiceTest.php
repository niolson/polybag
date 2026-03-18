<?php

use App\Enums\LabelBatchItemStatus;
use App\Enums\LabelBatchStatus;
use App\Enums\PackageStatus;
use App\Models\BoxSize;
use App\Models\LabelBatch;
use App\Models\LabelBatchItem;
use App\Models\Package;
use App\Models\PackageItem;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\BatchLabelService;
use Illuminate\Support\Facades\Bus;

beforeEach(function (): void {
    $this->service = new BatchLabelService;
});

// --- validateShipmentsForBatch ---

it('marks already-shipped shipments as ineligible', function (): void {
    $shipment = Shipment::factory()->shipped()->create();
    ShipmentItem::factory()->create(['shipment_id' => $shipment->id]);

    $result = $this->service->validateShipmentsForBatch(collect([$shipment]));

    expect($result->eligible)->toBeEmpty()
        ->and($result->ineligible)->toHaveCount(1)
        ->and($result->ineligible->first()['reason'])->toBe('Already shipped');
});

it('marks shipments without shipping method as ineligible', function (): void {
    $shipment = Shipment::factory()->withoutShippingMethod()->create();
    ShipmentItem::factory()->create(['shipment_id' => $shipment->id]);

    $result = $this->service->validateShipmentsForBatch(collect([$shipment]));

    expect($result->ineligible)->toHaveCount(1)
        ->and($result->ineligible->first()['reason'])->toBe('No shipping method assigned');
});

it('marks shipments with missing address fields as ineligible', function (): void {
    $shipment = Shipment::factory()->create(['address1' => null]);
    ShipmentItem::factory()->create(['shipment_id' => $shipment->id]);

    $result = $this->service->validateShipmentsForBatch(collect([$shipment]));

    expect($result->ineligible)->toHaveCount(1)
        ->and($result->ineligible->first()['reason'])->toBe('Missing address fields');
});

it('marks shipments with existing unshipped packages as ineligible', function (): void {
    $shipment = Shipment::factory()->create();
    ShipmentItem::factory()->create(['shipment_id' => $shipment->id]);
    Package::factory()->for($shipment)->create(['status' => PackageStatus::Unshipped]);

    $result = $this->service->validateShipmentsForBatch(collect([$shipment]));

    expect($result->ineligible)->toHaveCount(1)
        ->and($result->ineligible->first()['reason'])->toBe('Has existing unshipped packages');
});

it('marks shipments with zero-weight products as ineligible', function (): void {
    $product = Product::factory()->create(['weight' => 0]);
    $shipment = Shipment::factory()->create();
    ShipmentItem::factory()->create(['shipment_id' => $shipment->id, 'product_id' => $product->id]);

    $result = $this->service->validateShipmentsForBatch(collect([$shipment]));

    expect($result->ineligible)->toHaveCount(1)
        ->and($result->ineligible->first()['reason'])->toContain('Item missing product weight');
});

it('marks shipments with transparency-required items as ineligible', function (): void {
    $shipment = Shipment::factory()->create();
    ShipmentItem::factory()->withTransparency()->create(['shipment_id' => $shipment->id]);

    $result = $this->service->validateShipmentsForBatch(collect([$shipment]));

    expect($result->ineligible)->toHaveCount(1)
        ->and($result->ineligible->first()['reason'])->toBe('Contains transparency-required items');
});

it('correctly separates eligible and ineligible shipments', function (): void {
    $eligible = Shipment::factory()->create();
    ShipmentItem::factory()->create(['shipment_id' => $eligible->id]);

    $ineligible = Shipment::factory()->shipped()->create();
    ShipmentItem::factory()->create(['shipment_id' => $ineligible->id]);

    $result = $this->service->validateShipmentsForBatch(collect([$eligible, $ineligible]));

    expect($result->eligible)->toHaveCount(1)
        ->and($result->ineligible)->toHaveCount(1)
        ->and($result->hasIneligible())->toBeTrue()
        ->and($result->allIneligible())->toBeFalse();
});

it('marks all eligible when all shipments are valid', function (): void {
    $shipments = collect();
    for ($i = 0; $i < 3; $i++) {
        $s = Shipment::factory()->create();
        ShipmentItem::factory()->create(['shipment_id' => $s->id]);
        $shipments->push($s);
    }

    $result = $this->service->validateShipmentsForBatch($shipments);

    expect($result->eligible)->toHaveCount(3)
        ->and($result->ineligible)->toBeEmpty()
        ->and($result->hasIneligible())->toBeFalse();
});

// --- createBatch ---

it('creates packages with correct weight calculation', function (): void {
    Bus::fake();

    $user = User::factory()->admin()->create();
    $boxSize = BoxSize::factory()->create(['empty_weight' => 0.50, 'height' => 4, 'width' => 6, 'length' => 8]);

    $product1 = Product::factory()->create(['weight' => 1.00]);
    $product2 = Product::factory()->create(['weight' => 0.50]);

    $shipment = Shipment::factory()->create();
    ShipmentItem::factory()->create(['shipment_id' => $shipment->id, 'product_id' => $product1->id, 'quantity' => 2]);
    ShipmentItem::factory()->create(['shipment_id' => $shipment->id, 'product_id' => $product2->id, 'quantity' => 1]);

    // Reload with relations for createBatch
    $shipment->load('shipmentItems.product');

    $batch = $this->service->createBatch(collect([$shipment]), $boxSize, $user, 'pdf', null);

    expect($batch)->toBeInstanceOf(LabelBatch::class)
        ->and($batch->total_shipments)->toBe(1)
        ->and($batch->status)->toBe(LabelBatchStatus::Pending);

    $package = Package::where('shipment_id', $shipment->id)->first();
    // 0.50 (box) + 2*1.00 (product1) + 1*0.50 (product2) = 3.00
    expect((float) $package->weight)->toBe(3.00)
        ->and((float) $package->height)->toBe(4.00)
        ->and((float) $package->width)->toBe(6.00)
        ->and((float) $package->length)->toBe(8.00);
});

it('creates package items from shipment items', function (): void {
    Bus::fake();

    $user = User::factory()->admin()->create();
    $boxSize = BoxSize::factory()->create();

    $shipment = Shipment::factory()->create();
    $item1 = ShipmentItem::factory()->create(['shipment_id' => $shipment->id, 'quantity' => 3]);
    $item2 = ShipmentItem::factory()->create(['shipment_id' => $shipment->id, 'quantity' => 1]);

    $shipment->load('shipmentItems.product');

    $batch = $this->service->createBatch(collect([$shipment]), $boxSize, $user, 'pdf', null);

    $package = Package::where('shipment_id', $shipment->id)->first();
    $packageItems = PackageItem::where('package_id', $package->id)->get();

    expect($packageItems)->toHaveCount(2);
    expect($packageItems->firstWhere('shipment_item_id', $item1->id)->quantity)->toBe(3);
    expect($packageItems->firstWhere('shipment_item_id', $item2->id)->quantity)->toBe(1);
});

it('creates label batch items for each shipment', function (): void {
    Bus::fake();

    $user = User::factory()->admin()->create();
    $boxSize = BoxSize::factory()->create();

    $shipments = collect();
    for ($i = 0; $i < 3; $i++) {
        $s = Shipment::factory()->create();
        ShipmentItem::factory()->create(['shipment_id' => $s->id]);
        $s->load('shipmentItems.product');
        $shipments->push($s);
    }

    $batch = $this->service->createBatch($shipments, $boxSize, $user, 'pdf', null);

    $batchItems = LabelBatchItem::where('label_batch_id', $batch->id)->get();

    expect($batchItems)->toHaveCount(3);
    expect($batchItems->pluck('status')->unique()->toArray())->toBe([LabelBatchItemStatus::Pending]);
});

it('dispatches a bus batch with jobs', function (): void {
    Bus::fake();

    $user = User::factory()->admin()->create();
    $boxSize = BoxSize::factory()->create();

    $shipment = Shipment::factory()->create();
    ShipmentItem::factory()->create(['shipment_id' => $shipment->id]);
    $shipment->load('shipmentItems.product');

    $batch = $this->service->createBatch(collect([$shipment]), $boxSize, $user, 'zpl', 203);

    Bus::assertBatched(function ($batch) {
        return $batch->jobs->count() === 1;
    });

    expect($batch->label_format)->toBe('zpl')
        ->and($batch->label_dpi)->toBe(203);
});
