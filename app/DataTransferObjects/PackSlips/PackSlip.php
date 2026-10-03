<?php

namespace App\DataTransferObjects\PackSlips;

use App\Models\Client;
use App\Models\Shipment;

/**
 * One slip as the pack slip view draws it: the Shipment with its items loaded, and
 * the branding resolved from its own Client.
 */
final readonly class PackSlip
{
    public function __construct(
        public Shipment $shipment,
        public ?Client $client,
        public ?string $logoDataUri,
        public ?string $toteCode,
        public string $scanCode,
    ) {}
}
