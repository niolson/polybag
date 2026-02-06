<?php

namespace App\DataTransferObjects\Shipping;

use App\Models\Package;

readonly class RateRequest
{
    /**
     * @param  array<PackageData>  $packages
     */
    public function __construct(
        public string $originZip,
        public string $destinationZip,
        public string $destinationCountry = 'US',
        public ?string $destinationCity = null,
        public ?string $destinationState = null,
        public ?bool $residential = null,
        public array $packages = [],
    ) {}

    public static function fromPackage(Package $package): self
    {
        $shipment = $package->shipment;

        return new self(
            originZip: config('shipping.origin_zip', '98072'),
            destinationZip: $shipment->validated_zip ?? $shipment->zip,
            destinationCountry: $shipment->validated_country ?? $shipment->country ?? 'US',
            destinationCity: $shipment->validated_city ?? $shipment->city,
            destinationState: $shipment->validated_state ?? $shipment->state,
            residential: $shipment->validated_residential ?? $shipment->residential,
            packages: [PackageData::fromPackage($package)],
        );
    }
}
