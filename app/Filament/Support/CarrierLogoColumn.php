<?php

namespace App\Filament\Support;

use App\Models\Carrier;
use Filament\Tables\Columns\TextColumn;

class CarrierLogoColumn
{
    /**
     * A TextColumn that shows the carrier logo when available, falling back to the carrier name.
     *
     * Pass the Filament column name (e.g. 'carrier' for a string field, 'carrier.name' for a relation).
     * When the logo is present it renders a small inline <img>; otherwise the plain name is shown.
     *
     * @param  string  $name  Filament column name
     * @param  \Closure|string|null  $state  Optional closure or attribute path to resolve the carrier name.
     *                                       If omitted the column name is used to read the value from $record.
     *                                       A closure may return a `Carrier`: its name finds the logo and
     *                                       its label is the text shown. A name string is shown as the
     *                                       label of the carrier row it names, if any.
     */
    public static function make(string $name, \Closure|string|null $state = null): TextColumn
    {
        return TextColumn::make($name)
            ->label('Carrier')
            ->html()
            ->state(function ($record) use ($name, $state): string {
                $carrier = match (true) {
                    $state instanceof \Closure => ($state)($record),
                    is_string($state) => data_get($record, $state),
                    default => data_get($record, $name),
                };

                if (! $carrier) {
                    return '—';
                }

                return self::html($carrier instanceof Carrier ? $carrier : (string) $carrier);
            });
    }

    /**
     * The carrier's logo as an inline <img>, or its label where it has none.
     *
     * @param  Carrier|string  $carrier  A carrier row, or a carrier name: a name is shown as the label of the carrier row it names, if any
     * @param  string  $sizeClasses  Height and width classes for the logo, and the height of the label that stands in for it
     */
    public static function html(Carrier|string $carrier, string $sizeClasses = 'h-7 max-w-[4rem]'): string
    {
        $carrierName = $carrier instanceof Carrier ? $carrier->name : $carrier;
        $carrierLabel = $carrier instanceof Carrier ? $carrier->label() : Carrier::labelForName($carrierName);

        $logoUrl = Carrier::logoUrlForName($carrierName);

        if ($logoUrl) {
            return '<img src="'.e($logoUrl).'" alt="'.e($carrierLabel).'" title="'.e($carrierLabel).'" class="'.e($sizeClasses).' object-contain object-left">';
        }

        $height = str($sizeClasses)->explode(' ')->first(fn (string $class): bool => str_starts_with($class, 'h-')) ?? '';

        return '<span class="inline-flex items-center '.e($height).'">'.e($carrierLabel).'</span>';
    }
}
