<?php

namespace Database\Factories;

use App\Enums\BoxSizeType;
use App\Models\BoxSize;
use Illuminate\Database\Eloquent\Factories\Factory;

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
}
