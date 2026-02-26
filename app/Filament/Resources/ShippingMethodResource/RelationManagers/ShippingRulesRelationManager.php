<?php

namespace App\Filament\Resources\ShippingMethodResource\RelationManagers;

use App\Enums\DestinationZone;
use App\Enums\ShippingRuleAction;
use App\Models\Channel;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class ShippingRulesRelationManager extends RelationManager
{
    protected static string $relationship = 'shippingRules';

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('action')
                    ->options(ShippingRuleAction::class)
                    ->required(),
                Forms\Components\Select::make('carrier_service_id')
                    ->relationship('carrierService', 'name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->carrier->name} — {$record->name}")
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\Toggle::make('enabled')
                    ->default(true),
                Builder::make('conditions')
                    ->blocks([
                        self::weightBlock(),
                        self::orderValueBlock(),
                        self::itemCountBlock(),
                        self::destinationZoneBlock(),
                        self::destinationStateBlock(),
                        self::channelBlock(),
                        self::residentialBlock(),
                    ])
                    ->blockNumbers(false)
                    ->collapsible()
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->reorderable('priority')
            ->defaultSort('priority')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('action')
                    ->badge(),
                Tables\Columns\TextColumn::make('carrierService.name')
                    ->label('Carrier Service')
                    ->formatStateUsing(fn ($state, $record) => "{$record->carrierService->carrier->name} — {$state}"),
                Tables\Columns\ToggleColumn::make('enabled'),
                Tables\Columns\TextColumn::make('conditions_summary')
                    ->label('Conditions')
                    ->getStateUsing(fn ($record) => self::summarizeConditions($record->conditions))
                    ->placeholder('Always'),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->slideOver(),
            ])
            ->recordActions([
                Actions\EditAction::make()
                    ->slideOver(),
                Actions\DeleteAction::make(),
            ])
            ->groupedBulkActions([
                Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function summarizeConditions(mixed $conditions): ?string
    {
        if (empty($conditions)) {
            return null;
        }

        $parts = [];

        foreach ($conditions as $condition) {
            $type = $condition['type'] ?? null;
            $data = $condition['data'] ?? [];

            $summary = match ($type) {
                'weight' => self::summarizeNumeric('Weight', $data, 'lbs'),
                'order_value' => self::summarizeNumeric('Value', $data, '$', prefix: true),
                'item_count' => self::summarizeNumeric('Items', $data),
                'destination_zone' => DestinationZone::tryFrom($data['zone'] ?? '')?->getLabel(),
                'destination_state' => self::summarizeStates($data),
                'channel' => self::summarizeChannel($data),
                'residential' => ($data['is_residential'] ?? false) ? 'Residential' : 'Commercial',
                default => null,
            };

            if ($summary) {
                $parts[] = $summary;
            }
        }

        return implode(', ', $parts) ?: null;
    }

    private static function summarizeNumeric(string $label, array $data, string $unit = '', bool $prefix = false): string
    {
        $operator = $data['operator'] ?? '>=';
        $value = $data['value'] ?? 0;

        if ($operator === 'between') {
            $max = $data['max_value'] ?? $value;

            return $prefix
                ? "{$label} {$unit}{$value}–{$unit}{$max}"
                : "{$label} {$value}–{$max}{$unit}";
        }

        return $prefix
            ? "{$label} {$operator} {$unit}{$value}"
            : "{$label} {$operator} {$value}{$unit}";
    }

    private static function summarizeStates(array $data): string
    {
        $operator = $data['operator'] ?? 'in';
        $states = $data['states'] ?? [];
        $list = implode(', ', array_slice($states, 0, 5));

        if (count($states) > 5) {
            $list .= '...';
        }

        return $operator === 'not_in' ? "Not in {$list}" : "In {$list}";
    }

    private static function summarizeChannel(array $data): string
    {
        $operator = $data['operator'] ?? 'is';
        $channelId = $data['channel_id'] ?? null;
        $channel = $channelId ? Channel::find($channelId)?->name : '?';
        $prefix = $operator === 'is_not' ? 'Not ' : '';

        return "{$prefix}Channel: {$channel}";
    }

    private static function weightBlock(): Block
    {
        return Block::make('weight')
            ->label(function (?array $state): string {
                if ($state === null) {
                    return 'Package Weight';
                }

                return self::summarizeNumeric('Weight', $state, 'lbs');
            })
            ->icon('heroicon-o-scale')
            ->schema([
                Forms\Components\Select::make('operator')
                    ->options([
                        '<=' => 'Less than or equal (<=)',
                        '>=' => 'Greater than or equal (>=)',
                        'between' => 'Between',
                    ])
                    ->default('>=')
                    ->required()
                    ->live(),
                Forms\Components\TextInput::make('value')
                    ->label('Weight (lbs)')
                    ->numeric()
                    ->required(),
                Forms\Components\TextInput::make('max_value')
                    ->label('Max Weight (lbs)')
                    ->numeric()
                    ->visible(fn (Get $get) => $get('operator') === 'between')
                    ->requiredIf('operator', 'between'),
            ])
            ->columns(3);
    }

    private static function orderValueBlock(): Block
    {
        return Block::make('order_value')
            ->label(function (?array $state): string {
                if ($state === null) {
                    return 'Order Value';
                }

                return self::summarizeNumeric('Value', $state, '$', prefix: true);
            })
            ->icon('heroicon-o-currency-dollar')
            ->schema([
                Forms\Components\Select::make('operator')
                    ->options([
                        '<=' => 'Less than or equal (<=)',
                        '>=' => 'Greater than or equal (>=)',
                        'between' => 'Between',
                    ])
                    ->default('>=')
                    ->required()
                    ->live(),
                Forms\Components\TextInput::make('value')
                    ->label('Value ($)')
                    ->numeric()
                    ->required(),
                Forms\Components\TextInput::make('max_value')
                    ->label('Max Value ($)')
                    ->numeric()
                    ->visible(fn (Get $get) => $get('operator') === 'between')
                    ->requiredIf('operator', 'between'),
            ])
            ->columns(3);
    }

    private static function itemCountBlock(): Block
    {
        return Block::make('item_count')
            ->label(function (?array $state): string {
                if ($state === null) {
                    return 'Item Count';
                }

                return self::summarizeNumeric('Items', $state);
            })
            ->icon('heroicon-o-queue-list')
            ->schema([
                Forms\Components\Select::make('operator')
                    ->options([
                        '<=' => 'Less than or equal (<=)',
                        '>=' => 'Greater than or equal (>=)',
                        'between' => 'Between',
                    ])
                    ->default('>=')
                    ->required()
                    ->live(),
                Forms\Components\TextInput::make('value')
                    ->label('Count')
                    ->numeric()
                    ->required(),
                Forms\Components\TextInput::make('max_value')
                    ->label('Max Count')
                    ->numeric()
                    ->visible(fn (Get $get) => $get('operator') === 'between')
                    ->requiredIf('operator', 'between'),
            ])
            ->columns(3);
    }

    private static function destinationZoneBlock(): Block
    {
        return Block::make('destination_zone')
            ->label(function (?array $state): string {
                if ($state === null) {
                    return 'Destination Zone';
                }

                return DestinationZone::tryFrom($state['zone'] ?? '')?->getLabel() ?? 'Destination Zone';
            })
            ->icon('heroicon-o-globe-americas')
            ->schema([
                Forms\Components\Select::make('zone')
                    ->options(DestinationZone::class)
                    ->required(),
            ]);
    }

    private static function destinationStateBlock(): Block
    {
        return Block::make('destination_state')
            ->label(function (?array $state): string {
                if ($state === null) {
                    return 'Destination State';
                }

                return self::summarizeStates($state);
            })
            ->icon('heroicon-o-map-pin')
            ->schema([
                Forms\Components\Select::make('operator')
                    ->options([
                        'in' => 'Is in',
                        'not_in' => 'Is not in',
                    ])
                    ->default('in')
                    ->required(),
                Forms\Components\Select::make('states')
                    ->multiple()
                    ->searchable()
                    ->options(self::usStateOptions())
                    ->required(),
            ]);
    }

    private static function channelBlock(): Block
    {
        return Block::make('channel')
            ->label(function (?array $state): string {
                if ($state === null) {
                    return 'Sales Channel';
                }

                return self::summarizeChannel($state);
            })
            ->icon('heroicon-o-shopping-bag')
            ->schema([
                Forms\Components\Select::make('operator')
                    ->options([
                        'is' => 'Is',
                        'is_not' => 'Is not',
                    ])
                    ->default('is')
                    ->required(),
                Forms\Components\Select::make('channel_id')
                    ->label('Channel')
                    ->options(fn () => Channel::pluck('name', 'id'))
                    ->searchable()
                    ->required(),
            ]);
    }

    private static function residentialBlock(): Block
    {
        return Block::make('residential')
            ->label(function (?array $state): string {
                if ($state === null) {
                    return 'Residential / Commercial';
                }

                return ($state['is_residential'] ?? false) ? 'Residential' : 'Commercial';
            })
            ->icon('heroicon-o-home')
            ->schema([
                Forms\Components\Toggle::make('is_residential')
                    ->label('Is Residential?')
                    ->default(true),
            ]);
    }

    private static function usStateOptions(): array
    {
        return [
            'Northeast' => [
                'CT' => 'CT', 'DE' => 'DE', 'ME' => 'ME', 'MD' => 'MD',
                'MA' => 'MA', 'NH' => 'NH', 'NJ' => 'NJ', 'NY' => 'NY',
                'PA' => 'PA', 'RI' => 'RI', 'VT' => 'VT',
            ],
            'Southeast' => [
                'AL' => 'AL', 'AR' => 'AR', 'FL' => 'FL', 'GA' => 'GA',
                'KY' => 'KY', 'LA' => 'LA', 'MS' => 'MS', 'NC' => 'NC',
                'SC' => 'SC', 'TN' => 'TN', 'VA' => 'VA', 'WV' => 'WV',
            ],
            'Midwest' => [
                'IL' => 'IL', 'IN' => 'IN', 'IA' => 'IA', 'KS' => 'KS',
                'MI' => 'MI', 'MN' => 'MN', 'MO' => 'MO', 'NE' => 'NE',
                'ND' => 'ND', 'OH' => 'OH', 'SD' => 'SD', 'WI' => 'WI',
            ],
            'West' => [
                'AZ' => 'AZ', 'CO' => 'CO', 'ID' => 'ID', 'MT' => 'MT',
                'NV' => 'NV', 'NM' => 'NM', 'OK' => 'OK', 'OR' => 'OR',
                'TX' => 'TX', 'UT' => 'UT', 'WA' => 'WA', 'WY' => 'WY',
            ],
            'Pacific' => [
                'CA' => 'CA',
            ],
            'Non-Continental' => [
                'AK' => 'AK', 'HI' => 'HI',
            ],
            'Territories' => [
                'AS' => 'AS', 'DC' => 'DC', 'GU' => 'GU', 'MP' => 'MP',
                'PR' => 'PR', 'VI' => 'VI',
            ],
        ];
    }
}
