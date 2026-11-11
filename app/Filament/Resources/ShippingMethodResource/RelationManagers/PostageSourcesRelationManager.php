<?php

namespace App\Filament\Resources\ShippingMethodResource\RelationManagers;

use App\Enums\OtdrProtectedOrders;
use App\Enums\PostageSourceKind;
use App\Enums\UnlistedServices;
use App\Models\ShippingMethodPostageSource;
use App\Policies\ShippingMethodPostageSourcePolicy;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

/**
 * Which sources may sell for this method — `carrier-catalog-reset/09`.
 *
 * A row lets its kind of source sell; no row means it may not. Admin-only
 * through {@see ShippingMethodPostageSourcePolicy}: a Manager sees
 * the rows and edits the services and rules that pick within them.
 */
class PostageSourcesRelationManager extends RelationManager
{
    protected static string $relationship = 'postageSources';

    protected static ?string $title = 'Postage Sources';

    protected static ?string $modelLabel = 'postage source';

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Forms\Components\Select::make('source_kind')
                    ->label('Source')
                    ->options(self::kindOptions())
                    ->required()
                    ->live()
                    ->disabledOn('edit')
                    ->unique(
                        table: 'shipping_method_postage_sources',
                        column: 'source_kind',
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('shipping_method_id', $this->getOwnerRecord()->getKey()),
                    )
                    ->validationMessages(['unique' => 'This method already allows that source.']),
                // One column for every kind, labeled as each kind means it,
                // so nobody sees the shared name.
                Forms\Components\Toggle::make('unlisted_services')
                    ->label(fn (Get $get): string => self::kindFrom($get)?->unlistedServicesLabel() ?? '')
                    ->helperText(fn (Get $get): string => match (self::kindFrom($get)) {
                        PostageSourceKind::Amazon => 'Lets automation buy any service Amazon offers, including ones not mapped to a catalog service. The Ship page shows every offer either way.',
                        default => 'Lets this source sell beyond the services the method lists.',
                    })
                    ->dehydrateStateUsing(fn (bool $state): UnlistedServices => $state ? UnlistedServices::Any : UnlistedServices::None)
                    ->visible(fn (Get $get): bool => in_array(UnlistedServices::Any, self::kindFrom($get)?->acceptedUnlistedServices() ?? [], true)),
                // `amazon-buy-shipping/17`: only a Buy Shipping offer can be
                // protected, so the requirement lives on the Amazon row.
                Forms\Components\CheckboxList::make('otdr_protection_orders')
                    ->label('Only Buy OTDR-Protected Labels For')
                    ->options(OtdrProtectedOrders::class)
                    ->helperText('For the ticked Amazon orders, automated purchases only buy Amazon Buy Shipping offers that Amazon marks OTDR Protected, so a late delivery does not count against your On-Time Delivery Rate. Direct carrier rates are skipped for these orders even when they are cheaper. If Amazon returns no protected offer, the order is held for a person. Amazon only protects offers when Shipping Settings Automation and Average Handling Time automation are on in Seller Central. A packer on the Ship page can still pick any rate.')
                    ->visible(fn (Get $get): bool => self::kindFrom($get) === PostageSourceKind::Amazon),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (ShippingMethodPostageSource $record): string => $record->source_kind->label())
            ->columns([
                Tables\Columns\TextColumn::make('source_kind')
                    ->label('Source')
                    ->formatStateUsing(fn (PostageSourceKind $state): string => $state->label()),
                Tables\Columns\TextColumn::make('unlisted_services')
                    ->label('Beyond Listed Services')
                    ->state(fn (ShippingMethodPostageSource $record): ?string => $record->allowsUnlistedServices()
                        ? $record->source_kind->unlistedServicesLabel()
                        : $record->source_kind->listedServicesLabel()),
                Tables\Columns\TextColumn::make('otdr_protection_orders')
                    ->label('OTDR Protection Required For')
                    ->state(fn (ShippingMethodPostageSource $record): ?string => $record->otdr_protection_orders?->isNotEmpty()
                        ? $record->otdr_protection_orders->map(fn (OtdrProtectedOrders $kind): string => $kind->getLabel())->join(', ')
                        : null)
                    ->placeholder('—'),
            ])
            ->headerActions([
                Actions\CreateAction::make(),
            ])
            ->recordActions([
                Actions\EditAction::make()
                    // The toggle casts its loaded state to a bool before any
                    // formatter runs, which would read every enum as true then
                    // fail to parse it, so hand it the bool directly.
                    ->mutateRecordDataUsing(fn (array $data): array => [
                        ...$data,
                        'unlisted_services' => self::asUnlisted($data['unlisted_services'] ?? null) === UnlistedServices::Any,
                    ])
                    // Direct has nothing to edit: it sells the listed services or,
                    // with no row, nothing.
                    ->visible(fn (ShippingMethodPostageSource $record): bool => count($record->source_kind->acceptedUnlistedServices()) > 1),
                Actions\DeleteAction::make(),
            ]);
    }

    /**
     * The kinds a row here governs.
     *
     * @return array<string, string>
     */
    public static function kindOptions(): array
    {
        return collect(PostageSourceKind::cases())
            ->mapWithKeys(fn (PostageSourceKind $kind): array => [$kind->value => $kind->label()])
            ->all();
    }

    private static function kindFrom(Get $get): ?PostageSourceKind
    {
        $kind = $get('source_kind');

        return $kind instanceof PostageSourceKind ? $kind : PostageSourceKind::tryFrom((string) $kind);
    }

    private static function asUnlisted(mixed $state): ?UnlistedServices
    {
        return $state instanceof UnlistedServices ? $state : UnlistedServices::tryFrom((string) $state);
    }
}
