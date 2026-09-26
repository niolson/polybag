<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Enums\LabelReferenceSource;
use App\Services\AddressReferenceService;
use App\Services\LabelReferenceResolver;
use App\Services\SettingsService;
use App\Support\SvgUploadSanitizer;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ClientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Client Details')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Toggle::make('active')
                            ->default(true),
                        Toggle::make('is_default')
                            ->label('Default client')
                            ->helperText('Shipments with no client assigned use this client.')
                            ->default(false),
                        FileUpload::make('logo')
                            ->label('Pack Slip Logo')
                            ->helperText('Logo printed on pack slips for this client. Recommended: landscape image, PNG or JPG.')
                            ->disk('public')
                            ->directory('logos')
                            ->visibility('public')
                            ->image()
                            ->panelLayout('grid')
                            ->maxSize(10240)
                            ->acceptedFileTypes(['image/svg+xml', 'image/png', 'image/jpeg', 'image/gif', 'image/webp'])
                            ->saveUploadedFileUsing(SvgUploadSanitizer::saveUsing())
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Pack Slip')
                    ->description('Branding and messaging printed on pack slips for this client.')
                    ->schema([
                        TextInput::make('company_name')
                            ->label('Company Name')
                            ->maxLength(255)
                            ->helperText('Name shown in the return address on pack slips. Defaults to client name if blank.')
                            ->columnSpanFull(),
                        Textarea::make('custom_message')
                            ->label('Custom Message')
                            ->rows(3)
                            ->maxLength(500)
                            ->helperText('Optional message printed at the bottom of each pack slip.')
                            ->columnSpanFull(),
                        Textarea::make('return_instructions')
                            ->label('Return Instructions')
                            ->rows(3)
                            ->maxLength(500)
                            ->helperText('Optional return instructions printed at the bottom of each pack slip.')
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(fn (?object $record): bool => blank($record?->company_name) && blank($record?->custom_message) && blank($record?->return_instructions))
                    ->columns(2),

                Section::make('Shipping Labels')
                    ->description('How labels are printed for this client\'s shipments.')
                    ->schema([
                        Select::make('label_reference_source')
                            ->label('Reference Printed on Labels')
                            ->options(LabelReferenceSource::class)
                            ->placeholder(fn (): string => 'Use app setting ('.app(LabelReferenceResolver::class)->instanceDefault()->getLabel().')')
                            ->native(false)
                            ->helperText('Printed in the carrier\'s reference field so a label can be matched back to its package. USPS and UPS print it as text; FedEx prints it after "REF:". Carriers cut it to their own length limits. Leave unset to follow the app-wide setting.')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Billing / Rate Card')
                    ->description('Fees charged to this client per billing period. Used in the Client Billing report.')
                    ->visible(fn () => app(SettingsService::class)->get('multi_client_enabled', false))
                    ->schema([
                        TextInput::make('pick_fee_first_item')
                            ->label('Pick Fee (first item)')
                            ->numeric()
                            ->prefix('$')
                            ->step(0.01)
                            ->minValue(0)
                            ->rules(['min:0'])
                            ->placeholder('0.00')
                            ->helperText('Flat per-order base pick fee covering the first item.'),
                        TextInput::make('pick_fee_additional_item')
                            ->label('Pick Fee (each additional item)')
                            ->numeric()
                            ->prefix('$')
                            ->step(0.01)
                            ->minValue(0)
                            ->rules(['min:0'])
                            ->placeholder('0.00')
                            ->helperText('Per-item charge for each item after the first in an order.'),
                        TextInput::make('label_fee_per_package')
                            ->label('Label Fee per Package')
                            ->numeric()
                            ->prefix('$')
                            ->step(0.01)
                            ->minValue(0)
                            ->rules(['min:0'])
                            ->placeholder('0.00')
                            ->helperText('Per-label charge when not bundled with carrier cost.'),
                    ])
                    ->collapsible()
                    ->collapsed(fn (?object $record): bool => blank($record?->pick_fee_first_item) && blank($record?->pick_fee_additional_item) && blank($record?->label_fee_per_package))
                    ->columns(3),

                Section::make('Return Address')
                    ->description('Ship-from address shown on labels for this client\'s shipments. Leave blank to use the warehouse address.')
                    ->schema([
                        TextInput::make('return_company')
                            ->label('Company')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('return_name')
                            ->label('Contact Name')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('return_address1')
                            ->label('Address')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('return_address2')
                            ->label('Apartment, suite, etc.')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Grid::make(['default' => 1, 'md' => 3])
                            ->schema([
                                TextInput::make('return_city')
                                    ->label('City')
                                    ->maxLength(255),
                                TextInput::make('return_state_or_province')
                                    ->label('State / Province')
                                    ->maxLength(100),
                                TextInput::make('return_postal_code')
                                    ->label('Postal Code')
                                    ->maxLength(20),
                            ])
                            ->columnSpanFull(),
                        Select::make('return_country')
                            ->label('Country')
                            ->options(fn (): array => app(AddressReferenceService::class)->getCountryOptions())
                            ->searchable()
                            ->native(false)
                            ->columnSpanFull(),
                        TextInput::make('return_phone')
                            ->label('Phone')
                            ->tel()
                            ->maxLength(50)
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(fn (?object $record): bool => ! $record?->hasReturnAddress()),
            ]);
    }
}
