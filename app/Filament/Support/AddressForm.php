<?php

namespace App\Filament\Support;

use App\Services\AddressReferenceService;
use Filament\Forms;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class AddressForm
{
    /**
     * @return array<int, mixed>
     */
    public static function recipientAddressFields(
        string $prefix = '',
        bool $includeCompany = true,
        bool $includePhone = true,
        bool $includeEmail = false,
        bool $requireNames = false,
        bool $requirePostalCode = false,
        int $postalCodeMaxLength = 255,
        int $phoneMaxLength = 255,
        ?\Closure $afterStateUpdated = null,
    ): array {
        $field = fn (string $name): string => $prefix.$name;
        $touch = function ($component) use ($afterStateUpdated) {
            if ($component instanceof Forms\Components\TextInput) {
                $component->live(onBlur: true);
            }

            if ($afterStateUpdated instanceof \Closure) {
                $component->afterStateUpdated(fn (Set $set) => $afterStateUpdated($set));
            }

            return $component;
        };

        $fields = [
            self::countrySelect(
                field: $field('country'),
                subdivisionField: $field('state_or_province'),
                afterStateUpdated: $afterStateUpdated,
            )
                ->label('Country / Region')
                ->columnSpanFull(),
            Grid::make(2)
                ->schema([
                    $touch(
                        Forms\Components\TextInput::make($field('first_name'))
                            ->label('First Name')
                            ->required($requireNames)
                            ->maxLength(255)
                    ),
                    $touch(
                        Forms\Components\TextInput::make($field('last_name'))
                            ->label('Last Name')
                            ->required($requireNames)
                            ->maxLength(255)
                    ),
                ])
                ->columnSpanFull(),
        ];

        if ($includeCompany) {
            $fields[] = $touch(
                Forms\Components\TextInput::make($field('company'))
                    ->label('Company')
                    ->maxLength(255)
                    ->columnSpanFull()
            );
        }

        $fields[] = $touch(
            Forms\Components\TextInput::make($field('address1'))
                ->label('Address')
                ->required()
                ->maxLength(255)
                ->columnSpanFull()
        );

        $fields[] = $touch(
            Forms\Components\TextInput::make($field('address2'))
                ->label('Apartment, suite, etc.')
                ->maxLength(255)
                ->columnSpanFull()
        );

        $fields[] = Grid::make([
            'default' => 1,
            'md' => 3,
        ])
            ->schema([
                $touch(
                    Forms\Components\TextInput::make($field('city'))
                        ->label('City')
                        ->required()
                        ->maxLength(255)
                ),
                self::administrativeAreaSelect(
                    field: $field('state_or_province'),
                    countryField: $field('country'),
                    afterStateUpdated: $afterStateUpdated,
                ),
                $touch(
                    Forms\Components\TextInput::make($field('postal_code'))
                        ->label('Postal Code')
                        ->required($requirePostalCode)
                        ->maxLength($postalCodeMaxLength)
                ),
            ])
            ->columnSpanFull();

        if ($includePhone || $includeEmail) {
            $contactFields = [];

            if ($includePhone) {
                $contactFields[] = $touch(
                    Forms\Components\TextInput::make($field('phone'))
                        ->label('Phone')
                        ->tel()
                        ->telRegex('/^[+]?[0-9\s()\-\.\/]+(?:\s*(?:ext\.?|x|#)\s*[0-9]+)?$/i')
                        ->maxLength($phoneMaxLength)
                        ->helperText(function (Get $get) use ($field): string {
                            $country = $get($field('country')) ?: 'the selected country';

                            return "Use a valid phone number for {$country}. If the phone number is in a different country than the address, enter it in international format, such as +14155550132.";
                        })
                );
            }

            if ($includeEmail) {
                $contactFields[] = $touch(
                    Forms\Components\TextInput::make($field('email'))
                        ->label('Email')
                        ->email()
                        ->maxLength(255)
                );
            }

            $fields[] = Grid::make(max(1, count($contactFields)))
                ->schema($contactFields)
                ->columnSpanFull();
        }

        return $fields;
    }

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
