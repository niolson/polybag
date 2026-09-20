<?php

namespace App\DataTransferObjects\Shipping;

use App\Enums\BoxSizeType;
use App\Enums\CarrierPackaging;
use App\Exceptions\InvalidPackageDimensionsException;
use App\Models\Package;

readonly class PackageData
{
    /**
     * @param  BoxSizeType|null  $boxType  The physical form of the packaging — ADR-0005's first axis.
     * @param  CarrierPackaging|null  $carrierPackaging  Whose packaging it is when it is not the packer's own — the second axis. Null means the packer's own packaging of whatever form `$boxType` says.
     */
    public function __construct(
        public float $weight,
        public float $length,
        public float $width,
        public float $height,
        public ?BoxSizeType $boxType = null,
        public ?CarrierPackaging $carrierPackaging = null,
    ) {}

    public static function fromPackage(Package $package): self
    {
        return new self(
            weight: (float) $package->weight,
            length: (float) $package->length,
            width: (float) $package->width,
            height: (float) $package->height,
            boxType: $package->boxSize?->type,
            carrierPackaging: $package->boxSize?->carrier_packaging,
        );
    }

    /**
     * Carrier APIs rate dimensions in whole inches. Round every measured side
     * upward so their integer conversion cannot understate the Package size.
     *
     * @return array{length: int, width: int, height: int}
     */
    public function dimensionsInWholeInches(): array
    {
        $dimensions = [
            'length' => $this->length,
            'width' => $this->width,
            'height' => $this->height,
        ];

        foreach ($dimensions as $dimension) {
            if (! is_finite($dimension) || $dimension <= 0) {
                throw new InvalidPackageDimensionsException('Package dimensions must be finite positive measurements.');
            }
        }

        return array_map(
            fn (float $dimension): int => (int) ceil($dimension),
            $dimensions,
        );
    }
}
