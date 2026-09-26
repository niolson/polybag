<?php

namespace App\Filament\Resources\ShippingMethodResource\RelationManagers;

use App\Enums\AmazonOrderProgram;
use App\Enums\DestinationZone;
use App\Enums\ShippingRuleAction;
use App\Enums\ShippingRuleSource;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\Channel;
use App\Models\ShippingMethod;
use App\Models\ShippingRule;
use App\Services\PostageSources\MethodSourceAllowance;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

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
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('source', null);
                        $set('carrier_id', null);
                        $set('any_service', false);
                    }),
                Forms\Components\Select::make('source')
                    ->helperText('Where a Use rule buys, or which purchases an Exclude rule matches. A Use rule picks within what this method allows.')
                    ->options(fn (Get $get): array => $this->sourceOptions(self::actionFrom($get)))
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('carrier_service_id', null);
                        $set('any_service', false);
                    }),
                Forms\Components\Select::make('carrier_id')
                    ->label('Carrier')
                    ->helperText('Matches every offer this carrier carries, including services nobody has mapped.')
                    ->options(fn (): array => Carrier::query()->orderBy('name')->get()->mapWithKeys(fn (Carrier $carrier): array => [$carrier->id => $carrier->label()])->all())
                    ->searchable()
                    ->live()
                    // A rate has one carrier, so a service of another carrier
                    // would leave the rule matching nothing.
                    ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                        $serviceId = $get('carrier_service_id');

                        if ($state !== null && $serviceId !== null && (int) CarrierService::whereKey($serviceId)->value('carrier_id') !== (int) $state) {
                            $set('carrier_service_id', null);
                        }
                    })
                    ->visible(fn (Get $get): bool => self::actionFrom($get) === ShippingRuleAction::ExcludeService)
                    // An Exclude rule must name something: with any source and
                    // any service, the carrier is all that is left.
                    ->required(fn (Get $get): bool => self::sourceFrom($get) === ShippingRuleSource::Any && (bool) $get('any_service')),
                Forms\Components\Toggle::make('any_service')
                    ->label(fn (Get $get): string => self::actionFrom($get) === ShippingRuleAction::UseService && self::sourceFrom($get) === ShippingRuleSource::Shopify
                        ? "Shopify's choice (auto)"
                        : 'Any service')
                    ->live()
                    ->visible(fn (Get $get): bool => $this->allowsAnyService(self::actionFrom($get), self::sourceFrom($get))),
                Forms\Components\Select::make('carrier_service_id')
                    ->label('Service')
                    ->options(fn (Get $get): array => $this->serviceOptions(self::sourceFrom($get), self::carrierIdFrom($get)))
                    ->searchable()
                    ->hidden(fn (Get $get): bool => (bool) $get('any_service'))
                    ->required(fn (Get $get): bool => ! $get('any_service')),
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
                        self::amazonProgramBlock(),
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
                Tables\Columns\TextColumn::make('target')
                    ->label('Source and service')
                    ->state(fn (ShippingRule $record): string => $record->describeTarget()),
                Tables\Columns\ToggleColumn::make('enabled'),
                Tables\Columns\TextColumn::make('conditions_summary')
                    ->label('Conditions')
                    ->getStateUsing(fn ($record): ?string => self::summarizeConditions($record->conditions))
                    ->placeholder('Always'),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->mutateDataUsing(fn (array $data): array => $this->normalizeTarget($data))
                    ->slideOver(),
            ])
            ->recordActions([
                Actions\EditAction::make()
                    ->mutateDataUsing(fn (array $data): array => $this->normalizeTarget($data))
                    ->slideOver(),
                Actions\DeleteAction::make(),
            ])
            ->groupedBulkActions([
                Actions\DeleteBulkAction::make(),
            ]);
    }

    /**
     * Clear what the chosen action and source cannot say, so a field hidden
     * after it was filled is never saved.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function normalizeTarget(array $data): array
    {
        $action = self::asAction($data['action'] ?? null);
        $source = self::asSource($data['source'] ?? null);

        $data['any_service'] = $this->allowsAnyService($action, $source) && ($data['any_service'] ?? false);

        if ($data['any_service']) {
            $data['carrier_service_id'] = null;
        }

        if ($action !== ShippingRuleAction::ExcludeService) {
            $data['carrier_id'] = null;
        }

        return $data;
    }

    /**
     * @return array<string, string>
     */
    private function sourceOptions(?ShippingRuleAction $action): array
    {
        if ($action === null) {
            return [];
        }

        return collect(app(MethodSourceAllowance::class)->ruleSourcesFor($this->method(), $action))
            ->mapWithKeys(fn (ShippingRuleSource $source): array => [$source->value => $source->getLabel()])
            ->all();
    }

    /**
     * Only the method's services: a rule picks within what the method allows.
     *
     * @return array<int, string>
     */
    private function serviceOptions(?ShippingRuleSource $source, ?int $carrierId): array
    {
        return app(MethodSourceAllowance::class)
            ->serviceOptionsFor($this->method(), $source)
            ->when($carrierId !== null, fn (Collection $services): Collection => $services->where('carrier_id', $carrierId))
            ->mapWithKeys(fn (CarrierService $service): array => [$service->id => "{$service->carrier->label()} — {$service->name}"])
            ->all();
    }

    private function method(): ShippingMethod
    {
        /** @var ShippingMethod */
        return $this->getOwnerRecord();
    }

    /**
     * An *Exclude* rule may leave the service open. A *Use* rule may only for
     * Amazon Buy Shipping, whose services are discovered per quote, or for
     * Shopify when this method allows its own choice (`auto`).
     */
    private function allowsAnyService(?ShippingRuleAction $action, ?ShippingRuleSource $source): bool
    {
        return $action === ShippingRuleAction::ExcludeService
            || ($action === ShippingRuleAction::UseService && app(MethodSourceAllowance::class)->allowsAnyServiceFor($this->method(), $source));
    }

    private static function actionFrom(Get $get): ?ShippingRuleAction
    {
        return self::asAction($get('action'));
    }

    /**
     * The carrier an *Exclude* rule names; a *Use* rule names none.
     */
    private static function carrierIdFrom(Get $get): ?int
    {
        $carrierId = $get('carrier_id');

        return self::actionFrom($get) === ShippingRuleAction::ExcludeService && filled($carrierId) ? (int) $carrierId : null;
    }

    private static function sourceFrom(Get $get): ?ShippingRuleSource
    {
        return self::asSource($get('source'));
    }

    private static function asAction(mixed $action): ?ShippingRuleAction
    {
        return $action instanceof ShippingRuleAction ? $action : ShippingRuleAction::tryFrom((string) $action);
    }

    private static function asSource(mixed $source): ?ShippingRuleSource
    {
        return $source instanceof ShippingRuleSource ? $source : ShippingRuleSource::tryFrom((string) $source);
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
                'amazon_program' => self::summarizeAmazonProgram($data),
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
                    ->visible(fn (Get $get): bool => $get('operator') === 'between')
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
                    ->visible(fn (Get $get): bool => $get('operator') === 'between')
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
                    ->visible(fn (Get $get): bool => $get('operator') === 'between')
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

    private static function amazonProgramBlock(): Block
    {
        return Block::make('amazon_program')
            ->label(function (?array $state): string {
                if ($state === null) {
                    return 'Amazon Program';
                }

                return self::summarizeAmazonProgram($state) ?? 'Amazon Program';
            })
            ->icon('heroicon-o-star')
            ->schema([
                Forms\Components\Select::make('program')
                    ->label('Amazon order is')
                    ->options(AmazonOrderProgram::class)
                    ->helperText('Never matches an order from another channel.')
                    ->required(),
            ]);
    }

    private static function summarizeAmazonProgram(array $data): ?string
    {
        $program = AmazonOrderProgram::tryFrom($data['program'] ?? '');

        return $program ? "Amazon {$program->getLabel()}" : null;
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
