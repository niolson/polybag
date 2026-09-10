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
        /** Package this label belongs to, so the browser can report the print back. */
        public ?int $packageId = null,
        /**
         * Base64 of a customs document to print after the label, where the source
         * returned one separately. Letter-sized paper, so it goes to the report
         * printer rather than through the 4x6 label path.
         */
        public ?string $customsForm = null,
    ) {}

    public static function fromShipResponse(ShipResponse $response, ?Package $package = null): self
    {
        return new self(
            label: $response->labelData,
            orientation: $response->labelOrientation ?? 'portrait',
            format: $response->labelFormat ?? 'pdf',
            dpi: $response->labelDpi,
            packageId: $package?->id,
            customsForm: $response->customsFormData,
        );
    }

    public static function fromPackage(Package $package): self
    {
        return new self(
            label: $package->label_data,
            orientation: $package->label_orientation ?? 'portrait',
            format: $package->label_format ?? 'pdf',
            dpi: $package->label_dpi,
            packageId: $package->id,
            customsForm: $package->customs_form_data,
        );
    }
}
