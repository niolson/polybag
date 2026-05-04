<?php

use App\Contracts\PackageDraftWorkflow;
use App\DataTransferObjects\PackageDrafts\BatchPackageDraftInput;
use App\DataTransferObjects\PackageDrafts\Measurements;
use App\DataTransferObjects\PackageDrafts\PackageDraftInput;
use App\DataTransferObjects\PackageDrafts\PackageDraftItemInput;
use App\DataTransferObjects\PackageDrafts\PackageDraftOptions;
use App\Enums\PackageStatus;
use App\Events\PackageCreated;
use App\Exceptions\PackageDraftIncompleteException;
use App\Exceptions\PackageDraftInvalidException;
use App\Models\BoxSize;
use App\Models\Package;
use App\Models\PackageItem;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use Illuminate\Support\Facades\Event;

it('creates a package draft when resuming a shipment without one', function (): void {
    Event::fake([PackageCreated::class]);

    $shipment = Shipment::factory()->create();
    $service = app(PackageDraftWorkflow::class);

    $snapshot = $service->resumeForShipment($shipment);

    expect($snapshot->shipmentId)->toBe($shipment->id)
        ->and($snapshot->packageDraftId)->toBeInt()
        ->and($snapshot->readyToShip)->toBeFalse();

    $package = Package::findOrFail($snapshot->packageDraftId);
    expect($package->shipment_id)->toBe($shipment->id)
        ->and($package->status)->toBe(PackageStatus::Unshipped)
        ->and($package->packageItems)->toHaveCount(0);

    Event::assertDispatched(PackageCreated::class, fn (PackageCreated $event): bool => $event->package->id === $package->id);
});

it('resumes an existing package draft as source of truth', function (): void {
    $shipment = Shipment::factory()->create();
    $boxSize = BoxSize::factory()->create();
    $package = Package::factory()->create([
        'shipment_id' => $shipment->id,
        'box_size_id' => $boxSize->id,
        'weight' => 2.5,
        'height' => 10,
        'width' => 8,
        'length' => 6,
        'status' => PackageStatus::Unshipped,
    ]);

    $product = Product::factory()->create(['sku' => 'SKU-1']);
    $shipmentItem = ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $product->id,
        'quantity' => 2,
    ]);
    PackageItem::factory()->create([
        'package_id' => $package->id,
        'shipment_item_id' => $shipmentItem->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'transparency_codes' => ['ABC123'],
    ]);

    $snapshot = app(PackageDraftWorkflow::class)->resumeForShipment($shipment);

    expect($snapshot->packageDraftId)->toBe($package->id)
        ->and($snapshot->boxSizeId)->toBe($boxSize->id)
        ->and((float) $snapshot->measurements->weight)->toBe(2.5)
        ->and($snapshot->items)->toHaveCount(1)
        ->and($snapshot->items[0]->shipmentItemId)->toBe($shipmentItem->id)
        ->and($snapshot->items[0]->quantity)->toBe(1)
        ->and($snapshot->items[0]->transparencyCodes)->toBe(['ABC123']);

    expect(Package::where('shipment_id', $shipment->id)->count())->toBe(1);
});

it('does not dispatch package created when resuming an existing package draft', function (): void {
    $shipment = Shipment::factory()->create();
    $package = Package::factory()->create([
        'shipment_id' => $shipment->id,
        'status' => PackageStatus::Unshipped,
    ]);

    Event::fake([PackageCreated::class]);

    $snapshot = app(PackageDraftWorkflow::class)->resumeForShipment($shipment);

    expect($snapshot->packageDraftId)->toBe($package->id);
    Event::assertNotDispatched(PackageCreated::class);
});

it('saves through the existing package draft instead of creating a second active draft', function (): void {
    $shipment = Shipment::factory()->create();
    $package = Package::factory()->create([
        'shipment_id' => $shipment->id,
        'status' => PackageStatus::Unshipped,
    ]);

    $snapshot = app(PackageDraftWorkflow::class)->saveForShipment($shipment, new PackageDraftInput(
        measurements: new Measurements(3, 10, 8, 6),
        boxSizeId: null,
    ), new PackageDraftOptions(requireCompletePackedItems: false));

    expect($snapshot->packageDraftId)->toBe($package->id)
        ->and(Package::where('shipment_id', $shipment->id)->where('status', PackageStatus::Unshipped)->count())->toBe(1);
});

