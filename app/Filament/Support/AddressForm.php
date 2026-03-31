<?php

namespace App\Filament\Support;

use App\Services\AddressReferenceService;
use Filament\Forms;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class AddressForm
{
    public static function countrySelect(
        string $field = 'country',
        string $subdivisionField = 'state_or_province',
        bool $required = true,
        ?\Closure $afterStateUpdated = null,
    ): Forms\Components\Select {
        return Forms\Components\Select::make($field)
            ->label('Country')
            ->options(fn (): array => app(AddressReferenceService::class)->getCountryOptions())
            ->searchable()
            ->preload()
            ->native(false)
            ->default('US')
            ->required($required)
            ->live()
            ->afterStateUpdated(function (Set $set, ?string $state) use ($field, $subdivisionField, $afterStateUpdated): void {
                $normalizedCountry = app(AddressReferenceService::class)->normalizeCountry($state) ?? ($state ? strtoupper(trim($state)) : null);

                $set($field, $normalizedCountry);
                $set($subdivisionField, null);

                if ($afterStateUpdated instanceof \Closure) {
                    $afterStateUpdated($set);
                }
            });
    }

    public static function administrativeAreaSelect(
        string $field = 'state_or_province',
        string $countryField = 'country',
        ?\Closure $afterStateUpdated = null,
    ): Forms\Components\Select {
        return Forms\Components\Select::make($field)
            ->label(fn (Get $get): string => app(AddressReferenceService::class)->getAdministrativeAreaLabel($get($countryField)))
            ->options(fn (Get $get): array => app(AddressReferenceService::class)->getSubdivisionOptions($get($countryField)))
            ->searchable()
            ->preload()
            ->native(false)
            ->disabled(fn (Get $get): bool => app(AddressReferenceService::class)->getSubdivisionOptions($get($countryField)) === [])
            ->required(fn (Get $get): bool => app(AddressReferenceService::class)->isAdministrativeAreaRequired($get($countryField)))
            ->live()
            ->afterStateUpdated($afterStateUpdated);
    }
}
