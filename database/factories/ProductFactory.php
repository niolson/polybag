<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'sku' => fake()->unique()->regexify('[A-Z]{3}[0-9]{4}'),
            'name' => fake()->words(3, true),
            'barcode' => fake()->ean13(),
            'description' => fake()->words(3, true),
            'weight' => fake()->randomFloat(2, 0.1, 10),
        ];
    }
}
