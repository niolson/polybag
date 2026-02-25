<?php

namespace App\Events;

use App\Models\Package;
use App\Models\Shipment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PackageCancelled
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Package $package,
        public Shipment $shipment,
    ) {}
}
