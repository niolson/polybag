<?php

namespace Database\Factories;

use App\Models\AmazonCatalogLookup;
use App\Models\DataSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AmazonCatalogLookup>
 */
class AmazonCatalogLookupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'data_source_id' => DataSource::factory()->amazon(),
            'sku' => 'SYN-'.fake()->unique()->numerify('######'),
            'asin' => 'B0SYN'.fake()->unique()->numerify('#####'),
        ];
    }
}
