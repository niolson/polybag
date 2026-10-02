<?php

namespace App\Filament\Resources;

use App\Enums\OtdrProtectedOrders;
use App\Enums\PostageSourceKind;
use App\Filament\Resources\ShippingMethodResource\Pages;
use App\Filament\Resources\ShippingMethodResource\RelationManagers\AliasesRelationManager;
use App\Filament\Resources\ShippingMethodResource\RelationManagers\CarrierServicesRelationManager;
use App\Filament\Resources\ShippingMethodResource\RelationManagers\PostageSourcesRelationManager;
use App\Filament\Resources\ShippingMethodResource\RelationManagers\ShippingRulesRelationManager;
use App\Filament\Resources\ShippingMethodResource\RelationManagers\SpecialServicesRelationManager;
use App\Models\DataSource;
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

    protected static \UnitEnum|string|null $navigationGroup = 'Shipping Config';

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('commitment_days')
                    ->numeric()
                    ->live(onBlur: true),
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
                        Forms\Components\Toggle::make('excludes_late_rates')
                            ->label('Exclude rates that deliver after the due-by date')
                            ->default(true)
                            ->live()
                            ->helperText('Automated purchases skip any rate whose delivery date is after the shipment\'s due-by date, or that gives no delivery date. A Shopify Shipping purchase has no delivery date and is bought anyway when a shipping rule names it or it is the method\'s only choice. With this off, the cheapest late rate is bought when nothing arrives on time.'),
                        Forms\Components\CheckboxList::make('otdr_protection_orders')
                            ->label('Require OTDR protection for')
                            ->options(OtdrProtectedOrders::class)
                            ->helperText('For the Amazon orders ticked, only buy offers marked OTDR Protected. A protected label that arrives late does not count against your on-time delivery rate. Requires Shipping Settings Automation and Average Handling Time automation in Seller Central. Without them no offer is protected, and every order this applies to goes to a person.')
                            ->visible(fn (): bool => DataSource::hasActiveAmazonConnection()),
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
