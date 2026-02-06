<?php

namespace App\Filament\Resources\Carriers\Schemas;

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
                    ->required(),
                Toggle::make('active')
                    ->default(true)
                    ->helperText('Disabled carriers will not be used for rate shopping.'),
            ]);
    }
}
