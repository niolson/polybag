<?php

use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageShipping\PackageShippingResult;
use App\Enums\PackageStatus;
use App\Enums\PickingStatus;
use App\Enums\Role;
use App\Filament\Pages\Pack;
use App\Models\BoxSize;
use App\Models\Client;
use App\Models\Location;
use App\Models\Package;
use App\Models\PackageItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Session;
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

    Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
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

    Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
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

it('blocks packing at a different explicitly configured operator location', function (): void {
    $shipmentLocation = Location::factory()->create(['name' => 'East Warehouse']);
    $operatorLocation = Location::factory()->create(['name' => 'West Warehouse']);
    $this->actingAs(User::factory()->admin()->create(['location_id' => $operatorLocation]));
    $shipment = Shipment::factory()->create(['location_id' => $shipmentLocation]);

    Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
        ->assertNotified('Location unavailable')
        ->assertSet('shipment', null)
        ->assertRedirect('/pack');
});

it('blocks packing at an inactive shipment location', function (): void {
    $location = Location::factory()->create(['active' => false]);
    $shipment = Shipment::factory()->create(['location_id' => $location]);

    Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
        ->assertNotified('Location unavailable')
        ->assertRedirect('/pack');
});

it('navigates to shipment by reference', function (): void {
    $shipment = Shipment::factory()->create();

    Livewire::test(Pack::class)
        ->call('navigateToShipment', $shipment->shipment_reference)
        ->assertRedirect("/pack/{$shipment->id}");
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
        ->assertViewHas('boxSizes', function ($boxSizes): bool {
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

    Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
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

    Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
        ->call('ship', $packingItems, null, '1.5', '10', '8', '', false)
        ->assertNotified('Not Ready')
        ->assertDispatched('shipping-error');

    $package = Package::where('shipment_id', $shipment->id)->first();
    expect($package)->not->toBeNull()
        ->and($package->status)->toBe(PackageStatus::Unshipped)
        ->and($package->length)->toBeNull();
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

    Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
        ->call('ship', $packingItems, null, '1.5', '10', '8', '6', false)
        ->assertNotified('Not Ready')
        ->assertDispatched('shipping-error');

    $package = Package::where('shipment_id', $shipment->id)->first();
    expect($package)->not->toBeNull()
        ->and($package->status)->toBe(PackageStatus::Unshipped)
        ->and($package->packageItems->first()->quantity)->toBe(1);
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

    Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
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
    app(SettingsService::class)->clearCache();

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

    Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
        ->assertSet('packingValidationEnabled', false)
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
        'status' => PackageStatus::Unshipped,
    ]);
    $orphan->packageItems()->create([
        'shipment_item_id' => $shipmentItem->id,
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    // Simulate that this user's session tracks the orphan as in-progress
    Session::put('in_progress_package_id', $orphan->id);

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

    Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
        ->call('ship', $packingItems, $boxSize->id, '2.0', '10', '8', '6', false)
        ->assertRedirect();

    // Existing unshipped package is now the durable Package Draft and is updated in place.
    expect(Package::where('shipment_id', $shipment->id)->count())->toBe(1)
        ->and(Package::find($orphan->id))->not->toBeNull()
        ->and((float) Package::find($orphan->id)->weight)->toBe(2.0);
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

    Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
        ->call('ship', $packingItems, $boxSize->id, '2.0', '10', '8', '6', false)
        ->assertRedirect();

    // Both packages should exist: the shipped one and the new one
    expect(Package::where('shipment_id', $shipment->id)->count())->toBe(2)
        ->and(Package::find($shipped->id))->not->toBeNull()
        ->and(Package::find($shipped->id)->status)->toBe(PackageStatus::Shipped);
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
    Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
        ->call('ship', $packingItems, $boxSize->id, '1.5', '10', '8', '6', true)
        ->assertRedirect();

    $package = Package::where('shipment_id', $shipment->id)->first();
    expect($package)->not->toBeNull()
        ->and($package->status)->toBe(PackageStatus::Unshipped);
});

it('shows client name when multi_client_enabled is true', function (): void {
    $client = Client::factory()->create(['name' => 'Acme Corp']);
    $shipment = Shipment::factory()->create(['client_id' => $client->id]);

    Setting::create(['key' => 'multi_client_enabled', 'value' => '1', 'type' => 'boolean', 'group' => 'general']);
    app(SettingsService::class)->clearCache();

    Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
        ->assertSet('multiClientEnabled', true)
        ->assertSet('clientName', 'Acme Corp');
})->after(fn () => app(SettingsService::class)->clearCache());

it('does not expose client name when multi_client_enabled is false', function (): void {
    $client = Client::factory()->create(['name' => 'Acme Corp']);
    $shipment = Shipment::factory()->create(['client_id' => $client->id]);

    Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
        ->assertSet('multiClientEnabled', false);
});

it('scopes product loading to the shipment client', function (): void {
    $clientA = Client::factory()->create();

    $product = Product::factory()->create([
        'client_id' => $clientA->id,
        'barcode' => '1111111111111',
    ]);

    // Shipment belongs to Client A — product is correctly from Client A
    $shipment = Shipment::factory()->create(['client_id' => $clientA->id]);
    ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
        ->assertSet('packingItems.0.barcode', '1111111111111');
});

it('does not load product when it belongs to a different client', function (): void {
    $clientA = Client::factory()->create();
    $clientB = Client::factory()->create();

    // Product belongs to Client B
    $product = Product::factory()->create([
        'client_id' => $clientB->id,
        'barcode' => '9999999999999',
    ]);

    // Shipment belongs to Client A but item points to Client B's product (data inconsistency)
    $shipment = Shipment::factory()->create(['client_id' => $clientA->id]);
    ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
        ->assertSet('packingItems.0.barcode', null);
});

it('addItemByScan dispatches scan-to-add-found when product barcode matches shipment client', function (): void {
    Setting::create(['key' => 'scan_to_add_enabled', 'value' => '1', 'type' => 'boolean', 'group' => 'general']);
    app(SettingsService::class)->clearCache();

    $client = Client::factory()->create();
    $product = Product::factory()->create(['client_id' => $client->id, 'barcode' => '111222333444']);
    $shipment = Shipment::factory()->create(['client_id' => $client->id]);
    // No shipment items — activates scan-to-add mode

    Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
        ->assertSet('scanToAddMode', true)
        ->call('addItemByScan', '111222333444')
        ->assertDispatched('scan-to-add-found');
})->after(fn () => app(SettingsService::class)->clearCache());

it('addItemByScan dispatches scan-to-add-found when product SKU matches', function (): void {
    Setting::create(['key' => 'scan_to_add_enabled', 'value' => '1', 'type' => 'boolean', 'group' => 'general']);
    app(SettingsService::class)->clearCache();

    $client = Client::factory()->create();
    $product = Product::factory()->create(['client_id' => $client->id, 'sku' => 'SKU-ALPHA-001']);
    $shipment = Shipment::factory()->create(['client_id' => $client->id]);

    Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
        ->call('addItemByScan', 'SKU-ALPHA-001')
        ->assertDispatched('scan-to-add-found');
})->after(fn () => app(SettingsService::class)->clearCache());

it('addItemByScan dispatches scan-to-add-not-found for a product belonging to a different client', function (): void {
    Setting::create(['key' => 'scan_to_add_enabled', 'value' => '1', 'type' => 'boolean', 'group' => 'general']);
    app(SettingsService::class)->clearCache();

    $clientA = Client::factory()->create();
    $clientB = Client::factory()->create();
    Product::factory()->create(['client_id' => $clientB->id, 'barcode' => '999888777666']);
    $shipment = Shipment::factory()->create(['client_id' => $clientA->id]);

    Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
        ->call('addItemByScan', '999888777666')
        ->assertDispatched('scan-to-add-not-found')
        ->assertNotDispatched('scan-to-add-found');
})->after(fn () => app(SettingsService::class)->clearCache());

it('addItemByScan does nothing when scan-to-add setting is disabled', function (): void {
    $client = Client::factory()->create();
    Product::factory()->create(['client_id' => $client->id, 'barcode' => '111111111111']);
    $shipment = Shipment::factory()->create(['client_id' => $client->id]);

    Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
        ->assertSet('scanToAddEnabled', false)
        ->call('addItemByScan', '111111111111')
        ->assertNotDispatched('scan-to-add-found')
        ->assertNotDispatched('scan-to-add-not-found');
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
        'status' => PackageStatus::Unshipped,
    ]);
    $orphanItem = $orphan->packageItems()->create([
        'shipment_item_id' => $shipmentItem->id,
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    // Simulate that this user's session tracks the orphan as in-progress
    Session::put('in_progress_package_id', $orphan->id);

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

    Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
        ->call('ship', $packingItems, $boxSize->id, '2.0', '10', '8', '6', false)
        ->assertRedirect();

    expect(PackageItem::find($orphanItem->id))->toBeNull();
});

it('scan-to-add mode is false when shipment has line items even when setting is enabled', function (): void {
    Setting::create(['key' => 'scan_to_add_enabled', 'value' => '1', 'type' => 'boolean', 'group' => 'general']);
    app(SettingsService::class)->clearCache();

    $shipment = Shipment::factory()->create();
    $product = Product::factory()->create(['barcode' => '1234567890123']);
    ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
        ->assertSet('scanToAddMode', false);
})->after(fn () => app(SettingsService::class)->clearCache());

it('scan-to-add mode is forced on when redirected from manual ship even if setting is disabled', function (): void {
    // scan_to_add_enabled is NOT set (defaults false)
    $shipment = Shipment::factory()->create();
    // No ShipmentItems — manual ship shipments have no line items

    Session::put('pack_scan_to_add_override', true);

    Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
        ->assertSet('scanToAddMode', true);
});

it('dispatches shipping-error when ship is called with no shipment loaded', function (): void {
    Livewire::test(Pack::class)
        ->call('ship', [], null, '1.5', '10', '8', '6', false)
        ->assertNotified('Invalid State')
        ->assertDispatched('shipping-error');
});

it('dispatches shipping-error when auto-ship fails at the carrier', function (): void {
    $boxSize = BoxSize::factory()->create();
    $product = Product::factory()->create(['barcode' => '1234567890123']);
    $shipment = Shipment::factory()->create();
    $shipmentItem = ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'transparency' => false,
    ]);

    $mock = Mockery::mock(PackageShippingWorkflow::class);
    $mock->shouldReceive('autoShip')->andReturn(PackageShippingResult::failed('Carrier Error', 'No rates available.'));
    app()->instance(PackageShippingWorkflow::class, $mock);

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

    Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
        ->call('ship', $packingItems, $boxSize->id, '1.5', '10', '8', '6', true)
        ->assertNotified('Carrier Error')
        ->assertDispatched('shipping-error');
});

