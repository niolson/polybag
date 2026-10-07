<?php

namespace Database\Factories;

use App\Enums\TaxRegistrationRegime;
use App\Models\Client;
use App\Models\ClientTaxRegistration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Registration numbers here are synthetic: each matches its regime's format and
 * belongs to no one.
 *
 * @extends Factory<ClientTaxRegistration>
 */
class ClientTaxRegistrationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'regime' => TaxRegistrationRegime::Ioss,
            'number' => 'IM0000000001',
        ];
    }

    public function ioss(): static
    {
        return $this->state(fn (): array => [
            'regime' => TaxRegistrationRegime::Ioss,
            'number' => 'IM0000000001',
        ]);
    }

    public function ukVat(): static
    {
        return $this->state(fn (): array => [
            'regime' => TaxRegistrationRegime::UkVat,
            'number' => 'GB000000001',
        ]);
    }

    public function voec(): static
    {
        return $this->state(fn (): array => [
            'regime' => TaxRegistrationRegime::Voec,
            'number' => '0000001',
        ]);
    }

    public function arn(): static
    {
        return $this->state(fn (): array => [
            'regime' => TaxRegistrationRegime::Arn,
            'number' => '000000000001',
        ]);
    }
}
