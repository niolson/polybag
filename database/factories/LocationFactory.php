<?php

namespace Database\Factories;

use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
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
            // Within every carrier's limits (FedEx street 35, USPS city 28), which a
            // label is refused over rather than cut: a test wanting a long line sets one.
            'address1' => rtrim(mb_substr(fake()->streetAddress(), 0, 35)),
            'address2' => fake()->optional()->secondaryAddress(),
            'city' => rtrim(mb_substr(fake()->city(), 0, 28)),
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
