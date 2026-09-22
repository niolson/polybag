<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Models\Location;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class OperatorShippingSettings extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static ?string $navigationLabel = 'Operator Shipping';

    protected static UnitEnum|string|null $navigationGroup = 'Manage';

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.pages.operator-shipping-settings';

    protected ?string $heading = 'Operator Shipping';

    public static function canAccess(): bool
    {
        return auth()->user()?->role->isAtLeast(Role::Manager) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->operatorQuery())
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('location.name')
                    ->label('Location')
                    ->placeholder('System default')
                    ->sortable(),
                Tables\Columns\IconColumn::make('auto_ship_enabled')
                    ->label('Auto Ship')
                    ->boolean(),
                Tables\Columns\IconColumn::make('active')
                    ->boolean(),
            ])
            ->recordActions([
                Action::make('disableAutoShip')
                    ->label('Turn off Auto Ship')
                    ->icon(Heroicon::OutlinedPause)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (User $record): bool => $record->auto_ship_enabled)
                    ->action(fn (User $record) => $this->setAutoShip($record, false)),
                Action::make('enableAutoShip')
                    ->label('Turn on Auto Ship')
                    ->icon(Heroicon::OutlinedBolt)
                    ->color('success')
                    ->visible(fn (User $record): bool => ! $record->auto_ship_enabled)
                    ->action(fn (User $record) => $this->setAutoShip($record, true)),
            ]);
    }

    private function operatorQuery(): Builder
    {
        $query = User::query()->where('role', Role::User);
        $manager = auth()->user();

        if ($manager->role->isAtLeast(Role::Admin)) {
            return $query;
        }

        $location = $manager->resolveLocation();

        if ($location === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $query) use ($location): void {
            $query->where('location_id', $location->id);

            if (Location::getDefault()?->is($location)) {
                $query->orWhereNull('location_id');
            }
        });
    }

    private function setAutoShip(User $operator, bool $enabled): void
    {
        abort_unless($this->operatorQuery()->whereKey($operator)->exists(), 403);

        $operator->update(['auto_ship_enabled' => $enabled]);

        Notification::make()
            ->title($enabled ? 'Auto Ship enabled' : 'Auto Ship disabled')
            ->body("{$operator->name}'s shipping preference was updated.")
            ->success()
            ->send();
    }
}
