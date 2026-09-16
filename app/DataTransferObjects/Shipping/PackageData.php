<?php

namespace App\DataTransferObjects\Shipping;

use App\Enums\BoxSizeType;
use App\Enums\CarrierPackaging;
use App\Enums\FedexPackageType;
use App\Models\Package;

readonly class PackageData
{
    /**
     * @param  BoxSizeType|null  $boxType  The physical form of the packaging — ADR-0005's first axis.
     * @param  CarrierPackaging|null  $carrierPackaging  Whose packaging it is when it is not the packer's own — the second axis. Null means the packer's own packaging of whatever form `$boxType` says. Always null until `box_sizes.carrier_packaging` exists (packaging-form-and-carrier-identity/03).
     */
    public function __construct(
        public float $weight,
        public float $length,
        public float $width,
        public float $height,
        public ?BoxSizeType $boxType = null,
        public ?CarrierPackaging $carrierPackaging = null,
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
            carrierPackaging: null,
            fedexPackageType: $package->boxSize?->fedex_package_type,
        );
    }
}
