<?php

namespace Database\Factories;

use App\Models\Product;
use App\Services\ClientContext;
use App\Support\Gtin;
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
            'manufacturer_part_number' => fake()->bothify('MPN-####-??'),
            'gtin' => self::syntheticGtin(),
            'country_of_origin' => 'US',
            'hs_tariff_number' => '610910',
        ];
    }

    /**
     * A GTIN-13 with a valid check digit in GS1's restricted-circulation range
     * (prefix 20–29), which is never assigned to a real trade item.
     */
    public static function syntheticGtin(): string
    {
        $digits = '2'.fake()->numerify('###########');

        return $digits.Gtin::checkDigit($digits);
    }

    /**
     * Declared by the seller as media, so it qualifies for USPS Media Mail.
     */
    public function media(): static
    {
        return $this->state(fn () => ['is_media' => true]);
    }
}
