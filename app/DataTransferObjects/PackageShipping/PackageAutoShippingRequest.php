<?php

namespace App\DataTransferObjects\PackageShipping;

readonly class PackageAutoShippingRequest
{
    public function __construct(
        public string $labelFormat = 'pdf',
        public ?int $labelDpi = null,
        public ?int $userId = null,
        public bool $cleanupOnFailure = true,
        // See PackageShippingRequest::$hasReportPrinter.
        public bool $hasReportPrinter = false,
        // A batch chose the Shipment earlier, while it was open, and no person
        // is choosing it now. A Shipment shipped since is then refused: buying
        // for a shipped order is a manager's reship, decided on Pack or Ship.
        public bool $fromBatch = false,
    ) {}
}
