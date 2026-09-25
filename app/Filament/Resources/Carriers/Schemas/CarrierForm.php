<?php

namespace App\Filament\Resources\Carriers\Schemas;

use App\Models\Carrier;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CarrierForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->disabled(fn (?Carrier $record): bool => (bool) $record?->is_system)
                    ->helperText(fn (?Carrier $record): ?string => $record?->is_system
                        ? 'A system carrier\'s name is fixed, because PolyBag finds the carrier by it. Set a display name to change what is shown.'
                        : null),
                TextInput::make('display_name')
                    ->maxLength(255)
                    ->helperText('Shown in PolyBag instead of the name. Labels, exports and carrier requests keep the name.'),
                Toggle::make('active')
                    ->default(true)
                    ->helperText('Disabled carriers will not be used for rate shopping.'),
            ]);
    }
}
