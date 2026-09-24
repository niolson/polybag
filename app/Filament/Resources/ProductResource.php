<?php

namespace App\Filament\Resources;

use App\Enums\HazmatClass;
use App\Filament\Concerns\InteractsWithScoutSearch;
use App\Filament\Resources\ProductResource\Pages;
use App\Models\Location;
use App\Models\Product;
use App\Services\SettingsService;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class ProductResource extends Resource
{
    use InteractsWithScoutSearch;

    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static \UnitEnum|string|null $navigationGroup = 'Shipping Config';

    protected static ?string $recordTitleAttribute = 'name';

    protected static int $globalSearchResultsLimit = 10;

    /**
     * @return array<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'SKU' => $record->sku,
        ];
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Forms\Components\Select::make('client_id')
                    ->relationship('client', 'name')
                    ->required()
                    ->visible(fn () => app(SettingsService::class)->get('multi_client_enabled', false)),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('sku')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Forms\Components\TextInput::make('barcode')
                    ->maxLength(255),
                Forms\Components\TextInput::make('weight')
                    ->numeric()
                    ->inputMode('decimal')
                    ->step(0.01)
                    ->minValue(0)
                    ->maxValue(150)
                    ->suffix('lbs')
                    ->helperText('Weight in pounds'),
                Forms\Components\TextInput::make('hs_tariff_number')
                    ->label('HS Tariff Number')
                    ->maxLength(20)
                    ->helperText('For international shipments'),
                Forms\Components\TextInput::make('country_of_origin')
                    ->maxLength(2)
                    ->helperText('2-letter country code (e.g., US, CN)'),
                Forms\Components\TextInput::make('bin_location')
                    ->label('Bin Location')
                    ->maxLength(50)
                    ->helperText('Warehouse bin location (e.g. A-01-3). Used to sort picking summaries.'),
                Forms\Components\Toggle::make('active')
                    ->default(true),
                Section::make('Billing')
                    ->visible(fn () => app(SettingsService::class)->get('multi_client_enabled', false))
                    ->schema([
                        Forms\Components\TextInput::make('handling_surcharge')
                            ->label('Special Handling Surcharge')
                            ->numeric()
                            ->prefix('$')
                            ->step(0.01)
                            ->minValue(0)
                            ->rules(['min:0'])
                            ->placeholder('0.00')
                            ->helperText('Per-unit surcharge for items requiring special handling (fragile, hazmat, kitting, oversize, etc.).')
                            ->columnSpanFull(),
                    ]),
                Section::make('Compliance')
                    ->description('Declarations about the contents that decide which carrier services and special handling a package gets.')
                    // ->collapsed()
                    ->schema([
                        Forms\Components\Toggle::make('contains_alcohol')
                            ->label('Contains Alcohol')
                            ->helperText('Packages containing this product get FedEx rates only (USPS prohibits alcohol; UPS parcel is unsupported), ship with the FedEx alcohol declaration, and automatically require an adult signature.')
                            ->columnSpanFull(),
                        Forms\Components\Toggle::make('is_media')
                            ->label('Media')
                            ->helperText('Declares that this product qualifies for USPS Media Mail. A package is offered Media Mail only when every item in it is marked. Never set automatically: the seller is responsible for what qualifies.')
                            ->columnSpanFull(),
                        Forms\Components\Select::make('hazmat_class')
                            ->label('Hazmat Classification')
                            ->options(HazmatClass::class)
                            ->placeholder('None')
                            ->helperText('Battery classes gate rates to eligible carrier services and add the carrier battery declaration where one exists. USPS battery labels assume packages physically carry the required lithium battery marks. Dry ice and cremated remains are not yet wired to carriers.')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->searchable()
            ->searchUsing(function (Builder $query, string $search): void {
                $ids = Product::search($search)->keys()->all();

                if (empty($ids)) {
                    $query->whereRaw('0 = 1');

                    return;
                }

                $query->whereKey($ids);
            })
            ->columns([
                Tables\Columns\TextColumn::make('client.name')
                    ->label('Client')
                    ->sortable()
                    ->visible(fn () => app(SettingsService::class)->get('multi_client_enabled', false)),
                Tables\Columns\TextColumn::make('name'),
                Tables\Columns\TextColumn::make('sku'),
                Tables\Columns\TextColumn::make('bin_location')
                    ->label('Bin')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('weight')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('handling_surcharge')
                    ->label('Surcharge')
                    ->money('USD')
                    ->sortable()
                    ->placeholder('—')
                    ->visible(fn () => app(SettingsService::class)->get('multi_client_enabled', false)),
                Tables\Columns\IconColumn::make('contains_alcohol')
                    ->label('Alcohol')
                    ->boolean()
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->trueColor('warning')
                    ->falseIcon('')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('is_media')
                    ->label('Media')
                    ->boolean()
                    ->trueIcon('heroicon-o-book-open')
                    ->trueColor('info')
                    ->falseIcon('')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('hazmat_class')
                    ->label('Hazmat')
                    ->badge()
                    ->color('danger')
                    ->formatStateUsing(fn ($state) => $state?->getLabel())
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('M j, Y g:i A', timezone: Location::timezone())
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('client')
                    ->relationship('client', 'name')
                    ->visible(fn () => app(SettingsService::class)->get('multi_client_enabled', false)),
                Tables\Filters\TernaryFilter::make('active')
                    ->label('Active')
                    ->trueLabel('Active')
                    ->falseLabel('Inactive'),
                Tables\Filters\Filter::make('has_weight')
                    ->label('Has Weight')
                    ->query(fn ($query) => $query->whereNotNull('weight')->where('weight', '>', 0)),
                Tables\Filters\Filter::make('missing_weight')
                    ->label('Missing Weight')
                    ->query(fn ($query) => $query->whereNull('weight')->orWhere('weight', '<=', 0)),
                Tables\Filters\TernaryFilter::make('contains_alcohol')
                    ->label('Contains Alcohol'),
                Tables\Filters\TernaryFilter::make('is_media')
                    ->label('Media'),
                Tables\Filters\SelectFilter::make('hazmat_class')
                    ->label('Hazmat Class')
                    ->options(HazmatClass::class),
            ])
            ->recordActions([
                Actions\EditAction::make(),
            ])
            ->groupedBulkActions([
                self::markMediaBulkAction(true),
                self::markMediaBulkAction(false),
            ]);
    }

    /**
     * Mark the selected products as media, or as not media, in one go.
     */
    private static function markMediaBulkAction(bool $isMedia): Actions\BulkAction
    {
        return Actions\BulkAction::make($isMedia ? 'mark-media' : 'mark-not-media')
            ->label($isMedia ? 'Mark as media' : 'Mark as not media')
            ->icon($isMedia ? 'heroicon-o-book-open' : 'heroicon-o-x-circle')
            ->requiresConfirmation()
            ->modalDescription($isMedia
                ? 'Packages whose every item is marked as media may be offered USPS Media Mail. Only mark products that qualify.'
                : 'Packages containing these products will no longer be offered USPS Media Mail.')
            ->authorizeIndividualRecords('update')
            ->action(fn (Collection $records) => $records->each->update(['is_media' => $isMedia]))
            ->successNotificationTitle($isMedia ? 'Marked as media' : 'Marked as not media')
            ->deselectRecordsAfterCompletion();
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
