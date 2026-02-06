<?php

namespace Database\Factories;

use App\Models\Carrier;
use Illuminate\Database\Eloquent\Factories\Factory;

class CarrierFactory extends Factory
{
    protected $model = Carrier::class;

    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['USPS', 'FedEx', 'UPS']),
            'active' => true,
        ];
    }

    public function usps(): static
    {
        return $this->state(fn () => ['name' => 'USPS']);
    }

    public function fedex(): static
    {
        return $this->state(fn () => ['name' => 'FedEx']);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
