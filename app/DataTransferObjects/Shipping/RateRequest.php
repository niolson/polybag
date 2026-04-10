<?php

namespace App\DataTransferObjects\Shipping;

use App\Models\Package;
use Carbon\CarbonImmutable;

readonly class RateRequest
{
    /**
     * @param  array<PackageData>  $packages
     */
    public function __construct(
        public string $originPostalCode,
        public string $destinationPostalCode,
        public string $originCountry = 'US',
        public string $destinationCountry = 'US',
        public ?string $destinationCity = null,
        public ?string $destinationStateOrProvince = null,
        public ?bool $residential = null,
        public array $packages = [],
        public bool $saturdayDelivery = false,
        public ?int $locationId = null,
        public ?CarbonImmutable $shipDate = null,
    ) {}

    public static function fromPackage(Package $package): self
    {
        $shipment = $package->shipment;
        $origin = AddressData::fromConfig();

        if ($package->location) {
            $origin = AddressData::fromLocation($package->location);
        } elseif ($package->location_id) {
            $package->load('location');
            if ($package->location) {
                $origin = AddressData::fromLocation($package->location);
            }
        }

        $shippingMethod = $shipment->shippingMethod;

        return new self(
            originPostalCode: $origin->postalCode ?? '',
            destinationPostalCode: $shipment->validated_postal_code ?? $shipment->postal_code,
            originCountry: $origin->country,
            destinationCountry: $shipment->validated_country ?? $shipment->country ?? 'US',
            destinationCity: $shipment->validated_city ?? $shipment->city,
            destinationStateOrProvince: $shipment->validated_state_or_province ?? $shipment->state_or_province,
            residential: $shipment->validated_residential ?? $shipment->residential,
            packages: [PackageData::fromPackage($package)],
            saturdayDelivery: (bool) $shippingMethod?->hasDefaultService('saturday_delivery'),
            locationId: $package->location_id,
        );
    }

    public function withShipDate(CarbonImmutable $date): self
    {
        return new self(
            originPostalCode: $this->originPostalCode,
            destinationPostalCode: $this->destinationPostalCode,
            originCountry: $this->originCountry,
            destinationCountry: $this->destinationCountry,
            destinationCity: $this->destinationCity,
            destinationStateOrProvince: $this->destinationStateOrProvince,
            residential: $this->residential,
            packages: $this->packages,
            saturdayDelivery: $this->saturdayDelivery,
            locationId: $this->locationId,
            shipDate: $date,
        );
    }
}
