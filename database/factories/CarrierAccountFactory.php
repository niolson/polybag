<?php

namespace Database\Factories;

use App\Models\Carrier;
use App\Models\CarrierAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CarrierAccount>
 */
class CarrierAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            // Only a direct carrier can have an account, and carrier names are
            // unique, so reuse the row when the test already made it.
            'carrier_id' => fn (): int => Carrier::firstOrCreate([
                'name' => fake()->randomElement([Carrier::USPS, Carrier::FEDEX, Carrier::UPS]),
            ])->id,
            'name' => fake()->words(3, true),
            'active' => true,
        ];
    }

    public function usps(): static
    {
        return $this->state(fn () => [
            'carrier_id' => fn (): int => Carrier::firstOrCreate(['name' => Carrier::USPS], ['pickup_cutoff_hour' => 20])->id,
            'name' => 'USPS Account',
            'credentials' => [
                'crid' => fake()->numerify('########'),
                'mid' => fake()->numerify('#########'),
                'eps_account' => fake()->numerify('########'),
            ],
        ]);
    }

    public function fedex(): static
    {
        return $this->state(fn () => [
            'carrier_id' => fn (): int => Carrier::firstOrCreate(['name' => Carrier::FEDEX])->id,
            'name' => 'FedEx Account',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
