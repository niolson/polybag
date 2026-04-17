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
     */
    public static function make(string $name, \Closure|string|null $state = null): TextColumn
    {
        return TextColumn::make($name)
            ->label('Carrier')
            ->html()
            ->state(function ($record) use ($name, $state): string {
                $carrierName = match (true) {
                    $state instanceof \Closure => ($state)($record),
                    is_string($state) => data_get($record, $state),
                    default => data_get($record, $name),
                };

                if (! $carrierName) {
                    return '—';
                }

                $logoUrl = Carrier::logoUrlForName($carrierName);

                if ($logoUrl) {
                    return '<img src="'.e($logoUrl).'" alt="'.e($carrierName).'" class="h-7 max-w-[4rem] object-contain object-left">';
                }

                return '<span class="inline-flex items-center h-7">'.e($carrierName).'</span>';
            });
    }
}
