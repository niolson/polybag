<?php

use App\Enums\ShipmentStatus;
use App\Events\PackageCreated;
use App\Models\Channel;
use App\Models\Package;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Services\PackagingService;
use Illuminate\Support\Facades\Event;

it('createShipment creates a shipment record from data', function (): void {
    $service = app(PackagingService::class);

    $shipment = $service->createShipment([
        'shipment_reference' => 'REF-001',
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'company' => null,
        'address1' => '123 Main St',
        'address2' => null,
        'city' => 'Seattle',
        'state_or_province' => 'WA',
        'postal_code' => '98101',
        'country' => 'US',
        'phone' => null,
        'email' => null,
        'shipping_method_id' => null,
    ]);

    expect($shipment)->toBeInstanceOf(Shipment::class)
        ->and($shipment->shipment_reference)->toBe('REF-001')
        ->and($shipment->first_name)->toBe('Jane')
        ->and($shipment->city)->toBe('Seattle')
        ->and($shipment->status)->toBe(ShipmentStatus::Open)
        ->and($shipment->channel_id)->toBeNull();
});

it('createShipment accepts a channel id', function (): void {
    $channel = Channel::factory()->create(['name' => 'API']);

    $shipment = app(PackagingService::class)->createShipment([
        'shipment_reference' => null,
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'company' => null,
        'address1' => '1 Test St',
        'address2' => null,
        'city' => 'Portland',
        'state_or_province' => 'OR',
        'postal_code' => '97201',
        'country' => 'US',
        'phone' => null,
        'email' => null,
        'shipping_method_id' => null,
    ], channelId: $channel->id);

    expect($shipment->channel_id)->toBe($channel->id);
});

it('createPackage creates a package and dispatches PackageCreated', function (): void {
    Event::fake([PackageCreated::class]);

    $shipment = Shipment::factory()->create();

    $package = app(PackagingService::class)->createPackage(
        shipment: $shipment,
        weight: 2.5,
        height: 10.0,
        width: 8.0,
        length: 6.0,
    );

    expect($package)->toBeInstanceOf(Package::class)
        ->and($package->shipment_id)->toBe($shipment->id)
        ->and((float) $package->weight)->toBe(2.5)
        ->and((float) $package->height)->toBe(10.0);

    Event::assertDispatched(PackageCreated::class, fn ($e) => $e->package->id === $package->id);
});

it('createPackage attaches packing items', function (): void {
    Event::fake([PackageCreated::class]);

    $shipment = Shipment::factory()->create();
    $product = Product::factory()->create();
    $shipmentItem = ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $product->id,
        'quantity' => 2,
    ]);

    $package = app(PackagingService::class)->createPackage(
        shipment: $shipment,
        weight: 1.0,
        height: 5.0,
        width: 5.0,
        length: 5.0,
        packingItems: [
            [
                'shipment_item_id' => $shipmentItem->id,
                'product_id' => $product->id,
                'quantity' => 2,
                'transparency_codes' => [],
            ],
        ],
    );

    expect($package->packageItems)->toHaveCount(1)
        ->and($package->packageItems->first()->shipment_item_id)->toBe($shipmentItem->id)
        ->and($package->packageItems->first()->quantity)->toBe(2);
});

it('createPackage creates a package without items when none provided', function (): void {
    Event::fake([PackageCreated::class]);

    $shipment = Shipment::factory()->create();

    $package = app(PackagingService::class)->createPackage(
        shipment: $shipment,
        weight: 1.5,
        height: 4.0,
        width: 4.0,
        length: 4.0,
    );

    expect($package->packageItems)->toHaveCount(0);
});
