<?php

namespace Database\Factories;

use App\Models\CarrierAccount;
use App\Models\CarrierAccountScope;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CarrierAccountScope>
 */
class CarrierAccountScopeFactory extends Factory
{
    /**
     * `carrier_id` is left to the scope's `saving` hook, which derives it from
     * the target.
     */
    public function definition(): array
    {
        return [
            'carrier_account_id' => CarrierAccount::factory(),
            'data_source_id' => null,
            'location_id' => null,
            'client_id' => null,
            'rate_shop' => false,
        ];
    }

    public function forAccount(CarrierAccount $account): static
    {
        return $this->state(fn () => [
            'carrier_account_id' => $account->id,
            'carrier_id' => $account->carrier_id,
        ]);
    }

    /**
     * A row that routes off-Amazon Amazon Shipping to an Amazon connection. A
     * connection with a Client is scoped to it unless a state says otherwise.
     */
    public function forDataSource(DataSource $source): static
    {
        return $this->state(fn () => [
            'carrier_account_id' => null,
            'data_source_id' => $source->id,
            'client_id' => $source->client_id,
        ]);
    }

    public function locationScoped(Location $location): static
    {
        return $this->state(fn () => ['location_id' => $location->id]);
    }

    public function clientScoped(Client $client): static
    {
        return $this->state(fn () => ['client_id' => $client->id]);
    }

    public function global(): static
    {
        return $this->state(fn () => ['location_id' => null, 'client_id' => null]);
    }

    public function withRateShop(): static
    {
        return $this->state(fn () => ['rate_shop' => true]);
    }
}
