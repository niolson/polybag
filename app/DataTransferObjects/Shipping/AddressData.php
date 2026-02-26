<?php

namespace App\DataTransferObjects\Shipping;

use App\Models\Shipment;
use App\Services\SettingsService;

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
            phone: $shipment->phone,
            email: $shipment->email,
            phoneExtension: $shipment->phone_extension,
        );
    }

    public static function fromConfig(): self
    {
        return new self(
            firstName: app(SettingsService::class)->get('from_address.first_name', config('shipping.from_address.first_name', 'Shipping')),
            lastName: app(SettingsService::class)->get('from_address.last_name', config('shipping.from_address.last_name', 'Center')),
            streetAddress: app(SettingsService::class)->get('from_address.street', config('shipping.from_address.street', '')),
            streetAddress2: app(SettingsService::class)->get('from_address.street2', config('shipping.from_address.street2')),
            city: app(SettingsService::class)->get('from_address.city', config('shipping.from_address.city', '')),
            stateOrProvince: app(SettingsService::class)->get('from_address.state_or_province', config('shipping.from_address.state', '')),
            postalCode: app(SettingsService::class)->get('from_address.postal_code', config('shipping.origin_postal_code', '98072')),
            country: 'US',
            company: app(SettingsService::class)->get('from_address.company', config('shipping.from_address.company')),
            phone: app(SettingsService::class)->get('from_address.phone', config('shipping.from_address.phone')),
        );
    }
}
