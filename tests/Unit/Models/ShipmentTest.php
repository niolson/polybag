<?php

use App\Enums\PackageStatus;
use App\Enums\ShipmentStatus;
use App\Models\Package;
use App\Models\PackageItem;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Services\SettingsService;

it('marks shipment as shipped when all items are packed in shipped packages', function (): void {
    $shipment = Shipment::factory()->create();
    $product = Product::factory()->create();

    $shipmentItem = ShipmentItem::factory()->for($shipment)->create([
        'product_id' => $product->id,
        'quantity' => 2,
    ]);

    $package = Package::factory()->for($shipment)->create(['status' => PackageStatus::Shipped]);

    PackageItem::factory()->create([
        'package_id' => $package->id,
        'shipment_item_id' => $shipmentItem->id,
        'product_id' => $product->id,
        'quantity' => 2,
    ]);

    $shipment->load('shipmentItems');
    $shipment->updateShippedStatus();

    expect($shipment->fresh()->status)->toBe(ShipmentStatus::Shipped);
});

it('does not mark shipment as shipped when items are only partially packed', function (): void {
    $shipment = Shipment::factory()->create();
    $product = Product::factory()->create();

    $shipmentItem = ShipmentItem::factory()->for($shipment)->create([
        'product_id' => $product->id,
        'quantity' => 3,
    ]);

    $package = Package::factory()->for($shipment)->create(['status' => PackageStatus::Shipped]);

    PackageItem::factory()->create([
        'package_id' => $package->id,
        'shipment_item_id' => $shipmentItem->id,
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    $shipment->load('shipmentItems');
    $shipment->updateShippedStatus();

    expect($shipment->fresh()->status)->toBe(ShipmentStatus::Open);
});

it('marks shipment as shipped with any shipped package when packing validation is off', function (): void {
    app(SettingsService::class)->set('packing_validation_enabled', false);

    $shipment = Shipment::factory()->create();

    ShipmentItem::factory()->for($shipment)->create(['quantity' => 5]);

    Package::factory()->for($shipment)->create(['status' => PackageStatus::Shipped]);

    $shipment->load('shipmentItems');
    $shipment->updateShippedStatus();

    expect($shipment->fresh()->status)->toBe(ShipmentStatus::Shipped);
});

it('reverts shipped status when package shipping is cleared', function (): void {
    $shipment = Shipment::factory()->create();
    $product = Product::factory()->create();

    $shipmentItem = ShipmentItem::factory()->for($shipment)->create([
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    $package = Package::factory()->for($shipment)->create(['status' => PackageStatus::Shipped]);

    PackageItem::factory()->create([
        'package_id' => $package->id,
        'shipment_item_id' => $shipmentItem->id,
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    $shipment->load('shipmentItems');
    $shipment->updateShippedStatus();

    expect($shipment->fresh()->status)->toBe(ShipmentStatus::Shipped);

    $package->clearShipping();

    expect($shipment->fresh()->status)->toBe(ShipmentStatus::Open);
});
