<?php

namespace App\DataTransferObjects\Shipping;

use App\Models\Package;

readonly class RateRequest
{
    /**
     * @param  array<PackageData>  $packages
     */
    public function __construct(
        public string $originPostalCode,
        public string $destinationPostalCode,
        public string $destinationCountry = 'US',
        public ?string $destinationCity = null,
        public ?string $destinationStateOrProvince = null,
        public ?bool $residential = null,
        public array $packages = [],
    ) {}

    public static function fromPackage(Package $package): self
    {
        $shipment = $package->shipment;

        return new self(
            originPostalCode: config('shipping.origin_postal_code', '98072'),
            destinationPostalCode: $shipment->validated_postal_code ?? $shipment->postal_code,
            destinationCountry: $shipment->validated_country ?? $shipment->country ?? 'US',
            destinationCity: $shipment->validated_city ?? $shipment->city,
            destinationStateOrProvince: $shipment->validated_state_or_province ?? $shipment->state_or_province,
            residential: $shipment->validated_residential ?? $shipment->residential,
            packages: [PackageData::fromPackage($package)],
        );
    }
}
