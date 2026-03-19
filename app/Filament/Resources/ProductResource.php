<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\InteractsWithScoutSearch;
use App\Filament\Resources\ProductResource\Pages;
use App\Models\Location;
use App\Models\Product;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ProductResource extends Resource
{
    use InteractsWithScoutSearch;

    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static \UnitEnum|string|null $navigationGroup = 'Settings';

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
                Forms\Components\Toggle::make('active')
                    ->default(true),
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
                Tables\Columns\TextColumn::make('name'),
                Tables\Columns\TextColumn::make('sku'),
                Tables\Columns\TextColumn::make('weight')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\IconColumn::make('active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('M j, Y g:i A', timezone: Location::timezone())
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
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
            ])
            ->recordActions([
                Actions\EditAction::make(),
            ]);
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
