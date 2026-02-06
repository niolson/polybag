<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShipmentItemFactory extends Factory
{
    protected $model = ShipmentItem::class;

    public function definition(): array
    {
        return [
            'shipment_id' => Shipment::factory(),
            'product_id' => Product::factory(),
            'quantity' => fake()->numberBetween(1, 5),
            'value' => fake()->randomFloat(2, 5, 100),
            'transparency' => false,
        ];
    }

    public function withTransparency(): static
    {
        return $this->state(fn () => ['transparency' => true]);
    }
}
