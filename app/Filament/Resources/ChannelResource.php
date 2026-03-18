<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ChannelResource\Pages;
use App\Filament\Resources\ChannelResource\RelationManagers\AliasesRelationManager;
use App\Models\Channel;
use App\Models\Location;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class ChannelResource extends Resource
{
    protected static ?string $model = Channel::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static \UnitEnum|string|null $navigationGroup = 'Settings';

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('channel_reference')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Forms\Components\Select::make('icon')
                    ->options(fn () => collect([
                        'heroicon-o-shopping-bag' => 'Shopping Bag',
                        'heroicon-o-shopping-cart' => 'Shopping Cart',
                        'heroicon-o-building-storefront' => 'Storefront',
                        'heroicon-o-globe-alt' => 'Globe',
                        'heroicon-o-device-phone-mobile' => 'Mobile',
                        'heroicon-o-pencil-square' => 'Manual',
                        'heroicon-o-inbox-stack' => 'Inbox',
                        'heroicon-o-truck' => 'Truck',
                    ])->mapWithKeys(fn (string $label, string $icon) => [
                        $icon => '<span class="flex items-center gap-2">'
                            .svg($icon, 'w-5 h-5')->toHtml()
                            ."<span>{$label}</span></span>",
                    ])->all())
                    ->allowHtml()
                    ->nullable()
                    ->searchable(),
                Forms\Components\Toggle::make('active')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('channel_reference')
                    ->searchable(),
                Tables\Columns\IconColumn::make('active')
                    ->boolean(),
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
            AliasesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListChannels::route('/'),
            'create' => Pages\CreateChannel::route('/create'),
            'edit' => Pages\EditChannel::route('/{record}/edit'),
        ];
    }
}
