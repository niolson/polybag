<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Models\ShippingMethodAlias;
use App\Services\SettingsService;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class UnmappedShippingReferences extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?string $navigationLabel = 'Map Shipping References';

    protected static UnitEnum|string|null $navigationGroup = 'Integrations';

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.pages.unmapped-shipping-references';

    public static function canAccess(): bool
    {
        return auth()->user()->role->isAtLeast(Role::Manager);
    }

    public function table(Table $table): Table
    {
        $multiClient = app(SettingsService::class)->get('multi_client_enabled', false);

        return $table
            ->query(
                Shipment::query()
                    ->selectRaw('MIN(id) as id, shipping_method_reference, client_id, COUNT(*) as shipment_count')
                    ->whereNotNull('shipping_method_reference')
                    ->where('shipping_method_reference', '!=', '')
                    ->whereNull('shipping_method_id')
                    ->whereNotExists(function ($query): void {
                        $query->select(DB::raw(1))
                            ->from('shipping_method_aliases')
                            ->whereColumn('shipping_method_aliases.reference', 'shipments.shipping_method_reference')
                            ->whereColumn('shipping_method_aliases.client_id', 'shipments.client_id');
                    })
                    ->groupBy('shipping_method_reference', 'client_id')
            )
            ->defaultSort('shipping_method_reference')
            ->defaultKeySort(false)
            ->columns([
                Tables\Columns\TextColumn::make('client.name')
                    ->label('Client')
                    ->visible($multiClient),
                Tables\Columns\TextColumn::make('shipping_method_reference')
                    ->label('Reference')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('shipment_count')
                    ->label('Shipments')
                    ->sortable(),
            ])
            ->recordActions([
                Actions\Action::make('assign')
                    ->label('Assign')
                    ->icon('heroicon-o-link')
                    ->form([
                        Forms\Components\Select::make('shipping_method_id')
                            ->label('Shipping Method')
                            ->options(ShippingMethod::query()->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (Shipment $record, array $data): void {
                        $reference = $record->shipping_method_reference;
                        $shippingMethodId = $data['shipping_method_id'];

                        ShippingMethodAlias::create([
                            'client_id' => $record->client_id,
                            'reference' => $reference,
                            'shipping_method_id' => $shippingMethodId,
                        ]);

                        $updated = Shipment::where('shipping_method_reference', $reference)
                            ->where('client_id', $record->client_id)
                            ->whereNull('shipping_method_id')
                            // A query-builder update skips the model's saving hook, so clear
                            // the validation attempt here: a carrier validator the new
                            // method allows should get its turn on the schedule.
                            ->update(['shipping_method_id' => $shippingMethodId, 'validation_attempted_at' => null]);

                        Notification::make()
                            ->success()
                            ->title("Alias created and {$updated} shipment(s) updated")
                            ->send();
                    }),
            ]);
    }

    public function resolveTableRecord(?string $key): ?Model
    {
        return Shipment::find($key);
    }
}
