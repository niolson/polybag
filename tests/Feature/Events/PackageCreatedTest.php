<?php

use App\Enums\Role;
use App\Events\PackageCreated;
use App\Filament\Pages\Pack;
use App\Models\BoxSize;
use App\Models\Package;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

it('dispatches PackageCreated when a package is created via Pack page', function (): void {
    Event::fake([PackageCreated::class]);

    $this->actingAs(User::factory()->create(['role' => Role::Admin]));

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

    Livewire::test(Pack::class, ['shipment_id' => $shipment->shipment_reference])
        ->call('ship', $packingItems, $boxSize->id, '1.5', '10', '8', '6', false)
        ->assertRedirect();

    $package = Package::where('shipment_id', $shipment->id)->first();

    Event::assertDispatched(PackageCreated::class, function (PackageCreated $event) use ($package, $shipment): bool {
        return $event->package->id === $package->id
            && $event->shipment->id === $shipment->id;
    });
});
