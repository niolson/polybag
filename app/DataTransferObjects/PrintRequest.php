<?php

namespace App\DataTransferObjects;

use App\DataTransferObjects\Shipping\ShipResponse;
use App\Models\Package;

readonly class PrintRequest
{
    public function __construct(
        public string $label,
        public string $orientation,
        public string $format,
        public ?int $dpi,
    ) {}

    public static function fromShipResponse(ShipResponse $response): self
    {
        return new self(
            label: $response->labelData,
            orientation: $response->labelOrientation ?? 'portrait',
            format: $response->labelFormat ?? 'pdf',
            dpi: $response->labelDpi,
        );
    }

    public static function fromPackage(Package $package): self
    {
        return new self(
            label: $package->label_data,
            orientation: $package->label_orientation ?? 'portrait',
            format: $package->label_format ?? 'pdf',
            dpi: $package->label_dpi,
        );
    }
}
