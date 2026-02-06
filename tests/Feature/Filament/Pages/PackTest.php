<?php

use App\Enums\Role;
use App\Filament\Pages\Pack;
use App\Models\BoxSize;
use App\Models\Package;
use App\Models\PackageItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\SettingsService;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
});

it('loads box sizes into the component', function (): void {
    $boxSize = BoxSize::factory()->create([
        'code' => 'A1',
        'height' => 10.00,
        'width' => 8.00,
        'length' => 6.00,
    ]);

    $shipment = Shipment::factory()->create();

    Livewire::test(Pack::class, ['shipment_id' => $shipment->shipment_reference])
        ->assertSet('boxSizes.A1.id', $boxSize->id)
        ->assertSet('boxSizes.A1.code', 'A1')
        ->assertSet('boxSizes.A1.height', '10.00')
        ->assertSet('boxSizes.A1.width', '8.00')
        ->assertSet('boxSizes.A1.length', '6.00');
});

it('loads shipment with packing items', function (): void {
    $product = Product::factory()->create([
        'barcode' => '1234567890123',
        'description' => 'Test Product',
    ]);

    $shipment = Shipment::factory()->create();
    $shipmentItem = ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $product->id,
        'quantity' => 3,
        'transparency' => false,
    ]);

    Livewire::test(Pack::class, ['shipment_id' => $shipment->shipment_reference])
        ->assertSet('shipment.id', $shipment->id)
        ->assertCount('packingItems', 1)
        ->assertSet('packingItems.0.id', $shipmentItem->id)
        ->assertSet('packingItems.0.quantity', 3)
        ->assertSet('packingItems.0.barcode', '1234567890123')
        ->assertSet('packingItems.0.packed', 0)
        ->assertSet('packingItems.0.transparency_codes', []);
});

it('shows empty state when no shipment loaded', function (): void {
    Livewire::test(Pack::class)
        ->assertSet('shipment', null)
        ->assertSet('packingItems', []);
});

it('navigates to shipment by reference', function (): void {
    $shipment = Shipment::factory()->create();

    Livewire::test(Pack::class)
        ->call('navigateToShipment', $shipment->shipment_reference)
        ->assertRedirect("/pack/{$shipment->shipment_reference}");
});

it('shows error notification for invalid shipment reference', function (): void {
    Livewire::test(Pack::class)
        ->call('navigateToShipment', 'NONEXISTENT-REF')
        ->assertNotified()
        ->assertNoRedirect();
});

it('passes box sizes to blade view', function (): void {
    BoxSize::factory()->create(['code' => 'S1']);
    BoxSize::factory()->create(['code' => 'M2']);

    Livewire::test(Pack::class)
        ->assertViewHas('boxSizes', function ($boxSizes) {
            return array_key_exists('S1', $boxSizes)
                && array_key_exists('M2', $boxSizes);
        });
});

it('ships a package via manual ship', function (): void {
    $boxSize = BoxSize::factory()->create([
        'code' => 'A1',
        'height' => 10.00,
        'width' => 8.00,
        'length' => 6.00,
    ]);

    $product = Product::factory()->create(['barcode' => '1234567890123']);
    $shipment = Shipment::factory()->create();
    $shipmentItem = ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'transparency' => false,
    ]);

    $packingItems = [[
        'id' => $shipmentItem->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'packed' => 1,
        'barcode' => '1234567890123',
        'description' => $product->description,
        'transparency' => false,
        'transparency_codes' => [],
    ]];

    Livewire::test(Pack::class, ['shipment_id' => $shipment->shipment_reference])
        ->call('ship', $packingItems, $boxSize->id, '1.5', '10', '8', '6', false)
        ->assertRedirect();

    expect(Package::where('shipment_id', $shipment->id)->exists())->toBeTrue();

    $package = Package::where('shipment_id', $shipment->id)->first();
    expect((float) $package->weight)->toBe(1.5)
        ->and((float) $package->height)->toBe(10.0)
        ->and((float) $package->width)->toBe(8.0)
        ->and((float) $package->length)->toBe(6.0)
        ->and($package->box_size_id)->toBe($boxSize->id)
        ->and($package->packageItems)->toHaveCount(1)
        ->and($package->packageItems->first()->quantity)->toBe(1);
});

