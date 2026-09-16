<?php

namespace Database\Factories;

use App\Enums\BoxSizeType;
use App\Enums\CarrierPackaging;
use App\Models\BoxSize;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoxSize>
 */
class BoxSizeFactory extends Factory
{
    protected $model = BoxSize::class;

    public function definition(): array
    {
        return [
            'label' => fake()->words(2, true),
            'code' => fake()->unique()->regexify('[A-Z][0-9]'),
            'type' => fake()->randomElement(BoxSizeType::cases()),
            'height' => fake()->randomFloat(2, 2, 20),
            'width' => fake()->randomFloat(2, 2, 20),
            'length' => fake()->randomFloat(2, 2, 20),
            'max_weight' => fake()->randomFloat(2, 10, 70),
            'empty_weight' => fake()->randomFloat(2, 0.1, 2),
        ];
    }

    /**
     * A box size that is a carrier's own packaging — ADR-0005's second axis.
     */
    public function carrierPackaging(CarrierPackaging $packaging): static
    {
        return $this->state(fn (array $attributes): array => [
            'carrier_packaging' => $packaging,
        ]);
    }
}
