<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Enums\DutiesTerms;
use App\Enums\LabelReferenceSource;
use App\Enums\TaxRegistrationRegime;
use App\Filament\Pages\Settings;
use App\Models\Client;
use App\Models\ClientTaxRegistration;
use App\Services\AddressReferenceService;
use App\Services\LabelReferenceResolver;
use App\Services\SettingsService;
use App\Support\SvgUploadSanitizer;
use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

class ClientForm
{
    /**
     * The form fields `duties_policy` is edited through: its `EU` entry, and
     * its country entries as repeater rows. Neither is a column; the pages
     * convert with {@see self::fillDutiesPolicy()} and
     * {@see self::saveDutiesPolicy()}.
     */
    public const DUTIES_POLICY_EU_FIELD = 'duties_policy_eu';

    public const DUTIES_POLICY_COUNTRIES_FIELD = 'duties_policy_countries';

    /**
     * The Settings page's registrations repeater, which is not a relationship.
     */
    public const TAX_REGISTRATIONS_FIELD = 'tax_registrations';

    /**
     * Spread a record's `duties_policy` map into the form's EU field and
     * country rows.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillDutiesPolicy(array $data): array
    {
        $policy = is_array($data['duties_policy'] ?? null) ? $data['duties_policy'] : [];

        $data[self::DUTIES_POLICY_EU_FIELD] = $policy[Client::DUTIES_POLICY_EU] ?? null;
        $data[self::DUTIES_POLICY_COUNTRIES_FIELD] = collect($policy)
            ->except(Client::DUTIES_POLICY_EU)
            ->map(fn (mixed $terms, string $country): array => ['country' => $country, 'terms' => $terms])
            ->values()
            ->all();

        unset($data['duties_policy']);

        return $data;
    }

    /**
     * Fold the form's EU field and country rows back into a `duties_policy`
     * map, EU first. A policy with no entries is stored as null: nothing has
     * been chosen.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function saveDutiesPolicy(array $data): array
    {
        $policy = [];

        if (($euTerms = DutiesTerms::fromInput($data[self::DUTIES_POLICY_EU_FIELD] ?? null)) !== null) {
            $policy[Client::DUTIES_POLICY_EU] = $euTerms->value;
        }

        foreach ($data[self::DUTIES_POLICY_COUNTRIES_FIELD] ?? [] as $row) {
            $terms = DutiesTerms::fromInput($row['terms'] ?? null);

            if (filled($row['country'] ?? null) && $terms !== null) {
                $policy[strtoupper((string) $row['country'])] = $terms->value;
            }
        }

        unset($data[self::DUTIES_POLICY_EU_FIELD], $data[self::DUTIES_POLICY_COUNTRIES_FIELD]);

        $data['duties_policy'] = $policy === [] ? null : $policy;

        return $data;
    }

    /**
     * The duties policy and tax registration fields, shared by the client form
     * and, for the default client in single-client mode, the Settings page.
     *
     * The client form binds the registrations to the `taxRegistrations`
     * relationship; Settings, which has no record to bind, names a state path
     * under `$statePrefix` and saves it with {@see self::syncTaxRegistrations()}.
     * Either way rows are synced by regime, so swapping two saved rows' regimes
     * in one save does not trip the unique (client, regime) index.
     *
     * @return array<int, Component>
     */
    public static function customsComponents(?string $statePrefix = null): array
    {
        $prefix = $statePrefix === null ? '' : $statePrefix.'.';

        $taxRegistrations = $statePrefix === null
            ? Repeater::make('taxRegistrations')
                ->relationship()
                ->saveRelationshipsUsing(fn (Repeater $component, Client $record): null => self::syncTaxRegistrations($record, $component->getState() ?? []))
            : Repeater::make($prefix.self::TAX_REGISTRATIONS_FIELD);

        return [
            Select::make($prefix.self::DUTIES_POLICY_EU_FIELD)
                ->label('EU duties terms')
                ->options(DutiesTerms::class)
                ->placeholder('Not set — EU labels are refused')
                ->markAsRequired()
                ->native(false)
                ->helperText('Must be set before a label to an EU country can be bought for this client, unless the order carries its own terms. DDP is recommended: a DDU parcel into the EU collects duties, handling fees and the carrier\'s own fee at the door, and postal networks are starting to refuse them. DDP bills those charges to the carrier account that buys the label.')
                ->columnSpanFull(),
            Repeater::make($prefix.self::DUTIES_POLICY_COUNTRIES_FIELD)
                ->label('Country terms')
                ->helperText('A country row overrides the EU row for that country. A country outside the EU with no row ships DDU.')
                ->schema([
                    Select::make('country')
                        ->label('Country')
                        ->options(fn (): array => app(AddressReferenceService::class)->getCountryOptions())
                        ->searchable()
                        ->required()
                        ->distinct()
                        ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                    Select::make('terms')
                        ->label('Duties terms')
                        ->options(DutiesTerms::class)
                        ->required()
                        ->native(false),
                ])
                ->columns(2)
                ->defaultItems(0)
                ->addActionLabel('Add country')
                ->columnSpanFull(),
            $taxRegistrations
                ->label('Seller tax registrations')
                ->helperText('One number per regime. Each is declared only to the destinations its regime covers, and only below its low-value threshold.')
                ->schema([
                    Select::make('regime')
                        ->label('Regime')
                        ->options(TaxRegistrationRegime::class)
                        ->required()
                        ->distinct()
                        ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                        ->live()
                        ->native(false),
                    TextInput::make('number')
                        ->label('Number')
                        ->required()
                        ->helperText(fn (Get $get): ?string => TaxRegistrationRegime::fromInput($get('regime'))?->numberFormat())
                        ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            $error = TaxRegistrationRegime::fromInput($get('regime'))?->numberError(is_string($value) ? $value : null);

                            if ($error !== null) {
                                $fail($error);
                            }
                        })
                        ->dehydrateStateUsing(fn (?string $state, Get $get): ?string => $state !== null && ($regime = TaxRegistrationRegime::fromInput($get('regime'))) !== null
                            ? $regime->normalizeNumber($state)
                            : $state),
                ])
                ->columns(2)
                ->defaultItems(0)
                ->addActionLabel('Add registration')
                ->columnSpanFull(),
        ];
    }

    /**
     * The form rows for a client's registrations, one per regime.
     *
     * @return list<array{regime: string, number: string}>
     */
    public static function taxRegistrationRows(Client $client): array
    {
        return $client->taxRegistrations()
            ->orderBy('id')
            ->get()
            ->map(fn (ClientTaxRegistration $registration): array => [
                'regime' => $registration->regime->value,
                'number' => $registration->number,
            ])
            ->values()
            ->all();
    }

    /**
     * Make the client's registrations exactly the submitted rows, matched by
     * regime rather than by row, so a number moves between regimes without a
     * moment where two rows hold the same regime.
     *
     * @param  array<array-key, mixed>  $rows
     */
    public static function syncTaxRegistrations(Client $client, array $rows): null
    {
        $numbers = [];

        foreach ($rows as $row) {
            $regime = TaxRegistrationRegime::fromInput(is_array($row) ? ($row['regime'] ?? null) : null);
            $number = is_array($row) ? ($row['number'] ?? null) : null;

            if ($regime !== null && is_string($number) && filled($number)) {
                $numbers[$regime->value] = $regime->normalizeNumber($number);
            }
        }

        DB::transaction(function () use ($client, $numbers): void {
            $client->taxRegistrations()->whereNotIn('regime', array_keys($numbers))->delete();

            foreach ($numbers as $regime => $number) {
                $client->taxRegistrations()->updateOrCreate(['regime' => $regime], ['number' => $number]);
            }
        });

        $client->unsetRelation('taxRegistrations');

        return null;
    }

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
                            ->helperText(fn (): string => 'Logo printed on pack slips for this client. Recommended: landscape image, PNG or JPG.'
                                .(app(SettingsService::class)->packSlipsEnabled() ? '' : ' '.Settings::PACK_SLIPS_OFF_NOTE))
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
                    ->description(fn (): string => app(SettingsService::class)->packSlipsEnabled()
                        ? 'Branding and messaging printed on pack slips for this client.'
                        : 'Inactive. '.Settings::PACK_SLIPS_OFF_NOTE)
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

                Section::make('Customs')
                    ->description('Who pays duties on this client\'s international shipments, and the tax registrations that show import VAT was collected at checkout. An order that carries its own terms or registration uses those instead.')
                    ->schema([
                        ...self::customsComponents(),
                    ])
                    ->collapsible(),

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
