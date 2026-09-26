<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'is_default' => false,
            'active' => true,
        ];
    }

    public function default(): static
    {
        return $this->state(fn () => [
            'name' => 'Default Client',
            'is_default' => true,
            'active' => true,
        ]);
    }
}
