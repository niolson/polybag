<?php

namespace Database\Factories;

use App\Models\ExchangeRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Rates here are round, synthetic figures, not published ones.
 *
 * @extends Factory<ExchangeRate>
 */
class ExchangeRateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rate_date' => now()->toDateString(),
            'currency' => 'USD',
            'rate' => '1.100000',
        ];
    }

    /**
     * A rate for the given currency, on the given day or today.
     */
    public function quoting(string $currency, float $rate, ?string $date = null): static
    {
        return $this->state(fn (): array => array_filter([
            'currency' => $currency,
            'rate' => number_format($rate, 6, '.', ''),
            'rate_date' => $date,
        ]));
    }
}