it('saves draft measurements and replaces package items on the active draft', function (): void {
    Event::fake([PackageCreated::class]);

    $shipment = Shipment::factory()->create();
    $boxSize = BoxSize::factory()->create();
    $firstProduct = Product::factory()->create(['weight' => 1]);
    $secondProduct = Product::factory()->create(['weight' => 2]);
    $firstShipmentItem = ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $firstProduct->id,
        'quantity' => 2,
    ]);
    $secondShipmentItem = ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $secondProduct->id,
        'quantity' => 1,
    ]);

    $service = app(PackageDraftWorkflow::class);
    $created = $service->saveForShipment($shipment, new PackageDraftInput(
        measurements: new Measurements(3, 10, 8, 6),
        boxSizeId: $boxSize->id,
        items: [
            new PackageDraftItemInput($firstShipmentItem->id, $firstProduct->id, 1, ['T1']),
        ],
    ));

    $updated = $service->saveForShipment($shipment, new PackageDraftInput(
        measurements: new Measurements(4, 12, 9, 7),
        boxSizeId: null,
        items: [
            new PackageDraftItemInput($firstShipmentItem->id, $firstProduct->id, 2, ['T2', 'T3']),
            new PackageDraftItemInput($secondShipmentItem->id, $secondProduct->id, 1),
        ],
    ));

    expect($updated->packageDraftId)->toBe($created->packageDraftId)
        ->and((float) $updated->measurements->weight)->toBe(4.0)
        ->and($updated->boxSizeId)->toBeNull()
        ->and($updated->items)->toHaveCount(2);

    $package = Package::with('packageItems')->findOrFail($updated->packageDraftId);
    expect(Package::where('shipment_id', $shipment->id)->count())->toBe(1)
        ->and((float) $package->height)->toBe(12.0)
        ->and($package->packageItems)->toHaveCount(2)
        ->and($package->packageItems->firstWhere('shipment_item_id', $firstShipmentItem->id)->quantity)->toBe(2)
        ->and($package->packageItems->firstWhere('shipment_item_id', $firstShipmentItem->id)->transparency_codes)->toBe(['T2', 'T3']);

    Event::assertDispatched(PackageCreated::class, 1);
});

it('rejects package items that do not belong to the shipment', function (): void {
    $shipment = Shipment::factory()->create();
    $otherShipment = Shipment::factory()->create();
    $product = Product::factory()->create();
    $otherShipmentItem = ShipmentItem::factory()->create([
        'shipment_id' => $otherShipment->id,
        'product_id' => $product->id,
    ]);

    app(PackageDraftWorkflow::class)->saveForShipment($shipment, new PackageDraftInput(
        measurements: new Measurements(1, 2, 3, 4),
        boxSizeId: null,
        items: [
            new PackageDraftItemInput($otherShipmentItem->id, $product->id, 1),
        ],
    ));
})->throws(PackageDraftInvalidException::class, 'does not belong to this shipment');

it('rejects product mismatches for shipment items', function (): void {
    $shipment = Shipment::factory()->create();
    $expectedProduct = Product::factory()->create();
    $wrongProduct = Product::factory()->create();
    $shipmentItem = ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $expectedProduct->id,
    ]);

    app(PackageDraftWorkflow::class)->saveForShipment($shipment, new PackageDraftInput(
        measurements: new Measurements(1, 2, 3, 4),
        boxSizeId: null,
        items: [
            new PackageDraftItemInput($shipmentItem->id, $wrongProduct->id, 1),
        ],
    ));
})->throws(PackageDraftInvalidException::class, 'Product mismatch');

it('requires positive measurements before a draft is ready to ship', function (): void {
    $shipment = Shipment::factory()->create();

    $snapshot = app(PackageDraftWorkflow::class)->saveForShipment($shipment, new PackageDraftInput(
        measurements: new Measurements(0, 2, 3, 4),
        boxSizeId: null,
    ), new PackageDraftOptions(requireCompletePackedItems: false));

    app(PackageDraftWorkflow::class)->assertReadyToShip($shipment, $snapshot->packageDraftId);
})->throws(PackageDraftIncompleteException::class, 'Package draft is missing valid measurements');

