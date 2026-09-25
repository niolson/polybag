<?php

namespace Database\Factories;

use App\Models\Carrier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Carrier>
 */
class CarrierFactory extends Factory
{
    protected $model = Carrier::class;

    public function definition(): array
    {
        // A custom carrier by default: names are unique, and a direct carrier
        // is asked for by its state.
        return [
            'name' => fake()->unique()->company().' Freight',
            'active' => true,
        ];
    }

    public function usps(): static
    {
        return $this->state(fn () => ['name' => Carrier::USPS, 'pickup_cutoff_hour' => 20]);
    }

    public function shopify(): static
    {
        return $this->state(fn () => ['name' => 'Shopify', 'pickup_cutoff_hour' => 20]);
    }

    public function fedex(): static
    {
        return $this->state(fn () => ['name' => Carrier::FEDEX]);
    }

    public function ups(): static
    {
        return $this->state(fn () => ['name' => Carrier::UPS]);
    }

    /**
     * A carrier the seeders own: its name is fixed.
     */
    public function system(): static
    {
        return $this->state(fn () => ['is_system' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
