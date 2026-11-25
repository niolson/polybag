<?php

namespace App\Filament\Support;

use Illuminate\Support\Collection;

/**
 * Nearest-rank percentiles for the reports that need a median or a 90th
 * percentile, which no SQL both MySQL and SQLite run can give.
 */
class Percentile
{
    /**
     * The value at or below which `$percentile` percent of the values fall,
     * or null for no values.
     *
     * @template TKey of array-key
     * @template TValue of float|int
     *
     * @param  Collection<TKey, TValue>  $values
     */
    public static function of(Collection $values, int $percentile): ?float
    {
        if ($values->isEmpty()) {
            return null;
        }

        $sorted = $values->sort()->values();
        $rank = (int) ceil($percentile / 100 * $sorted->count());

        return (float) $sorted->get(max(1, $rank) - 1);
    }
}
