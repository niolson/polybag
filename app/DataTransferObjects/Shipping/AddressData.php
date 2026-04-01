<?php

namespace App\DataTransferObjects\Shipping;

use App\Models\Location;
use App\Models\Shipment;
use App\Services\PhoneParserService;

readonly class AddressData
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $streetAddress,
        public string $city,
        public ?string $stateOrProvince,
        public ?string $postalCode,
        public string $country = 'US',
        public ?string $streetAddress2 = null,
        public ?string $company = null,
        public ?string $phone = null,
        public ?string $email = null,
        public ?string $phoneExtension = null,
    ) {}

    public static function fromShipment(Shipment $shipment): self
    {
        return new self(
            firstName: $shipment->first_name ?? '',
            lastName: $shipment->last_name ?? '',
            streetAddress: $shipment->validated_address1 ?? $shipment->address1,
            streetAddress2: $shipment->validated_address2 ?? $shipment->address2,
            city: $shipment->validated_city ?? $shipment->city,
            stateOrProvince: $shipment->validated_state_or_province ?? $shipment->state_or_province,
            postalCode: $shipment->validated_postal_code ?? $shipment->postal_code,
            country: $shipment->validated_country ?? $shipment->country ?? 'US',
            company: $shipment->validated_company ?? $shipment->company,
            phone: PhoneParserService::nationalDigits($shipment->phone_e164, $shipment->validated_country ?? $shipment->country ?? 'US'),
            email: $shipment->email,
            phoneExtension: $shipment->phone_extension,
        );
    }

    public static function fromLocation(Location $location): self
    {
        return new self(
            firstName: $location->first_name,
            lastName: $location->last_name,
            streetAddress: $location->address1,
            streetAddress2: $location->address2,
            city: $location->city,
            stateOrProvince: $location->state_or_province,
            postalCode: $location->postal_code,
            country: $location->country,
            company: $location->company,
            phone: PhoneParserService::nationalDigits($location->phone_e164, $location->country),
        );
    }

    public static function fromConfig(): self
    {
        $location = Location::getDefault();

        if (! $location) {
            throw new \RuntimeException('No default location configured. Go to Settings > Locations and set a default location.');
        }

        return self::fromLocation($location);
    }
}
