<?php

namespace App\Filament\Resources;

use App\Enums\Role;
use App\Filament\Concerns\InteractsWithScoutSearch;
use App\Filament\Resources\ShipmentResource\Pages;
use App\Filament\Resources\ShipmentResource\RelationManagers\PackagesRelationManager;
use App\Filament\Resources\ShipmentResource\RelationManagers\ShipmentItemsRelationManager;
use App\Models\Shipment;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Resources\Resource;
use Filament\Schemas\Components;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ShipmentResource extends Resource
{
    use InteractsWithScoutSearch;

    protected static ?string $model = Shipment::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static ?string $recordTitleAttribute = 'shipment_reference';

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
            'Location' => trim("{$record->city}, {$record->state}"),
        ];
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Components\Section::make('Reference')
                    ->schema([
                        Forms\Components\TextInput::make('shipment_reference')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Select::make('shipping_method_id')
                            ->relationship('shippingMethod', 'name')
                            ->required(),
                        Forms\Components\Select::make('channel_id')
                            ->relationship('channel', 'name')
                            ->required(),
                    ])->columns(3),
                Components\Section::make('Recipient')
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
                    ])->columns(3),
                Components\Section::make('Address')
                    ->schema([
                        Forms\Components\TextInput::make('address1')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('address2')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('city')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('state')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('zip')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('country')
                            ->required()
                            ->maxLength(255)
                            ->default('US'),
                    ])->columns(3),
                Components\Section::make('Value')
                    ->schema([
                        Forms\Components\TextInput::make('value')
                            ->numeric()
                            ->prefix('$'),
                    ])->columns(1),
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
                Tables\Columns\TextColumn::make('shipment_reference'),
                Tables\Columns\TextColumn::make('first_name'),
                Tables\Columns\TextColumn::make('last_name'),
                Tables\Columns\TextColumn::make('company'),
                Tables\Columns\TextColumn::make('shippingMethod.name')
                    ->label('Shipping Method'),
                Tables\Columns\TextColumn::make('deliverability')
                    ->label('Deliverable')
                    ->badge()
                    ->placeholder('Not checked'),
                Tables\Columns\IconColumn::make('shipped')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('shipped')
                    ->label('Shipped')
                    ->trueLabel('Shipped')
                    ->falseLabel('Not Shipped'),
                Tables\Filters\SelectFilter::make('deliverability')
                    ->options(\App\Enums\Deliverability::class)
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
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Actions\ViewAction::make(),
                Actions\EditAction::make(),
            ])
            ->groupedBulkActions([
                Actions\DeleteBulkAction::make()
                    ->visible(fn () => auth()->user()->role->isAtLeast(Role::Manager)),
            ]);
    }

    public static function infolist(Schema $infolist): Schema
    {
        return $infolist
            ->schema([
                Components\Section::make('Shipment Details')
                    ->columnSpan(2)
                    ->schema([
                        TextEntry::make('shipment_reference'),
                        TextEntry::make('first_name'),
                        TextEntry::make('last_name'),
                        TextEntry::make('company'),
                        TextEntry::make('deliverability')
                            ->label('Deliverable')
                            ->badge()
                            ->placeholder('Not checked'),
                        TextEntry::make('validation_message')
                            ->placeholder('N/A')
                            ->columnSpanFull(),
                        Components\Grid::make(2)->schema([
                            Components\Section::make('Address')
                                ->schema([
                                    TextEntry::make('address1'),
                                    TextEntry::make('address2'),
                                    TextEntry::make('city'),
                                    TextEntry::make('state'),
                                    TextEntry::make('zip'),
                                ]),
                            Components\Section::make('Validated Address')
                                ->schema([
                                    TextEntry::make('validated_address1'),
                                    TextEntry::make('validated_address2'),
                                    TextEntry::make('validated_city'),
                                    TextEntry::make('validated_state'),
                                    TextEntry::make('validated_zip'),
                                ]),
                        ]),
                        TextEntry::make('country'),
                        TextEntry::make('phone'),
                        TextEntry::make('phone_extension')
                            ->label('Phone Ext.'),
                        TextEntry::make('email'),
                        TextEntry::make('value')
                            ->money('USD'),
                        TextEntry::make('shippingMethod.name'),
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
