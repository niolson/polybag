<?php

namespace App\Filament\Resources\ShippingMethodResource\RelationManagers;

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

    protected static ?string $title = 'Postage sources';

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
                // One column for every kind, labelled as each kind means it,
                // so nobody sees the shared name.
                Forms\Components\Toggle::make('unlisted_services')
                    ->label(fn (Get $get): string => self::kindFrom($get)?->unlistedServicesLabel() ?? '')
                    ->helperText('Lets this source sell beyond the services the method lists.')
                    ->formatStateUsing(fn (mixed $state): bool => self::asUnlisted($state) === UnlistedServices::Any)
                    ->dehydrateStateUsing(fn (bool $state): UnlistedServices => $state ? UnlistedServices::Any : UnlistedServices::None)
                    ->visible(fn (Get $get): bool => in_array(UnlistedServices::Any, self::kindFrom($get)?->acceptedUnlistedServices() ?? [], true)),
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
                    ->label('Beyond listed services')
                    ->state(fn (ShippingMethodPostageSource $record): ?string => $record->allowsUnlistedServices()
                        ? $record->source_kind->unlistedServicesLabel()
                        : null)
                    ->placeholder('Listed services only'),
            ])
            ->headerActions([
                Actions\CreateAction::make(),
            ])
            ->recordActions([
                Actions\EditAction::make()
                    // Direct has nothing to edit: it sells the listed services or,
                    // with no row, nothing.
                    ->visible(fn (ShippingMethodPostageSource $record): bool => count($record->source_kind->acceptedUnlistedServices()) > 1),
                Actions\DeleteAction::make(),
            ]);
    }

    /**
     * The kinds a row here governs. Amazon Buy Shipping is still allowed by the
     * `Amazon` hook row the method lists, so an Amazon row would change nothing;
     * it is offered once `carrier-catalog-reset/12` makes the row govern it.
     *
     * @return array<string, string>
     */
    public static function kindOptions(): array
    {
        return collect(PostageSourceKind::cases())
            ->reject(fn (PostageSourceKind $kind): bool => $kind === PostageSourceKind::Amazon)
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
