<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ExchangeRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One ECB euro reference rate: how many units of `currency` one euro bought
 * on `rate_date` (`international-customs-terms/04`).
 *
 * Fetched by `exchange-rates:fetch`; read by `ExchangeRateConverter`, which
 * converts a USD customs value into a regime's threshold currency.
 *
 * @property int $id
 * @property CarbonImmutable $rate_date
 * @property string $currency
 * @property string $rate
 */
class ExchangeRate extends Model
{
    /** @use HasFactory<ExchangeRateFactory> */
    use HasFactory;

    /**
     * The ECB quotes every rate against the euro, which therefore has no row.
     */
    public const BASE_CURRENCY = 'EUR';

    protected $fillable = [
        'rate_date',
        'currency',
        'rate',
    ];

    protected function casts(): array
    {
        return [
            'rate_date' => 'immutable_date',
            'rate' => 'decimal:6',
        ];
    }
}
