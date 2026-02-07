<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Models\ShippingMethodAlias;
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
use UnitEnum;

class UnmappedShippingReferences extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static ?string $navigationLabel = 'Map Shipping References';

    protected static UnitEnum|string|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.pages.unmapped-shipping-references';

    public static function canAccess(): bool
    {
        return auth()->user()->role->isAtLeast(Role::Manager);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Shipment::query()
                    ->selectRaw('MIN(id) as id, shipping_method_reference, COUNT(*) as shipment_count')
                    ->whereNotNull('shipping_method_reference')
                    ->where('shipping_method_reference', '!=', '')
                    ->whereNull('shipping_method_id')
                    ->whereNotIn('shipping_method_reference', ShippingMethodAlias::query()->select('reference'))
                    ->groupBy('shipping_method_reference')
            )
            ->defaultSort('shipping_method_reference')
            ->defaultKeySort(false)
            ->columns([
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
                            'reference' => $reference,
                            'shipping_method_id' => $shippingMethodId,
                        ]);

                        $updated = Shipment::where('shipping_method_reference', $reference)
                            ->whereNull('shipping_method_id')
                            ->update(['shipping_method_id' => $shippingMethodId]);

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
