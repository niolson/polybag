<?php

namespace App\DataTransferObjects\Shipping;

use App\Models\PackageItem;

readonly class CustomsItem
{
    public function __construct(
        public string $description,
        public int $quantity,
        public float $unitValue,
        public float $weight,
        public ?string $hsTariffNumber = null,
        public ?string $countryOfOrigin = null,
    ) {}

    public static function fromPackageItem(PackageItem $packageItem): self
    {
        $product = $packageItem->product;
        $shipmentItem = $packageItem->shipmentItem;

        return new self(
            description: $product->description ?? $product->name,
            quantity: $packageItem->quantity,
            unitValue: (float) ($shipmentItem?->value ?? 1),
            weight: (float) ($product->weight ?? 0.1),
            hsTariffNumber: $product->hs_tariff_number,
            countryOfOrigin: $product->country_of_origin ?? 'US',
        );
    }
}