// --- picking gate ---

it('blocks packing when picking is required and the shipment is not picked', function (): void {
    app(SettingsService::class)->set('picking_enabled', true);
    app(SettingsService::class)->set('require_picking_before_shipping', true);

    $shipment = Shipment::factory()->create(['picking_status' => PickingStatus::Pending]);

    Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
        ->assertSet('shipment', null)
        ->assertRedirect('/pack')
        ->assertNotified();
});

it('allows packing a picked shipment when picking is required', function (): void {
    app(SettingsService::class)->set('picking_enabled', true);
    app(SettingsService::class)->set('require_picking_before_shipping', true);

    $shipment = Shipment::factory()->create(['picking_status' => PickingStatus::Picked]);

    Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
        ->assertSet('shipment.id', $shipment->id)
        ->assertNoRedirect();
});

it('allows packing an unpicked shipment when picking is not required before shipping', function (): void {
    app(SettingsService::class)->set('picking_enabled', true);
    app(SettingsService::class)->set('require_picking_before_shipping', false);

    $shipment = Shipment::factory()->create(['picking_status' => PickingStatus::Pending]);

    Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
        ->assertSet('shipment.id', $shipment->id)
        ->assertNoRedirect();
});

// --- FBA gate ---

it('blocks packing an Amazon-fulfilled (FBA) shipment', function (): void {
    $shipment = Shipment::factory()->create([
        'metadata' => ['amazon_fulfilled_by' => 'AMAZON'],
    ]);

    Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
        ->assertSet('shipment', null)
        ->assertRedirect('/pack')
        ->assertNotified();
});

it('allows packing a merchant-fulfilled Amazon shipment', function (): void {
    $shipment = Shipment::factory()->create([
        'metadata' => ['amazon_fulfilled_by' => 'MERCHANT'],
    ]);

    Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
        ->assertSet('shipment.id', $shipment->id);
});
