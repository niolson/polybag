<?php

namespace App\DataTransferObjects\Shipping;

use App\Enums\BoxSizeType;
use App\Enums\FedexPackageType;
use App\Models\Package;

readonly class PackageData
{
    public function __construct(
        public float $weight,
        public float $length,
        public float $width,
        public float $height,
        public ?BoxSizeType $boxType = null,
        public ?FedexPackageType $fedexPackageType = null,
    ) {}

    public static function fromPackage(Package $package): self
    {
        return new self(
            weight: (float) $package->weight,
            length: (float) $package->length,
            width: (float) $package->width,
            height: (float) $package->height,
            boxType: $package->boxSize?->type,
            fedexPackageType: $package->boxSize?->fedex_package_type,
        );
    }
}