it('rejects ship when dimensions are missing', function (): void {
    $product = Product::factory()->create(['barcode' => '1234567890123']);
    $shipment = Shipment::factory()->create();
    $shipmentItem = ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'transparency' => false,
    ]);

    $packingItems = [[
        'id' => $shipmentItem->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'packed' => 1,
        'barcode' => '1234567890123',
        'description' => $product->description,
        'transparency' => false,
        'transparency_codes' => [],
    ]];

    Livewire::test(Pack::class, ['shipment_id' => $shipment->shipment_reference])
        ->call('ship', $packingItems, null, '1.5', '10', '8', '', false)
        ->assertNotified();

    expect(Package::where('shipment_id', $shipment->id)->exists())->toBeFalse();
});

it('rejects ship when items not fully packed', function (): void {
    $product = Product::factory()->create(['barcode' => '1234567890123']);
    $shipment = Shipment::factory()->create();
    $shipmentItem = ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'transparency' => false,
    ]);

    $packingItems = [[
        'id' => $shipmentItem->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'packed' => 1,
        'barcode' => '1234567890123',
        'description' => $product->description,
        'transparency' => false,
        'transparency_codes' => [],
    ]];

    Livewire::test(Pack::class, ['shipment_id' => $shipment->shipment_reference])
        ->call('ship', $packingItems, null, '1.5', '10', '8', '6', false)
        ->assertNotified();

    expect(Package::where('shipment_id', $shipment->id)->exists())->toBeFalse();
});

it('ships with transparency codes', function (): void {
    $boxSize = BoxSize::factory()->create();
    $product = Product::factory()->create(['barcode' => '1234567890123']);
    $shipment = Shipment::factory()->create();
    $shipmentItem = ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'transparency' => true,
    ]);

    $transparencyCode = 'AZ:A1B2C3D4E5F6G7H8I9J0K1L2MN';
    $packingItems = [[
        'id' => $shipmentItem->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'packed' => 1,
        'barcode' => '1234567890123',
        'description' => $product->description,
        'transparency' => true,
        'transparency_codes' => [$transparencyCode],
    ]];

    Livewire::test(Pack::class, ['shipment_id' => $shipment->shipment_reference])
        ->call('ship', $packingItems, $boxSize->id, '1.5', '10', '8', '6', false)
        ->assertRedirect();

    $package = Package::where('shipment_id', $shipment->id)->first();
    expect($package->packageItems->first()->transparency_codes)->toBe([$transparencyCode]);
});

it('allows shipping when packing validation is disabled', function (): void {
    Setting::create([
        'key' => 'packing_validation_enabled',
        'value' => '0',
        'type' => 'boolean',
        'group' => 'general',
    ]);
    SettingsService::clearCache();

    $boxSize = BoxSize::factory()->create();
    $product = Product::factory()->create(['barcode' => '1234567890123']);
    $shipment = Shipment::factory()->create();
    $shipmentItem = ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'transparency' => false,
    ]);

    // Items NOT fully packed, but packing validation disabled
    $packingItems = [[
        'id' => $shipmentItem->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'packed' => 0,
        'barcode' => '1234567890123',
        'description' => $product->description,
        'transparency' => false,
        'transparency_codes' => [],
    ]];

    Livewire::test(Pack::class, ['shipment_id' => $shipment->shipment_reference])
        ->call('ship', $packingItems, $boxSize->id, '1.5', '10', '8', '6', false)
        ->assertRedirect();

    expect(Package::where('shipment_id', $shipment->id)->exists())->toBeTrue();
});

