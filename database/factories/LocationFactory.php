<?php

namespace Database\Factories;

use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

class LocationFactory extends Factory
{
    protected $model = Location::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company().' Warehouse',
            'company' => fake()->optional()->company(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'address1' => fake()->streetAddress(),
            'address2' => fake()->optional()->secondaryAddress(),
            'city' => fake()->city(),
            'state_or_province' => fake()->stateAbbr(),
            'postal_code' => fake()->postcode(),
            'country' => 'US',
            'timezone' => 'America/New_York',
            'fedex_hub_id' => null,
            'phone' => fake()->optional()->phoneNumber(),
            'is_default' => false,
            'active' => true,
        ];
    }

    public function default(): static
    {
        return $this->state(fn () => [
            'is_default' => true,
        ]);
    }
}
