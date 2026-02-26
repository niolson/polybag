<?php

namespace Database\Factories;

use App\Models\Package;
use App\Models\RateQuote;
use Illuminate\Database\Eloquent\Factories\Factory;

class RateQuoteFactory extends Factory
{
    protected $model = RateQuote::class;

    public function definition(): array
    {
        return [
            'package_id' => Package::factory(),
            'carrier' => fake()->randomElement(['USPS', 'FedEx', 'UPS']),
            'service_code' => fake()->randomElement(['PRIORITY', 'GROUND', 'EXPRESS']),
            'service_name' => fake()->randomElement(['Priority Mail', 'Ground', 'Express Saver']),
            'quoted_price' => fake()->randomFloat(2, 5, 50),
            'quoted_delivery_date' => fake()->optional()->date(),
            'transit_time' => fake()->optional()->randomElement(['1 day', '2-3 days', '5-7 days']),
            'selected' => false,
            'created_at' => now(),
        ];
    }

    public function selected(): static
    {
        return $this->state(fn () => [
            'selected' => true,
        ]);
    }
}
