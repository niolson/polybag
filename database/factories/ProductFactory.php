<?php

namespace Database\Factories;

use App\Models\Product;
use App\Services\ClientContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'client_id' => app(ClientContext::class)->id(),
            'sku' => fake()->unique()->regexify('[A-Z]{3}[0-9]{4}'),
            'name' => fake()->words(3, true),
            'barcode' => fake()->ean13(),
            'description' => fake()->words(3, true),
            'weight' => fake()->randomFloat(2, 0.1, 10),
        ];
    }

    /**
     * Declared by the seller as media, so it qualifies for USPS Media Mail.
     */
    public function media(): static
    {
        return $this->state(fn () => ['is_media' => true]);
    }
}