it('cleans up existing unshipped packages before creating a new one', function (): void {
    $boxSize = BoxSize::factory()->create();
    $product = Product::factory()->create(['barcode' => '1234567890123']);
    $shipment = Shipment::factory()->create();
    $shipmentItem = ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'transparency' => false,
    ]);

    // Create an orphaned unshipped package from a previous attempt
    $orphan = Package::create([
        'shipment_id' => $shipment->id,
        'box_size_id' => $boxSize->id,
        'weight' => 1.0,
        'height' => 5,
        'width' => 5,
        'length' => 5,
        'shipped' => false,
    ]);
    $orphan->packageItems()->create([
        'shipment_item_id' => $shipmentItem->id,
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    $packingItems = [[
        'id' => $shipmentItem->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'packed' => 1,
        'barcode' => '1234567890123',
        'description' => $product->description,
        'transparency' => false,
        'transparency_codes' => [],
    ]];

    Livewire::test(Pack::class, ['shipment_id' => $shipment->shipment_reference])
        ->call('ship', $packingItems, $boxSize->id, '2.0', '10', '8', '6', false)
        ->assertRedirect();

    // Orphan should be deleted, only the new package remains
    expect(Package::where('shipment_id', $shipment->id)->count())->toBe(1)
        ->and(Package::find($orphan->id))->toBeNull();
});

it('preserves shipped packages when creating a new one', function (): void {
    $boxSize = BoxSize::factory()->create();
    $product = Product::factory()->create(['barcode' => '1234567890123']);
    $shipment = Shipment::factory()->create();
    $shipmentItem = ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'transparency' => false,
    ]);

    // Create a shipped package (should NOT be deleted)
    $shipped = Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'box_size_id' => $boxSize->id,
    ]);

    $packingItems = [[
        'id' => $shipmentItem->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'packed' => 1,
        'barcode' => '1234567890123',
        'description' => $product->description,
        'transparency' => false,
        'transparency_codes' => [],
    ]];

    Livewire::test(Pack::class, ['shipment_id' => $shipment->shipment_reference])
        ->call('ship', $packingItems, $boxSize->id, '2.0', '10', '8', '6', false)
        ->assertRedirect();

    // Both packages should exist: the shipped one and the new one
    expect(Package::where('shipment_id', $shipment->id)->count())->toBe(2)
        ->and(Package::find($shipped->id))->not->toBeNull()
        ->and(Package::find($shipped->id)->shipped)->toBeTrue();
});

it('downgrades auto-ship to manual ship for non-admin users', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Manager]));

    $boxSize = BoxSize::factory()->create();
    $product = Product::factory()->create(['barcode' => '1234567890123']);
    $shipment = Shipment::factory()->create();
    $shipmentItem = ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'transparency' => false,
    ]);

    $packingItems = [[
        'id' => $shipmentItem->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'packed' => 1,
        'barcode' => '1234567890123',
        'description' => $product->description,
        'transparency' => false,
        'transparency_codes' => [],
    ]];

    // Pass autoShip=true as a non-admin — should be downgraded to manual ship (redirect)
    Livewire::test(Pack::class, ['shipment_id' => $shipment->shipment_reference])
        ->call('ship', $packingItems, $boxSize->id, '1.5', '10', '8', '6', true)
        ->assertRedirect();

    $package = Package::where('shipment_id', $shipment->id)->first();
    expect($package)->not->toBeNull()
        ->and($package->shipped)->toBeFalse();
});

it('deletes orphaned package items when cleaning up unshipped packages', function (): void {
    $boxSize = BoxSize::factory()->create();
    $product = Product::factory()->create(['barcode' => '1234567890123']);
    $shipment = Shipment::factory()->create();
    $shipmentItem = ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'transparency' => false,
    ]);

    // Create an orphaned package with items
    $orphan = Package::create([
        'shipment_id' => $shipment->id,
        'weight' => 1.0,
        'height' => 5,
        'width' => 5,
        'length' => 5,
        'shipped' => false,
    ]);
    $orphanItem = $orphan->packageItems()->create([
        'shipment_item_id' => $shipmentItem->id,
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    $packingItems = [[
        'id' => $shipmentItem->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'packed' => 1,
        'barcode' => '1234567890123',
        'description' => $product->description,
        'transparency' => false,
        'transparency_codes' => [],
    ]];

    Livewire::test(Pack::class, ['shipment_id' => $shipment->shipment_reference])
        ->call('ship', $packingItems, $boxSize->id, '2.0', '10', '8', '6', false)
        ->assertRedirect();

    expect(PackageItem::find($orphanItem->id))->toBeNull();
});
