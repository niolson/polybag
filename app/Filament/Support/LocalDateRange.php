<?php

namespace App\Filament\Support;

use App\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * A report's from/until dates, read as calendar dates in the default
 * Location's timezone, as the `ship_by` filters read them.
 *
 * Timestamps such as `packages.shipped_at` are stored in UTC. Comparing them
 * with a bare local date bills a Label bought on a US evening on the last day
 * of a month in the next month, and `<= 'Y-m-d'` drops the whole "until" day
 * (`release-polish/32`).
 */
class LocalDateRange
{
    /**
     * The UTC bounds of the local days from `$from` through `$until`: the
     * start of `$from`, inclusive, and the start of the day after `$until`,
     * exclusive. An empty date is no bound.
     *
     * @return array{from: ?string, until: ?string}
     */
    public static function utcBounds(?string $from, ?string $until): array
    {
        $timezone = Location::timezone();
        $storage = config('app.timezone');

        return [
            'from' => filled($from)
                ? CarbonImmutable::parse($from, $timezone)->startOfDay()->setTimezone($storage)->format('Y-m-d H:i:s')
                : null,
            'until' => filled($until)
                ? CarbonImmutable::parse($until, $timezone)->addDay()->startOfDay()->setTimezone($storage)->format('Y-m-d H:i:s')
                : null,
        ];
    }

    /**
     * Narrow `$query` to rows whose `$column` timestamp falls on the local
     * days from `$from` through `$until`.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function apply(Builder $query, string $column, ?string $from, ?string $until): Builder
    {
        $bounds = self::utcBounds($from, $until);

        if ($bounds['from'] !== null) {
            $query->where($column, '>=', $bounds['from']);
        }

        if ($bounds['until'] !== null) {
            $query->where($column, '<', $bounds['until']);
        }

        return $query;
    }

    /**
     * Now, in the default Location's timezone, for a filter's default dates.
     */
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(Location::timezone());
    }
}
