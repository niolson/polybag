<?php

namespace App\Filament\Support;

/**
 * The timezones a Location may be set to.
 *
 * Every IANA zone, not only the Americas: a Location can be anywhere a
 * carrier picks up, and its timezone decides the ship date.
 */
class TimezoneOptions
{
    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return collect(timezone_identifiers_list())
            ->mapWithKeys(fn (string $timezone): array => [$timezone => str_replace('_', ' ', $timezone)])
            ->all();
    }
}
