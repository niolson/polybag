<?php

namespace App\Filament\Resources;

use App\Enums\Deliverability;
use App\Enums\Role;
use App\Enums\ShipmentStatus;
use App\Filament\Concerns\InteractsWithScoutSearch;
use App\Filament\Resources\ShipmentResource\Pages;
use App\Filament\Resources\ShipmentResource\RelationManagers\PackagesRelationManager;
use App\Filament\Resources\ShipmentResource\RelationManagers\ShipmentItemsRelationManager;
use App\Jobs\ValidateAddressJob;
use App\Models\BoxSize;
use App\Models\Location;
use App\Models\Shipment;
use App\Services\BatchLabelService;
use App\Services\SettingsService;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Resources\Resource;
use Filament\Schemas\Components;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\IconPosition;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class ShipmentResource extends Resource
{
    use InteractsWithScoutSearch;

    protected static ?string $model = Shipment::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static \UnitEnum|string|null $navigationGroup = 'Manage';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'shipment_reference';

    public static function getNavigationBadge(): ?string
    {
        $count = Cache::remember('shipments_open_count', 60, function () {
            return Shipment::where('status', ShipmentStatus::Open)->count();
        });

        return $count > 0 ? number_format($count) : null;
    }

    protected static int $globalSearchResultsLimit = 10;

    protected static ?bool $shouldSplitGlobalSearchTerms = false;

    /**
     * @return array<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['shipment_reference'];
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Name' => trim("{$record->first_name} {$record->last_name}"),
            'Location' => trim("{$record->city}, {$record->state_or_province}"),
        ];
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->columns(2)
            ->schema([
                // Left column — Shipment Details
                Section::make('Shipment Details')
                    ->inlineLabel()
                    ->schema([
                        Forms\Components\TextInput::make('shipment_reference')
                            ->maxLength(255),
                        Forms\Components\Select::make('status')
                            ->options(ShipmentStatus::class)
                            ->disabled(fn () => ! auth()->user()?->role->isAtLeast(Role::Manager))
                            ->visibleOn('edit'),
                        Forms\Components\Select::make('channel_id')
                            ->relationship('channel', 'name')
                            ->required(),
                        Forms\Components\Select::make('shipping_method_id')
                            ->relationship('shippingMethod', 'name')
                            ->required(),
                        Forms\Components\TextInput::make('value')
                            ->numeric()
                            ->prefix('$'),
                        Forms\Components\DatePicker::make('deliver_by')
                            ->label('Deliver By'),

                        // Unmapped warnings (edit only)
                        Forms\Components\Placeholder::make('unmapped_channel_warning')
                            ->label('')
                            ->content('⚠ Channel reference is unmapped. [Fix it](/app/unmapped-channel-references)')
                            ->visible(fn (?Shipment $record): bool => $record !== null && $record->channel_id === null && filled($record->channel_reference)),

                        Forms\Components\Placeholder::make('unmapped_shipping_warning')
                            ->label('')
                            ->content('⚠ Shipping method reference is unmapped. [Fix it](/app/unmapped-shipping-references)')
                            ->visible(fn (?Shipment $record): bool => $record !== null && $record->shipping_method_id === null && filled($record->shipping_method_reference)),
                    ]),

                // Right column — Recipient & Address
                Section::make('Recipient & Address')
                    ->inlineLabel()
                    ->schema([
                        Forms\Components\TextInput::make('first_name')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('last_name')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('company')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('phone')
                            ->tel()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('phone_extension')
                            ->label('Phone Ext.')
                            ->maxLength(6),
                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->maxLength(255),

                        Section::make('Shipping Address')
                            ->inlineLabel()
                            ->schema([
                                Forms\Components\TextInput::make('address1')
                                    ->required()
                                    ->maxLength(255)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (Components\Utilities\Set $set) => $set('checked', false)),
                                Forms\Components\TextInput::make('address2')
                                    ->maxLength(255)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (Components\Utilities\Set $set) => $set('checked', false)),
                                Forms\Components\TextInput::make('city')
                                    ->required()
                                    ->maxLength(255)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (Components\Utilities\Set $set) => $set('checked', false)),
                                Forms\Components\TextInput::make('state_or_province')
                                    ->label('State or Province')
                                    ->maxLength(255)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (Components\Utilities\Set $set) => $set('checked', false)),
                                Forms\Components\TextInput::make('postal_code')
                                    ->label('Postal Code')
                                    ->maxLength(255)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (Components\Utilities\Set $set) => $set('checked', false)),
                                Forms\Components\TextInput::make('country')
                                    ->required()
                                    ->maxLength(255)
                                    ->default('US')
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (Components\Utilities\Set $set) => $set('checked', false)),
                            ])
                            ->columns(2),

                        Forms\Components\Hidden::make('checked'),

                        // Validated address section (edit only, when validated)
                        Section::make('Validated Address')
                            ->description('Address returned by USPS validation')
                            ->icon('heroicon-o-check-badge')
                            ->schema([
                                Forms\Components\Placeholder::make('validated_address1_display')
                                    ->label('Address 1')
                                    ->content(fn (Shipment $record) => $record->validated_address1),
                                Forms\Components\Placeholder::make('validated_address2_display')
                                    ->label('Address 2')
                                    ->content(fn (Shipment $record) => $record->validated_address2 ?: '—'),
                                Forms\Components\Placeholder::make('validated_city_display')
                                    ->label('City')
                                    ->content(fn (Shipment $record) => $record->validated_city),
                                Forms\Components\Placeholder::make('validated_state_or_province_display')
                                    ->label('State or Province')
                                    ->content(fn (Shipment $record) => $record->validated_state_or_province),
                                Forms\Components\Placeholder::make('validated_postal_code_display')
                                    ->label('Postal Code')
                                    ->content(fn (Shipment $record) => $record->validated_postal_code),
                            ])
                            ->columns(2)
                            ->collapsed()
                            ->visible(fn (?Shipment $record): bool => $record !== null && $record->checked && filled($record->validated_address1)),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['shippingMethod', 'channel']))
            ->searchable()
            ->searchUsing(function (Builder $query, string $search): void {
                static::applyGlobalSearchAttributeConstraints($query, $search);
            })
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                Tables\Columns\TextColumn::make('shipment_reference')
                    ->fontFamily('mono')
                    ->size('sm')
                    ->sortable(),
                Tables\Columns\TextColumn::make('channel.name')
                    ->label('Channel')
                    ->icon(fn (Shipment $record): ?string => $record->channel?->icon)
                    ->iconPosition(IconPosition::Before)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('full_name')
                    ->label('Name')
                    ->state(fn (Shipment $record): string => trim("{$record->first_name} {$record->last_name}"))
                    ->searchable(query: fn ($query, string $search) => $query
                        ->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%"))
                    ->weight(FontWeight::Medium),
                Tables\Columns\TextColumn::make('company')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('shippingMethod.name')
                    ->label('Shipping Method'),
                Tables\Columns\TextColumn::make('deliver_by')
                    ->label('Deliver By')
                    ->date(timezone: Location::timezone())
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('deliverability')
                    ->label('Deliverable')
                    ->badge(),
                Tables\Columns\TextColumn::make('status')
                    ->badge(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('M j, Y g:i A', timezone: Location::timezone())
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(ShipmentStatus::class),
                Tables\Filters\SelectFilter::make('deliverability')
                    ->options(Deliverability::class)
                    ->label('Deliverability'),
                Tables\Filters\SelectFilter::make('channel')
                    ->relationship('channel', 'name')
                    ->label('Channel')
                    ->preload(),
                Tables\Filters\SelectFilter::make('shipping_method')
                    ->relationship('shippingMethod', 'name')
                    ->label('Shipping Method')
                    ->preload(),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('created_from')
                            ->label('Created From'),
                        Forms\Components\DatePicker::make('created_until')
                            ->label('Created Until'),
                    ])
                    ->columns(2)
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['created_from'], fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
                            ->when($data['created_until'], fn ($query, $date) => $query->whereDate('created_at', '<=', $date));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['created_from'] ?? null) {
                            $indicators['created_from'] = 'From '.$data['created_from'];
                        }
                        if ($data['created_until'] ?? null) {
                            $indicators['created_until'] = 'Until '.$data['created_until'];
                        }

                        return $indicators;
                    }),
                Tables\Filters\Filter::make('deliver_by')
                    ->form([
                        Forms\Components\DatePicker::make('deliver_by_from')
                            ->label('Deliver By From'),
                        Forms\Components\DatePicker::make('deliver_by_until')
                            ->label('Deliver By Until'),
                    ])
                    ->columns(2)
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['deliver_by_from'], fn ($query, $date) => $query->whereDate('deliver_by', '>=', $date))
                            ->when($data['deliver_by_until'], fn ($query, $date) => $query->whereDate('deliver_by', '<=', $date));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['deliver_by_from'] ?? null) {
                            $indicators['deliver_by_from'] = 'Deliver by from '.$data['deliver_by_from'];
                        }
                        if ($data['deliver_by_until'] ?? null) {
                            $indicators['deliver_by_until'] = 'Deliver by until '.$data['deliver_by_until'];
                        }

                        return $indicators;
                    }),
                Tables\Filters\Filter::make('value_range')
                    ->form([
                        Forms\Components\TextInput::make('value_from')
                            ->label('Min Value ($)')
                            ->numeric(),
                        Forms\Components\TextInput::make('value_to')
                            ->label('Max Value ($)')
                            ->numeric(),
                    ])
                    ->columns(2)
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['value_from'], fn ($query, $val) => $query->where('value', '>=', $val))
                            ->when($data['value_to'], fn ($query, $val) => $query->where('value', '<=', $val));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['value_from'] ?? null) {
                            $indicators['value_from'] = 'Value ≥ $'.$data['value_from'];
                        }
                        if ($data['value_to'] ?? null) {
                            $indicators['value_to'] = 'Value ≤ $'.$data['value_to'];
                        }

                        return $indicators;
                    }),
            ], layout: FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->filtersFormColumns(4)
            ->filtersFormSchema(fn (array $filters): array => [
                $filters['status'],
                $filters['deliverability'],
                $filters['channel'],
                $filters['shipping_method'],
                Section::make('More Filters')
                    ->schema([
                        $filters['created_at'],
                        $filters['deliver_by'],
                        $filters['value_range'],
                    ])
                    ->columns(4)
                    ->collapsed()
                    ->compact()
                    ->columnSpanFull(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([])
            ->toolbarActions([
                Actions\BulkAction::make('batch-ship')
                    ->label('Batch Ship')
                    ->icon('heroicon-o-paper-airplane')
                    ->visible(fn () => auth()->user()->role->isAtLeast(Role::Admin) && app(SettingsService::class)->get('batch_shipping_enabled', true))
                    ->schema([
                        Forms\Components\Select::make('box_size_id')
                            ->label('Box Size')
                            ->options(BoxSize::query()->pluck('label', 'id'))
                            ->required()
                            ->searchable(),
                        Forms\Components\Hidden::make('label_format')
                            ->default('pdf'),
                        Forms\Components\Hidden::make('label_dpi'),
                        Components\View::make('filament.components.batch-ship-local-storage'),
                    ])
                    ->modalHeading('Batch Ship')
                    ->modalDescription('Generate labels for all selected shipments using the same box size. Ineligible shipments will be skipped.')
                    ->modalSubmitActionLabel('Generate Labels')
                    ->action(function (Collection $records, array $data) {
                        $service = new BatchLabelService;

                        $validation = $service->validateShipmentsForBatch($records);

                        if ($validation->allIneligible()) {
                            Notification::make()
                                ->title('No eligible shipments')
                                ->body('None of the selected shipments are eligible for batch shipping.')
                                ->danger()
                                ->send();

                            return;
                        }

                        if ($validation->hasIneligible()) {
                            $skippedCount = $validation->ineligible->count();
                            Notification::make()
                                ->title("{$skippedCount} shipment(s) skipped")
                                ->body($validation->ineligible->map(fn ($item) => "{$item['shipment']->shipment_reference}: {$item['reason']}")->join("\n"))
                                ->warning()
                                ->persistent()
                                ->send();
                        }

                        $batch = $service->createBatch(
                            $validation->eligible,
                            BoxSize::findOrFail($data['box_size_id']),
                            auth()->user(),
                            $data['label_format'] ?: 'pdf',
                            $data['label_dpi'] ? (int) $data['label_dpi'] : null,
                        );

                        redirect(LabelBatchResource::getUrl('view', ['record' => $batch]));
                    })
                    ->deselectRecordsAfterCompletion(),
                Actions\BulkAction::make('validate-addresses')
                    ->label('Validate Addresses')
                    ->icon('heroicon-o-check-badge')
                    ->requiresConfirmation()
                    ->modalHeading('Validate Addresses')
                    ->modalDescription('Queue address validation for all selected shipments. Already-validated shipments will be re-checked.')
                    ->modalSubmitActionLabel('Validate')
                    ->action(function (Collection $records): void {
                        foreach ($records as $shipment) {
                            ValidateAddressJob::dispatch($shipment->id);
                        }

                        $count = $records->count();
                        Notification::make()
                            ->success()
                            ->title("Queued {$count} ".str('shipment')->plural($count).' for address validation')
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    public static function infolist(Schema $infolist): Schema
    {
        return $infolist
            ->columns(2)
            ->schema([
                // Left column — Shipment Details
                Section::make('Shipment Details')
                    ->inlineLabel()
                    ->schema([
                        TextEntry::make('shipment_reference')
                            ->weight(FontWeight::Bold)
                            ->copyable()
                            ->icon('heroicon-o-clipboard')
                            ->iconPosition('after'),
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('channel.name')
                            ->label('Channel')
                            ->icon(fn (Shipment $record): ?string => $record->channel?->icon)
                            ->iconPosition(IconPosition::Before)
                            ->placeholder('—'),
                        TextEntry::make('shippingMethod.name')
                            ->label('Shipping Method')
                            ->placeholder('—'),
                        TextEntry::make('value')
                            ->money('USD'),
                        TextEntry::make('deliver_by')
                            ->label('Deliver By')
                            ->date(timezone: Location::timezone())
                            ->placeholder('—'),
                        TextEntry::make('created_at')
                            ->dateTime('M j, Y g:i A', timezone: Location::timezone()),
                        TextEntry::make('updated_at')
                            ->dateTime('M j, Y g:i A', timezone: Location::timezone()),

                        // Unmapped warnings
                        TextEntry::make('channel_reference')
                            ->label('Unmapped Channel')
                            ->icon('heroicon-o-exclamation-triangle')
                            ->color('warning')
                            ->url('/app/unmapped-channel-references')
                            ->visible(fn (Shipment $record): bool => $record->channel_id === null && filled($record->channel_reference)),

                        TextEntry::make('shipping_method_reference')
                            ->label('Unmapped Shipping Method')
                            ->icon('heroicon-o-exclamation-triangle')
                            ->color('warning')
                            ->url('/app/unmapped-shipping-references')
                            ->visible(fn (Shipment $record): bool => $record->shipping_method_id === null && filled($record->shipping_method_reference)),
                    ]),

                // Right column — Recipient & Address
                Section::make('Recipient & Address')
                    ->inlineLabel()
                    ->schema([
                        TextEntry::make('first_name'),
                        TextEntry::make('last_name'),
                        TextEntry::make('company')
                            ->placeholder('—'),
                        TextEntry::make('phone')
                            ->placeholder('—'),
                        TextEntry::make('email')
                            ->placeholder('—'),

                        Section::make(fn (Shipment $record): string => $record->checked && filled($record->validated_address1)
                            ? 'Shipping Address (Validated)'
                            : 'Shipping Address')
                            ->description(fn (Shipment $record): ?string => $record->checked && filled($record->validated_address1)
                                ? 'Showing USPS-validated address'
                                : 'Not validated')
                            ->icon(fn (Shipment $record): string => $record->checked && filled($record->validated_address1)
                                ? 'heroicon-o-check-badge'
                                : 'heroicon-o-map-pin')
                            ->inlineLabel()
                            ->schema([
                                TextEntry::make('effective_address1')
                                    ->label('Address 1')
                                    ->state(fn (Shipment $record): ?string => $record->checked && filled($record->validated_address1)
                                        ? $record->validated_address1
                                        : $record->address1),
                                TextEntry::make('effective_address2')
                                    ->label('Address 2')
                                    ->state(fn (Shipment $record): ?string => $record->checked && filled($record->validated_address1)
                                        ? $record->validated_address2
                                        : $record->address2)
                                    ->placeholder('—'),
                                TextEntry::make('effective_city')
                                    ->label('City')
                                    ->state(fn (Shipment $record): ?string => $record->checked && filled($record->validated_address1)
                                        ? $record->validated_city
                                        : $record->city),
                                TextEntry::make('effective_state_or_province')
                                    ->label('State or Province')
                                    ->state(fn (Shipment $record): ?string => $record->checked && filled($record->validated_address1)
                                        ? $record->validated_state_or_province
                                        : $record->state_or_province),
                                TextEntry::make('effective_postal_code')
                                    ->label('Postal Code')
                                    ->state(fn (Shipment $record): ?string => $record->checked && filled($record->validated_address1)
                                        ? $record->validated_postal_code
                                        : $record->postal_code),
                                TextEntry::make('country'),
                            ])
                            ->columns(2),

                        TextEntry::make('deliverability')
                            ->label('Deliverability')
                            ->badge()
                            ->placeholder('Not checked'),
                        TextEntry::make('validation_message')
                            ->placeholder('N/A')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationGroup::make('Items & Packages', [
                ShipmentItemsRelationManager::class,
                PackagesRelationManager::class,
            ]),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShipments::route('/'),
            'create' => Pages\CreateShipment::route('/create'),
            'view' => Pages\ViewShipment::route('/{record}'),
            'edit' => Pages\EditShipment::route('/{record}/edit'),
        ];
    }
}
