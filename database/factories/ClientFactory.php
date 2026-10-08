<?php

namespace Database\Factories;

use App\Enums\DutiesTerms;
use App\Models\Client;
use App\Models\ClientTaxRegistration;
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

    /**
     * A client that has chosen DDP for every EU destination, and nothing else.
     */
    public function ddpToEu(): static
    {
        return $this->withDutiesPolicy([Client::DUTIES_POLICY_EU => DutiesTerms::Ddp->value]);
    }

    /**
     * @param  array<string, string>  $policy  destination (`EU` or a country code) to `ddp`/`ddu`
     */
    public function withDutiesPolicy(array $policy): static
    {
        return $this->state(fn (): array => ['duties_policy' => $policy]);
    }

    /**
     * A client holding a synthetic IOSS registration.
     */
    public function withIossRegistration(): static
    {
        return $this->has(ClientTaxRegistration::factory()->ioss(), 'taxRegistrations');
    }

    /**
     * A client with an exporter EIN, for parcels that carry an export ITN.
     */
    public function withExporterEin(string $ein = '123456789'): static
    {
        return $this->state(fn (): array => ['exporter_ein' => $ein]);
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
