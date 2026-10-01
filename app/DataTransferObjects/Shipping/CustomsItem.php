<?php

namespace App\DataTransferObjects\Shipping;

use App\Models\PackageItem;
use App\Support\Gtin;

readonly class CustomsItem
{
    /**
     * The three identifiers are the EU product identifiers, each null when the
     * product has nothing to say: the M-PID (the seller's SKU), the NS-PID (the
     * manufacturer's part number) and the S-PID (a GTIN).
     */
    public function __construct(
        public string $description,
        public int $quantity,
        public float $unitValue,
        public float $weight,
        public ?string $hsTariffNumber = null,
        public ?string $countryOfOrigin = null,
        public ?string $merchantProductId = null,
        public ?string $manufacturerProductId = null,
        public ?string $standardProductId = null,
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
            merchantProductId: self::blankToNull($product->sku),
            manufacturerProductId: self::blankToNull($product->manufacturer_part_number),
            standardProductId: self::standardProductIdFor($product->gtin, $product->barcode),
        );
    }

    /**
     * This item with another unit weight and everything else carried over, so a
     * field added later cannot be dropped by a caller rebuilding the item by hand.
     */
    public function withWeight(float $weight): self
    {
        return new self(...array_merge(get_object_vars($this), ['weight' => $weight]));
    }

    /**
     * The product's GTIN, else its scan barcode when that is a GTIN. A barcode
     * that is not one — an internal Code 128 label — is never declared as one.
     */
    private static function standardProductIdFor(?string $gtin, ?string $barcode): ?string
    {
        foreach ([$gtin, $barcode] as $candidate) {
            if (Gtin::isValid($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function blankToNull(?string $value): ?string
    {
        return filled($value) ? trim($value) : null;
    }
}
