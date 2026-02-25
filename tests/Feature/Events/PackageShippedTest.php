<?php

use App\DataTransferObjects\Shipping\ShipResponse;
use App\Events\PackageShipped;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use Illuminate\Support\Facades\Event;

it('dispatches PackageShipped when markShipped is called', function (): void {
    Event::fake([PackageShipped::class]);

    $shipment = Shipment::factory()->create();
    ShipmentItem::factory()->create(['shipment_id' => $shipment->id]);
    $package = Package::factory()->create(['shipment_id' => $shipment->id]);

    $response = ShipResponse::success(
        trackingNumber: '9400111899223456789012',
        cost: 8.50,
        carrier: 'USPS',
        service: 'USPS_GROUND_ADVANTAGE',
        labelData: base64_encode('PDF content'),
    );

    $package->markShipped($response);

    Event::assertDispatched(PackageShipped::class, function (PackageShipped $event) use ($package, $shipment): bool {
        return $event->package->id === $package->id
            && $event->shipment->id === $shipment->id;
    });
});
