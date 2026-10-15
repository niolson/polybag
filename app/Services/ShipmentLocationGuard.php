<?php

namespace App\Services;

use App\Models\Shipment;
use App\Models\User;

class ShipmentLocationGuard
{
    public function errorFor(Shipment $shipment, ?User $operator): ?string
    {
        $shipment->loadMissing('location');

        if ($shipment->location !== null && ! $shipment->location->active) {
            return "Shipment {$shipment->shipment_reference} is assigned to inactive location {$shipment->location->name}. Ask an administrator to reactivate the location or move the shipment.";
        }

        if ($shipment->location_id === null || $operator?->location_id === null) {
            return null;
        }

        $operator->loadMissing('location');

        if ($shipment->location_id !== $operator->location_id) {
            $shipmentLocationName = optional($shipment->location)->name ?? 'Unassigned';
            $operatorLocationName = optional($operator->location)->name ?? 'Unassigned';

            return "Shipment {$shipment->shipment_reference} is assigned to {$shipmentLocationName}, but your operator location is {$operatorLocationName}. Switch workstations or ask an administrator to update your location.";
        }

        return null;
    }
}
