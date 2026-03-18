<?php

namespace App\Filament\Resources;

use App\Enums\PackageStatus;
use App\Filament\Concerns\InteractsWithScoutSearch;
use App\Filament\Resources\PackageResource\Pages;
use App\Filament\Resources\PackageResource\RelationManagers\PackageItemsRelationManager;
use App\Models\Location;
use App\Models\Package;
use App\Services\Carriers\CarrierRegistry;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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
                            ->disabled(fn (string $operation) => $operation === 'edit'),
                        Forms\Components\TextInput::make('tracking_number')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('shipping_method')
                            ->maxLength(255),
                        Components\Fieldset::make('Dimensions')->schema([
                            Forms\Components\TextInput::make('length')
                                ->numeric()
                                ->minValue(0.01)
                                ->maxValue(999)
                                ->suffix('in'),
                            Forms\Components\TextInput::make('width')
                                ->numeric()
                                ->minValue(0.01)
                                ->maxValue(999)
                                ->suffix('in'),
                            Forms\Components\TextInput::make('height')
                                ->numeric()
                                ->minValue(0.01)
                                ->maxValue(999)
                                ->suffix('in'),
                            Forms\Components\TextInput::make('weight')
                                ->numeric()
                                ->minValue(0.01)
                                ->maxValue(150)
                                ->suffix('lbs'),
                        ]),
                        Forms\Components\TextInput::make('cost')
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
                            ->content(fn (?Package $record) => $record ? trim("{$record->shipment->first_name} {$record->shipment->last_name}") : '—'),
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
            ->modifyQueryUsing(fn (Builder $query) => $query->with('shipment'))
            ->searchable()
            ->searchUsing(function (Builder $query, string $search): void {
                $ids = Package::search($search)->keys()->all();

                if (empty($ids)) {
                    $query->whereRaw('0 = 1');

                    return;
                }

                $query->whereKey($ids);
            })
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable(),
                Tables\Columns\TextColumn::make('shipment.shipment_reference')
                    ->label('Shipment')
                    ->fontFamily('mono')
                    ->size('sm')
                    ->sortable(),
                Tables\Columns\TextColumn::make('tracking_number')
                    ->fontFamily('mono')
                    ->size('sm')
                    ->copyable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('shipping_method')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('weight')
                    ->numeric()
                    ->suffix(' lbs')
                    ->sortable(),
                Tables\Columns\TextColumn::make('cost')
                    ->money('USD')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge(),
                Tables\Columns\IconColumn::make('exported')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('M j, Y g:i A', timezone: Location::timezone())
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(PackageStatus::class),
                Tables\Filters\SelectFilter::make('carrier')
                    ->options([
                        'USPS' => 'USPS',
                        'FedEx' => 'FedEx',
                        'UPS' => 'UPS',
                    ]),
                Tables\Filters\TernaryFilter::make('exported')
                    ->label('Exported')
                    ->trueLabel('Exported')
                    ->falseLabel('Not Exported'),
                Tables\Filters\SelectFilter::make('service')
                    ->options(fn () => \App\Models\Package::query()
                        ->whereNotNull('service')
                        ->distinct()
                        ->orderBy('service')
                        ->pluck('service', 'service')
                        ->toArray())
                    ->searchable(),
                Tables\Filters\TernaryFilter::make('manifested')
                    ->label('Manifested')
                    ->trueLabel('Manifested')
                    ->falseLabel('Not Manifested'),
                Tables\Filters\SelectFilter::make('label_format')
                    ->label('Label Format')
                    ->options([
                        'pdf' => 'PDF',
                        'zpl' => 'ZPL',
                    ]),
                Tables\Filters\Filter::make('shipped_at')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('shipped_from')
                            ->label('Shipped From'),
                        \Filament\Forms\Components\DatePicker::make('shipped_until')
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
                        \Filament\Forms\Components\TextInput::make('cost_from')
                            ->label('Min Cost ($)')
                            ->numeric(),
                        \Filament\Forms\Components\TextInput::make('cost_to')
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
                Actions\Action::make('reprint')
                    ->label('Reprint')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->visible(fn (Package $record) => $record->status === PackageStatus::Shipped && $record->label_data)
                    ->action(function (Package $record, $livewire): void {
                        $livewire->dispatch('print-label', label: $record->label_data, orientation: $record->label_orientation ?? 'portrait', format: $record->label_format ?? 'pdf', dpi: $record->label_dpi);
                    }),
                Actions\Action::make('void')
                    ->label('Void Label')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Void Label')
                    ->modalDescription('This will cancel the label with the carrier. The package will be kept with its dimensions so it can be re-shipped.')
                    ->visible(fn (Package $record) => $record->status === PackageStatus::Shipped && $record->tracking_number && $record->carrier)
                    ->action(function (Package $record): void {
                        try {
                            $adapter = app(CarrierRegistry::class)->get($record->carrier);
                            $response = $adapter->cancelShipment($record->tracking_number, $record);

                            if ($response->success) {
                                $record->clearShipping();
                                Notification::make()->success()->title('Label voided')->body($response->message)->send();
                            } else {
                                Notification::make()->danger()->title('Void failed')->body($response->message)->send();
                            }
                        } catch (\RuntimeException $e) {
                            Notification::make()->danger()->title('State Changed')->body($e->getMessage())->send();
                        } catch (\Saloon\Exceptions\Request\RequestException $e) {
                            Notification::make()->danger()->title('Carrier Error')->body('Unable to connect to carrier. Please try again.')->send();
                        }
                    }),
                Actions\EditAction::make(),
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
