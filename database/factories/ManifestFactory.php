<?php

namespace Database\Factories;

use App\Models\Manifest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Manifest>
 */
class ManifestFactory extends Factory
{
    protected $model = Manifest::class;

    public function definition(): array
    {
        return [
            'carrier' => 'USPS',
            'manifest_number' => fake()->numerify('##########'),
            'image' => null,
            'manifest_date' => now()->toDateString(),
            'package_count' => fake()->numberBetween(1, 20),
        ];
    }

    public function usps(): static
    {
        return $this->state(fn () => [
            'carrier' => 'USPS',
        ]);
    }

    public function fedex(): static
    {
        return $this->state(fn () => [
            'carrier' => 'FedEx',
        ]);
    }

    public function withImage(): static
    {
        return $this->state(fn () => [
            'image' => base64_encode('fake-pdf-content'),
        ]);
    }
}
