<?php

namespace App\Events;

use App\Models\Shipment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ShipmentUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Shipment $shipment,
    ) {}
}
