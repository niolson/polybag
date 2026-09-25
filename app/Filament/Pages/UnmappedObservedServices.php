<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Enums\SourceEnvironment;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\Location;
use App\Models\ObservedService;
use App\Services\PostageSources\ObservedServiceMapper;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Where a human says "this Amazon service is our Ground Advantage".
 *
 * The counterpart to {@see UnmappedShippingReferences}, and modeled on it
 * deliberately: an import that cannot resolve a channel's shipping string does
 * not reject the shipment, it records the string and offers it here. A postage
 * source naming a service we have no row for behaves the same way.
 *
 * Two things this page does *not* do, both from ADR-0003. It carries no
 * navigation badge and raises nothing: an observation nobody promotes stays
 * permanently human-selectable rather than nagging from a queue (decision 8).
 * And it never invents catalog rows on the operator's behalf — authoring a
 * `Carrier` or a `CarrierService` is a separate, admin-gated action with a form
 * in front of it (decision 2).
 */
class UnmappedObservedServices extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Map Carrier Services';

    protected static ?string $title = 'Map Carrier Services';

    protected static UnitEnum|string|null $navigationGroup = 'Integrations';

    protected static ?int $navigationSort = 92;

    protected string $view = 'filament.pages.unmapped-observed-services';

    public static function canAccess(): bool
    {
        // Admin, not Manager: from `carrier-catalog-reset/13` a mapping
        // authorizes spend, and the page should not change hands that day.
        return auth()->user()->role->isAtLeast(Role::Admin);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                ObservedService::query()
                    ->withMapping()
                    ->with('mappedCarrierService.carrier')
            )
            ->defaultSort('last_seen_at', 'desc')
            ->groups([
                Group::make('source')
                    ->label('Source')
                    ->getTitleFromRecordUsing(fn (ObservedService $record): string => Str::headline($record->source)),
            ])
            ->defaultGroup('source')
            ->columns([
                Tables\Columns\TextColumn::make('external_carrier_name')
                    ->label('Carrier')
                    ->state(fn (ObservedService $record): string => $record->external_carrier_name ?? $record->external_carrier_id)
                    ->description(fn (ObservedService $record): string => $record->external_carrier_id)
                    ->searchable(['external_carrier_name', 'external_carrier_id'])
                    ->sortable(),
                Tables\Columns\TextColumn::make('external_service_name')
                    ->label('Service')
                    ->state(fn (ObservedService $record): string => $record->external_service_name ?? $record->external_service_id)
                    ->description(fn (ObservedService $record): string => $record->external_service_id)
                    ->searchable(['external_service_name', 'external_service_id'])
                    ->sortable()
                    // Grouped by source, so these render per source: how many
                    // distinct identities that source has shown us, against how
                    // many times it has shown them.
                    ->summarize(
                        Tables\Columns\Summarizers\Count::make()->label('Services'),
                    ),
                Tables\Columns\TextColumn::make('environment')
                    ->label('Environment')
                    ->badge()
                    ->formatStateUsing(fn (SourceEnvironment $state): string => $state->label())
                    ->color(fn (SourceEnvironment $state): string => $state === SourceEnvironment::Sandbox ? 'warning' : 'gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('marketplace')
                    ->label('Marketplace')
                    // Stored as '' rather than null for sources that have no
                    // marketplace, so the empty case needs saying explicitly.
                    ->state(fn (ObservedService $record): ?string => $record->marketplace !== '' ? $record->marketplace : null)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('last_eligible_at')
                    ->label('Offered')
                    ->tooltip('Whether this service has ever been offered as buyable, rather than only listed as ineligible.')
                    ->boolean()
                    ->state(fn (ObservedService $record): bool => $record->hasBeenEligible()),
                Tables\Columns\TextColumn::make('observation_count')
                    ->label('Times seen')
                    ->numeric()
                    ->sortable()
                    ->summarize(
                        Tables\Columns\Summarizers\Sum::make()->label('Observations'),
                    ),
                Tables\Columns\TextColumn::make('last_seen_at')
                    ->label('Last seen')
                    ->dateTime('M j, Y g:i A', timezone: Location::timezone())
                    ->sortable(),
                Tables\Columns\TextColumn::make('mappedCarrierService.name')
                    ->label('Mapped to')
                    ->description(fn (ObservedService $record): ?string => $record->mappedCarrierService?->carrier?->label())
                    ->placeholder('Unmapped'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('mapped')
                    ->label('Mapping')
                    ->placeholder('All')
                    ->trueLabel('Mapped')
                    ->falseLabel('Unmapped')
                    ->queries(
                        true: fn ($query) => $query->whereNot(fn ($query) => $query->unmapped()),
                        false: fn ($query) => $query->unmapped(),
                        blank: fn ($query) => $query,
                    )
                    ->default(false),
                Tables\Filters\SelectFilter::make('environment')
                    ->label('Environment')
                    ->options(fn (): array => collect(SourceEnvironment::cases())
                        ->mapWithKeys(fn (SourceEnvironment $environment): array => [$environment->value => $environment->label()])
                        ->all()),
            ])
            ->emptyStateHeading('Nothing observed yet')
            ->emptyStateDescription('Services a postage source reports appear here after a rate quote. Leaving one unmapped is fine — nothing depends on it being mapped, including approval for automated shipping.')
            ->recordActions([
                Actions\Action::make('assign')
                    ->label('Assign')
                    ->icon('heroicon-o-link')
                    ->modalDescription('Alias this observed service onto a service already in the catalog. Nothing new is created.')
                    ->schema([
                        Forms\Components\Select::make('carrier_service_id')
                            ->label('Carrier Service')
                            ->options(fn (): array => static::carrierServiceOptions())
                            ->default(fn (ObservedService $record): ?int => $record->mapped_carrier_service_id)
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (ObservedService $record, array $data): void {
                        $carrierService = CarrierService::findOrFail($data['carrier_service_id']);

                        $mapped = app(ObservedServiceMapper::class)->map($record, $carrierService);

                        Notification::make()
                            ->success()
                            ->title("Mapped to {$carrierService->name}")
                            ->body(static::coverage($mapped))
                            ->send();
                    }),

                Actions\Action::make('author')
                    ->label('Author service')
                    ->icon('heroicon-o-plus-circle')
                    ->color('gray')
                    ->modalHeading('Author a carrier service')
                    ->modalDescription('For a service the catalog has no row for. This creates a real Carrier Service — and, if you create one, a real Carrier — which then behaves like any other authored configuration.')
                    ->modalSubmitActionLabel('Create and map')
                    // Aliasing onto an existing service is a manager's job; minting
                    // catalog identities is not, and CarrierServicePolicy already
                    // says so for the resource that does it the ordinary way.
                    ->visible(fn (): bool => auth()->user()->can('create', CarrierService::class))
                    ->schema([
                        Forms\Components\Select::make('carrier_id')
                            ->label('Carrier')
                            ->options(fn (): array => Carrier::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->default(fn (ObservedService $record): ?int => static::matchingCarrierId($record))
                            ->searchable()
                            ->required()
                            ->createOptionForm([
                                Forms\Components\TextInput::make('name')
                                    ->label('Carrier name')
                                    ->required()
                                    ->maxLength(255),
                            ])
                            ->createOptionUsing(fn (array $data): int => Carrier::create([
                                'name' => $data['name'],
                                'active' => true,
                            ])->getKey())
                            ->helperText('No row for this carrier? Create one — this is catalog authorship, not discovery.'),
                        Forms\Components\TextInput::make('service_code')
                            ->label('Service code')
                            ->default(fn (ObservedService $record): string => $record->external_service_id)
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('name')
                            ->label('Service name')
                            ->default(fn (ObservedService $record): string => $record->external_service_name ?? $record->external_service_id)
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Toggle::make('can_ship_to_po_boxes')
                            ->label('Can ship to PO Boxes')
                            ->default(false),
                        Forms\Components\Toggle::make('can_ship_to_military_addresses')
                            ->label('Can ship to military addresses')
                            ->default(false),
                    ])
                    ->action(function (ObservedService $record, array $data): void {
                        $carrier = Carrier::findOrFail($data['carrier_id']);

                        $mapped = app(ObservedServiceMapper::class)->promote(
                            observation: $record,
                            carrier: $carrier,
                            serviceCode: $data['service_code'],
                            serviceName: $data['name'],
                            canShipToPoBoxes: (bool) $data['can_ship_to_po_boxes'],
                            canShipToMilitaryAddresses: (bool) $data['can_ship_to_military_addresses'],
                        );

                        Notification::make()
                            ->success()
                            ->title("Created {$carrier->label()} {$data['name']}")
                            ->body(static::coverage($mapped))
                            ->send();
                    }),

                Actions\Action::make('unmap')
                    ->label('Unmap')
                    ->icon('heroicon-o-link-slash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('The observation stays on file and stays selectable by a person. Only the mapping is removed; no catalog rows are deleted, and approvals for automated shipping are unchanged.')
                    ->visible(fn (ObservedService $record): bool => $record->mapped_carrier_service_id !== null)
                    ->action(function (ObservedService $record): void {
                        $observations = app(ObservedServiceMapper::class)->unmap($record);

                        Notification::make()
                            ->success()
                            ->title('Mapping removed')
                            ->body(static::coverage($observations))
                            ->send();
                    }),
            ]);
    }

    /**
     * Carrier services, labelled the way a person picking one needs to read
     * them — "USPS — Ground Advantage", not "Ground Advantage" three times.
     *
     * @return array<int, string>
     */
    protected static function carrierServiceOptions(): array
    {
        return CarrierService::query()
            ->with('carrier')
            ->get()
            ->sortBy(fn (CarrierService $service): string => $service->carrier->label().' '.$service->name)
            ->mapWithKeys(fn (CarrierService $service): array => [
                $service->getKey() => trim($service->carrier->label().' — '.$service->name),
            ])
            ->all();
    }

    /**
     * The carrier row this observation probably belongs to, as a starting point
     * a human then confirms or changes. A guess in a form default, not a match.
     */
    protected static function matchingCarrierId(ObservedService $record): ?int
    {
        $name = Str::lower(Str::squish($record->external_carrier_name ?? $record->external_carrier_id));

        return Carrier::query()
            ->get()
            ->first(fn (Carrier $carrier): bool => Str::lower(Str::squish($carrier->name)) === $name)
            ?->getKey();
    }

    /**
     * One mapping can cover the same identity in more than one environment or
     * marketplace, so say when it did rather than leaving it a surprise.
     */
    protected static function coverage(int $observations): ?string
    {
        return $observations > 1
            ? "Applies to all {$observations} observations of this service."
            : null;
    }

    public function getSubheading(): ?string
    {
        return 'Services a postage source has reported. Mapping one gives it a name we already use; leaving it unmapped is a valid end state. Whether automated shipping may buy a service is set separately, under Amazon Approvals.';
    }
}
