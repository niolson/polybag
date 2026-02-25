<?php

use App\Events\PackageCancelled;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use Illuminate\Support\Facades\Event;

it('dispatches PackageCancelled when clearShipping is called', function (): void {
    Event::fake([PackageCancelled::class]);

    $shipment = Shipment::factory()->create();
    ShipmentItem::factory()->create(['shipment_id' => $shipment->id]);
    $package = Package::factory()->shipped()->create(['shipment_id' => $shipment->id]);

    $package->clearShipping();

    Event::assertDispatched(PackageCancelled::class, function (PackageCancelled $event) use ($package, $shipment): bool {
        return $event->package->id === $package->id
            && $event->shipment->id === $shipment->id;
    });
});
