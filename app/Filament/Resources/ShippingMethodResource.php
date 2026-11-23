<?php

namespace App\Filament\Resources;

use App\Enums\PostageSourceKind;
use App\Filament\Resources\ShippingMethodResource\Pages;
use App\Filament\Resources\ShippingMethodResource\RelationManagers\AliasesRelationManager;
use App\Filament\Resources\ShippingMethodResource\RelationManagers\CarrierServicesRelationManager;
use App\Filament\Resources\ShippingMethodResource\RelationManagers\PostageSourcesRelationManager;
use App\Filament\Resources\ShippingMethodResource\RelationManagers\ShippingRulesRelationManager;
use App\Filament\Resources\ShippingMethodResource\RelationManagers\SpecialServicesRelationManager;
use App\Models\Location;
use App\Models\ShippingMethod;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class ShippingMethodResource extends Resource
{
    protected static ?string $model = ShippingMethod::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-paper-airplane';

    protected static \UnitEnum|string|null $navigationGroup = 'Shipping Setup';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('commitment_days')
                    ->label('Delivery Commitment (Business Days)')
                    ->numeric()
                    ->minValue(1)
                    ->live(onBlur: true)
                    ->helperText('Sets the due-by date for shipments whose order does not give a deliver-by date, counted in business days from when the label is bought. Automated purchases buy the cheapest rate that arrives by the due-by date. Leave blank for no due-by date beyond what the order gives.'),
                Forms\Components\Toggle::make('active')
                    ->default(true),
                Forms\Components\Toggle::make('is_expedited')
                    ->label('Expedited')
                    ->helperText('When enabled, shipments with this method are prioritized in auto-generated pick batches.')
                    ->default(false),
                Section::make('Automated Purchases')
                    ->description('What batch ship, auto-ship and shipping rules insist on before buying a label for an order on this method. A person on the Ship page sees every rate, marked, and can still choose any of them.')
                    ->schema([
                        // `project-review/20`: a rule naming Shopify, or Shopify
                        // being the method's only choice, is the operator's
                        // consent to a blind purchase, and it wins over the
                        // on-time requirement. Say so where the requirement is set.
                        Callout::make('Shopify Shipping is bought without a delivery date')
                            ->key('shopify_due_by_notice')
                            ->warning()
                            ->description('When a shipping rule names Shopify Shipping, or it is this method\'s only choice, automated purchases buy it even for an order with a due-by date, and Shopify may choose a service that arrives late. Shopify keeps the buyer\'s checkout delivery method where it can, then the shop\'s preferred carrier and service, so set those in the Shopify admin.')
                            ->visible(fn (?ShippingMethod $record, Get $get): bool => self::warnsOfUndatedShopifyPurchases($record, $get)),
                        // `excludes_late_rates` only decides the fallback: an
                        // on-time rate always outranks a late one whenever
                        // there is a due-by date (RateSelector::classify()).
                        Forms\Components\Radio::make('excludes_late_rates')
                            ->label('When no rate arrives by the due-by date')
                            ->boolean(
                                trueLabel: 'Leave it for a person to ship',
                                falseLabel: 'Buy the cheapest late rate',
                            )
                            ->default(true)
                            ->live()
                            ->helperText(fn (Get $get): string => 'Applies only to a shipment with a due-by date, from the order or from the delivery commitment above. Rates that give no delivery date count as late. '
                                .'Amazon orders with no due-by date are always left for a person under the first choice.'
                                .(filled($get('commitment_days')) ? '' : ' With no delivery commitment, this applies only to orders that give their own deliver-by date.')),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Whether the method allows Shopify Shipping and its orders can have a
     * due-by date that automation will not hold a blind purchase to.
     */
    private static function warnsOfUndatedShopifyPurchases(?ShippingMethod $record, Get $get): bool
    {
        if ($record === null || ! $record->postageSources()->where('source_kind', PostageSourceKind::Shopify)->exists()) {
            return false;
        }

        return filled($get('commitment_days')) || (bool) $get('excludes_late_rates');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('commitment_days')
                    ->label('Delivery Commitment (Days)')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\IconColumn::make('active')
                    ->boolean(),
                Tables\Columns\IconColumn::make('is_expedited')
                    ->label('Expedited')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('M j, Y g:i A', timezone: Location::timezone())
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Actions\EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            CarrierServicesRelationManager::class,
            SpecialServicesRelationManager::class,
            AliasesRelationManager::class,
            PostageSourcesRelationManager::class,
            ShippingRulesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShippingMethods::route('/'),
            'create' => Pages\CreateShippingMethod::route('/create'),
            'edit' => Pages\EditShippingMethod::route('/{record}/edit'),
        ];
    }
}
