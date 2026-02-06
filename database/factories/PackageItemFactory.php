<?php

namespace Database\Factories;

use App\Models\Package;
use App\Models\PackageItem;
use App\Models\Product;
use App\Models\ShipmentItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class PackageItemFactory extends Factory
{
    protected $model = PackageItem::class;

    public function definition(): array
    {
        return [
            'package_id' => Package::factory(),
            'shipment_item_id' => ShipmentItem::factory(),
            'product_id' => Product::factory(),
            'quantity' => fake()->numberBetween(1, 5),
            'transparency_codes' => null,
        ];
    }

    public function withTransparencyCodes(int $count = 1): static
    {
        return $this->state(fn () => [
            'transparency_codes' => collect(range(1, $count))
                ->map(fn () => fake()->regexify('[A-Z0-9]{29}'))
                ->toArray(),
        ]);
    }
}
