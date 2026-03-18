<?php

namespace App\Jobs;

use App\Models\Shipment;
use App\Services\AddressValidationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ValidateAddressJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** @var int[] */
    public array $backoff = [5, 15];

    public function __construct(
        public int $shipmentId,
    ) {
        $this->onQueue('low');
    }

    public function handle(AddressValidationService $service): void
    {
        $shipment = Shipment::find($this->shipmentId);

        if (! $shipment) {
            return;
        }

        $service->validate($shipment);
    }
}
