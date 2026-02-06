<?php

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

    $package = Package::factory()->for($shipment)->create(['shipped' => true]);

    PackageItem::factory()->create([
        'package_id' => $package->id,
        'shipment_item_id' => $shipmentItem->id,
        'product_id' => $product->id,
        'quantity' => 2,
    ]);

    $shipment->load('shipmentItems');
    $shipment->updateShippedStatus();

    expect($shipment->fresh()->shipped)->toBeTrue();
});

it('does not mark shipment as shipped when items are only partially packed', function (): void {
    $shipment = Shipment::factory()->create();
    $product = Product::factory()->create();

    $shipmentItem = ShipmentItem::factory()->for($shipment)->create([
        'product_id' => $product->id,
        'quantity' => 3,
    ]);

    $package = Package::factory()->for($shipment)->create(['shipped' => true]);

    PackageItem::factory()->create([
        'package_id' => $package->id,
        'shipment_item_id' => $shipmentItem->id,
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    $shipment->load('shipmentItems');
    $shipment->updateShippedStatus();

    expect($shipment->fresh()->shipped)->toBeFalse();
});

it('marks shipment as shipped with any shipped package when packing validation is off', function (): void {
    SettingsService::set('packing_validation_enabled', false);

    $shipment = Shipment::factory()->create();

    ShipmentItem::factory()->for($shipment)->create(['quantity' => 5]);

    Package::factory()->for($shipment)->create(['shipped' => true]);

    $shipment->load('shipmentItems');
    $shipment->updateShippedStatus();

    expect($shipment->fresh()->shipped)->toBeTrue();
});

it('reverts shipped status when package shipping is cleared', function (): void {
    $shipment = Shipment::factory()->create();
    $product = Product::factory()->create();

    $shipmentItem = ShipmentItem::factory()->for($shipment)->create([
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    $package = Package::factory()->for($shipment)->create(['shipped' => true]);

    PackageItem::factory()->create([
        'package_id' => $package->id,
        'shipment_item_id' => $shipmentItem->id,
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    $shipment->load('shipmentItems');
    $shipment->updateShippedStatus();

    expect($shipment->fresh()->shipped)->toBeTrue();

    $package->clearShipping();

    expect($shipment->fresh()->shipped)->toBeFalse();
});
