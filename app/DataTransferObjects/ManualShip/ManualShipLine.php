<?php

namespace App\DataTransferObjects\ManualShip;

/**
 * One item entered on Manual Ship: picked from the catalog (a product ID) or
 * typed in. Only a typed-in line's description, SKU, weight, HS code and origin
 * are read; a catalog line's come from its Product.
 */
final readonly class ManualShipLine
{
    /**
     * @param  float|null  $unitValue  In the Shipment's currency
     */
    public function __construct(
        public ?int $productId,
        public int $quantity,
        public ?float $unitValue = null,
        public ?string $description = null,
        public ?string $sku = null,
        public ?float $weight = null,
        public ?string $hsTariffNumber = null,
        public ?string $countryOfOrigin = null,
    ) {}

    /**
     * @param  array<string, mixed>  $state  One row of the Manual Ship items repeater
     */
    public static function fromFormState(array $state): self
    {
        return new self(
            productId: filled($state['product_id'] ?? null) ? (int) $state['product_id'] : null,
            quantity: (int) $state['quantity'],
            unitValue: self::nullableFloat($state['value'] ?? null),
            description: self::nullableString($state['description'] ?? null),
            sku: self::nullableString($state['sku'] ?? null),
            weight: self::nullableFloat($state['weight'] ?? null),
            hsTariffNumber: self::nullableString($state['hs_tariff_number'] ?? null),
            countryOfOrigin: ($origin = self::nullableString($state['country_of_origin'] ?? null)) !== null ? mb_strtoupper($origin) : null,
        );
    }

    public function isFreehand(): bool
    {
        return $this->productId === null;
    }

    private static function nullableFloat(mixed $value): ?float
    {
        return filled($value) ? (float) $value : null;
    }

    private static function nullableString(mixed $value): ?string
    {
        return filled($value) ? trim((string) $value) : null;
    }
}
