<?php

namespace App\Filament\Resources;

use App\Contracts\PackageLabelWorkflow;
use App\Enums\PackageStatus;
use App\Enums\PostageSource;
use App\Enums\TrackingStatus;
use App\Filament\Concerns\InteractsWithScoutSearch;
use App\Filament\Resources\PackageResource\Pages;
use App\Filament\Resources\PackageResource\RelationManagers\PackageItemsRelationManager;
use App\Filament\Support\CarrierLogoColumn;
use App\Models\Client;
use App\Models\Location;
use App\Models\Package;
use App\Services\SettingsService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use App\Services\ShipmentImport\Sources\ShopifySource;
use App\Services\TrackingService;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class PackageResource extends Resource
{
    use InteractsWithScoutSearch;

    protected static ?string $model = Package::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static \UnitEnum|string|null $navigationGroup = 'Manage';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'tracking_number';

    protected static int $globalSearchResultsLimit = 10;

    protected static ?bool $shouldSplitGlobalSearchTerms = false;

    /**
     * @return array<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['tracking_number'];
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([
            'Carrier' => $record->carrier,
            'Shipment' => $record->shipment?->shipment_reference,
        ]);
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('shipment');
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->columns(2)
            ->schema([
                // Left column — Package Details
                Components\Section::make('Package Details')
                    ->inlineLabel()
                    ->schema([
                        Forms\Components\Select::make('shipment_id')
                            ->relationship('shipment', 'shipment_reference')
                            ->searchable()
                            ->required()
                            ->disabled(fn (string $operation): bool => $operation === 'edit'),
                        TextInput::make('tracking_number')
                            ->maxLength(255),
                        TextInput::make('shipping_method')
                            ->maxLength(255),
                        Components\Fieldset::make('Dimensions')->schema([
                            TextInput::make('length')
                                ->numeric()
                                ->minValue(0.01)
                                ->maxValue(999)
                                ->suffix('in'),
                            TextInput::make('width')
                                ->numeric()
                                ->minValue(0.01)
                                ->maxValue(999)
                                ->suffix('in'),
                            TextInput::make('height')
                                ->numeric()
                                ->minValue(0.01)
                                ->maxValue(999)
                                ->suffix('in'),
                            TextInput::make('weight')
                                ->numeric()
                                ->minValue(0.01)
                                ->maxValue(150)
                                ->suffix('lbs'),
                        ]),
                        TextInput::make('cost')
                            ->numeric()
                            ->prefix('$'),
                        Forms\Components\Select::make('status')
                            ->options(PackageStatus::class),
                        Forms\Components\Toggle::make('exported'),
                    ]),

                // Right column — Ship To (read-only context from shipment)
                Components\Section::make('Ship To')
                    ->inlineLabel()
                    ->schema([
                        Forms\Components\Placeholder::make('ship_to_name')
                            ->label('Name')
                            ->content(fn (?Package $record): string => $record ? trim("{$record->shipment->first_name} {$record->shipment->last_name}") : '—'),
                        Forms\Components\Placeholder::make('ship_to_company')
                            ->label('Company')
                            ->content(fn (?Package $record) => $record?->shipment->company ?: '—'),
                        Forms\Components\Placeholder::make('ship_to_address')
                            ->label('Address')
                            ->content(fn (?Package $record) => $record?->shipment->address1 ?? '—'),
                        Forms\Components\Placeholder::make('ship_to_address2')
                            ->label('Address 2')
                            ->content(fn (?Package $record) => $record?->shipment->address2 ?: '—'),
                        Forms\Components\Placeholder::make('ship_to_city')
                            ->label('City')
                            ->content(fn (?Package $record) => $record?->shipment->city ?? '—'),
                        Forms\Components\Placeholder::make('ship_to_state_or_province')
                            ->label('State/Province')
                            ->content(fn (?Package $record) => $record?->shipment->state_or_province ?? '—'),
                        Forms\Components\Placeholder::make('ship_to_postal_code')
                            ->label('Postal Code')
                            ->content(fn (?Package $record) => $record?->shipment->postal_code ?? '—'),
                        Forms\Components\Placeholder::make('ship_to_country')
                            ->label('Country')
                            ->content(fn (?Package $record) => $record?->shipment->country ?? '—'),
                    ])
                    ->visible(fn (?Package $record): bool => $record !== null),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(array_filter([
                'shipment',
                'postageDataSource',
                app(SettingsService::class)->get('multi_client_enabled', false) ? 'shipment.client' : null,
                app(SettingsService::class)->get('multi_location_enabled', false) ? 'location' : null,
            ])))
            ->searchable()
            ->searchUsing(function (Builder $query, string $search): void {
                $ids = Package::search($search)->keys()->all();

                if (empty($ids)) {
                    $query->whereRaw('0 = 1');

                    return;
                }

                $query->whereKey($ids);
            })
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable(),
                Tables\Columns\TextColumn::make('shipment.shipment_reference')
                    ->label('Shipment')
                    ->fontFamily('mono')
                    ->size('sm')
                    ->sortable(),
                Tables\Columns\TextColumn::make('shipment.client.name')
                    ->label('Client')
                    ->placeholder('—')
                    ->visible(fn () => app(SettingsService::class)->get('multi_client_enabled', false)),
                Tables\Columns\TextColumn::make('location.name')
                    ->label('Location')
                    ->placeholder('—')
                    ->visible(fn () => app(SettingsService::class)->get('multi_location_enabled', false)),
                Tables\Columns\TextColumn::make('tracking_number')
                    ->fontFamily('mono')
                    ->size('sm')
                    ->copyable()
                    ->placeholder('—'),
                CarrierLogoColumn::make('carrier')
                    ->placeholder('—')
                    ->description(fn (Package $record): ?string => match (true) {
                        $record->isShopifyShipped() => 'via Shopify Shipping',
                        $record->isAmazonShipped() => 'via Amazon Buy Shipping',
                        default => null,
                    }),
                Tables\Columns\TextColumn::make('service')
                    ->placeholder('—')
                    // A blank service is a fact, not missing data: Shopify never
                    // reports what it bought. Show what was asked for instead,
                    // labelled as the request it was (ADR-0003 decision 7).
                    ->description(fn (Package $record): ?string => $record->service === null
                        && filled($record->requested_service)
                            ? 'requested '.$record->requested_service
                            : null),
                Tables\Columns\TextColumn::make('weight')
                    ->numeric()
                    ->suffix(' lbs')
                    ->sortable(),
                Tables\Columns\TextColumn::make('cost')
                    ->money('USD')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge(),
                Tables\Columns\TextColumn::make('tracking_status')
                    ->badge()
                    ->placeholder('—'),
                Tables\Columns\IconColumn::make('label_printed_at')
                    ->label('Printed')
                    ->boolean()
                    ->tooltip(fn (Package $record): string => $record->label_printed_at
                        ? 'Last printed '.$record->label_printed_at->tz(Location::timezone())->format('M j, Y g:i A')
                        : 'Not printed'),
                Tables\Columns\IconColumn::make('exported')
                    ->boolean(),
                Tables\Columns\TextColumn::make('tracking_updated_at')
                    ->dateTime('M j, Y g:i A', timezone: Location::timezone())
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('M j, Y g:i A', timezone: Location::timezone())
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('client')
                    ->label('Client')
                    ->options(fn () => Client::orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn ($q, $value) => $q->whereHas('shipment', fn ($q) => $q->where('client_id', $value))
                    ))
                    ->visible(fn () => app(SettingsService::class)->get('multi_client_enabled', false)),
                Tables\Filters\SelectFilter::make('location')
                    ->relationship('location', 'name')
                    ->label('Location')
                    ->preload()
                    ->visible(fn () => app(SettingsService::class)->get('multi_location_enabled', false)),
                Tables\Filters\SelectFilter::make('status')
                    ->options(PackageStatus::class),
                Tables\Filters\SelectFilter::make('tracking_status')
                    ->options(TrackingStatus::class)
                    ->label('Tracking Status'),
                Tables\Filters\SelectFilter::make('carrier')
                    ->options([
                        'USPS' => 'USPS',
                        'FedEx' => 'FedEx',
                        'UPS' => 'UPS',
                        'shopify_shipping' => 'Shopify Shipping',
                        'amazon_buy_shipping' => 'Amazon Buy Shipping',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        $postageSourceType = match ($value) {
                            'shopify_shipping' => ShopifySource::class,
                            'amazon_buy_shipping' => AmazonSource::class,
                            default => null,
                        };

                        if ($postageSourceType !== null) {
                            return $query
                                ->where('postage_source', PostageSource::PostageDataSource)
                                ->whereHas(
                                    'postageDataSource',
                                    fn (Builder $query): Builder => $query->where('source_type', $postageSourceType),
                                );
                        }

                        return $query->when($value, fn (Builder $query, string $value): Builder => $query->where('carrier', $value));
                    }),
                Tables\Filters\TernaryFilter::make('exported')
                    ->label('Exported')
                    ->trueLabel('Exported')
                    ->falseLabel('Not Exported'),
                Tables\Filters\SelectFilter::make('service')
                    ->options(fn () => Package::query()
                        ->whereNotNull('service')
                        ->distinct()
                        ->orderBy('service')
                        ->pluck('service', 'service')
                        ->toArray())
                    ->searchable(),
                Tables\Filters\TernaryFilter::make('manifested')
                    ->label('Manifested')
                    ->trueLabel('Manifested')
                    ->falseLabel('Not Manifested')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('manifest_id'),
                        false: fn ($query) => $query->whereNull('manifest_id'),
                    ),
                Tables\Filters\TernaryFilter::make('label_printed')
                    ->label('Label Printed')
                    ->trueLabel('Printed')
                    ->falseLabel('Not Printed')
                    ->queries(
                        // "Not printed" is scoped to shipped packages — an unshipped
                        // package has no label to print, so listing it is just noise.
                        true: fn ($query) => $query->whereNotNull('label_printed_at'),
                        false: fn ($query) => $query->whereNull('label_printed_at')
                            ->where('status', PackageStatus::Shipped),
                    ),
                Tables\Filters\SelectFilter::make('label_format')
                    ->label('Label Format')
                    ->options([
                        'pdf' => 'PDF',
                        'zpl' => 'ZPL',
                        'image' => 'Image',
                    ]),
                Tables\Filters\Filter::make('shipped_at')
                    ->form([
                        DatePicker::make('shipped_from')
                            ->label('Shipped From'),
                        DatePicker::make('shipped_until')
                            ->label('Shipped Until'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['shipped_from'], fn ($query, $date) => $query->whereDate('shipped_at', '>=', $date))
                            ->when($data['shipped_until'], fn ($query, $date) => $query->whereDate('shipped_at', '<=', $date));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['shipped_from'] ?? null) {
                            $indicators['shipped_from'] = 'Shipped from '.$data['shipped_from'];
                        }
                        if ($data['shipped_until'] ?? null) {
                            $indicators['shipped_until'] = 'Shipped until '.$data['shipped_until'];
                        }

                        return $indicators;
                    }),
                Tables\Filters\Filter::make('cost_range')
                    ->form([
                        TextInput::make('cost_from')
                            ->label('Min Cost ($)')
                            ->numeric(),
                        TextInput::make('cost_to')
                            ->label('Max Cost ($)')
                            ->numeric(),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['cost_from'], fn ($query, $val) => $query->where('cost', '>=', $val))
                            ->when($data['cost_to'], fn ($query, $val) => $query->where('cost', '<=', $val));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['cost_from'] ?? null) {
                            $indicators['cost_from'] = 'Cost ≥ $'.$data['cost_from'];
                        }
                        if ($data['cost_to'] ?? null) {
                            $indicators['cost_to'] = 'Cost ≤ $'.$data['cost_to'];
                        }

                        return $indicators;
                    }),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->recordActions([
                Actions\ViewAction::make(),
                static::makeTrackAction(),
                Actions\Action::make('reprint')
                    ->label(fn (Package $record): string => $record->label_printed_at ? 'Reprint' : 'Print')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->visible(fn (Package $record): bool => $record->status === PackageStatus::Shipped && $record->label_data)
                    ->action(fn (Package $record, $livewire) => $livewire->printStoredPackageLabel($record->id)),
                Actions\Action::make('void')
                    ->label('Void Label')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Void Label')
                    ->modalDescription('This will cancel the label with the carrier. The package will be kept with its dimensions so it can be re-shipped.')
                    ->visible(fn (Package $record): bool => $record->status === PackageStatus::Shipped
                        && $record->tracking_number
                        && ($record->carrier || $record->isShopifyShipped()))
                    // Shopify's API has no void operation at all, so offering a
                    // live button here would only ever produce a failure.
                    ->disabled(fn (Package $record): bool => $record->isShopifyShipped())
                    ->tooltip(fn (Package $record): ?string => $record->isShopifyShipped()
                        ? 'Bought through Shopify Shipping — void and refund it in the Shopify admin. PolyBag un-ships the package once Shopify reports the label voided.'
                        : null)
                    ->action(function (Package $record): void {
                        $result = app(PackageLabelWorkflow::class)->voidLabel($record);

                        $notification = Notification::make()
                            ->title($result->title)
                            ->body($result->message);

                        $result->success
                            ? $notification->success()->send()
                            : $notification->danger()->send();
                    }),
                Actions\EditAction::make(),
            ]);
    }

    public static function makeTrackAction(): Actions\Action
    {
        return Actions\Action::make('track')
            ->label('Track')
            ->icon('heroicon-o-map')
            ->color('primary')
            ->visible(fn (Package $record): bool => $record->status === PackageStatus::Shipped && filled($record->tracking_number) && filled($record->carrier))
            ->slideOver()
            ->modalWidth('3xl')
            ->close(false)
            ->modalSubmitActionLabel('Refresh Tracking')
            ->modalCancelActionLabel('Close')
            ->action(function (Actions\Action $action, Package $record): void {
                app(TrackingService::class)->refreshPackage($record->fresh());

                $action->halt();
            })
            ->schema([
                Html::make(fn (Package $record): HtmlString => new HtmlString(
                    view('filament.components.package-tracking', ['package' => $record->fresh()])->render()
                ))->columnSpanFull(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            PackageItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPackages::route('/'),
            'create' => Pages\CreatePackage::route('/create'),
            'view' => Pages\ViewPackage::route('/{record}'),
            'edit' => Pages\EditPackage::route('/{record}/edit'),
        ];
    }
}