it('requires complete packed items when requested before a draft is ready to ship', function (): void {
    $shipment = Shipment::factory()->create();
    $product = Product::factory()->create();
    $shipmentItem = ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $product->id,
        'quantity' => 2,
    ]);

    $snapshot = app(PackageDraftWorkflow::class)->saveForShipment($shipment, new PackageDraftInput(
        measurements: new Measurements(1, 2, 3, 4),
        boxSizeId: null,
        items: [
            new PackageDraftItemInput($shipmentItem->id, $product->id, 1),
        ],
    ));

    app(PackageDraftWorkflow::class)->assertReadyToShip($shipment, $snapshot->packageDraftId);
})->throws(PackageDraftIncompleteException::class, 'Not all shipment items are packed');

it('returns a ready package draft when measurements and packed items are complete', function (): void {
    $shipment = Shipment::factory()->create();
    $product = Product::factory()->create(['weight' => 1.5]);
    $shipmentItem = ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $product->id,
        'quantity' => 2,
    ]);

    $snapshot = app(PackageDraftWorkflow::class)->saveForShipment($shipment, new PackageDraftInput(
        measurements: new Measurements(3, 2, 3, 4),
        boxSizeId: null,
        items: [
            new PackageDraftItemInput($shipmentItem->id, $product->id, 2),
        ],
    ));

    $ready = app(PackageDraftWorkflow::class)->assertReadyToShip($shipment, $snapshot->packageDraftId);

    expect($ready->package->id)->toBe($snapshot->packageDraftId)
        ->and($ready->snapshot->readyToShip)->toBeTrue();
});

it('creates a ready batch package draft from all shipment items and box dimensions', function (): void {
    $boxSize = BoxSize::factory()->create([
        'empty_weight' => 0.50,
        'height' => 4,
        'width' => 6,
        'length' => 8,
    ]);
    $firstProduct = Product::factory()->create(['weight' => 1.00]);
    $secondProduct = Product::factory()->create(['weight' => 0.50]);
    $shipment = Shipment::factory()->create();
    $firstItem = ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $firstProduct->id,
        'quantity' => 2,
    ]);
    $secondItem = ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $secondProduct->id,
        'quantity' => 1,
    ]);

    Event::fake([PackageCreated::class]);

    $ready = app(PackageDraftWorkflow::class)->createBatchReadyDraft(
        $shipment,
        new BatchPackageDraftInput($boxSize),
    );

    $package = $ready->package->fresh('packageItems');

    expect($ready->snapshot->readyToShip)->toBeTrue()
        ->and((float) $package->weight)->toBe(3.00)
        ->and((float) $package->height)->toBe(4.00)
        ->and((float) $package->width)->toBe(6.00)
        ->and((float) $package->length)->toBe(8.00)
        ->and($package->box_size_id)->toBe($boxSize->id)
        ->and($package->packageItems)->toHaveCount(2)
        ->and($package->packageItems->firstWhere('shipment_item_id', $firstItem->id)->quantity)->toBe(2)
        ->and($package->packageItems->firstWhere('shipment_item_id', $secondItem->id)->quantity)->toBe(1);

    Event::assertDispatched(PackageCreated::class, fn (PackageCreated $event): bool => $event->package->id === $package->id);
});

it('rejects batch package drafts when an active package draft already exists', function (): void {
    $shipment = Shipment::factory()->create();
    Package::factory()->for($shipment)->create(['status' => PackageStatus::Unshipped]);

    app(PackageDraftWorkflow::class)->createBatchReadyDraft(
        $shipment,
        new BatchPackageDraftInput(BoxSize::factory()->create()),
    );
})->throws(PackageDraftInvalidException::class, 'already has an active package draft');

it('rejects batch package drafts when a shipment item is missing product weight', function (): void {
    $shipment = Shipment::factory()->create();
    $product = Product::factory()->create(['weight' => 0]);
    ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $product->id,
    ]);

    app(PackageDraftWorkflow::class)->createBatchReadyDraft(
        $shipment,
        new BatchPackageDraftInput(BoxSize::factory()->create()),
    );
})->throws(PackageDraftInvalidException::class, 'missing product weight');
