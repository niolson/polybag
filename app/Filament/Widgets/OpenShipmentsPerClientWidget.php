<?php

namespace App\Filament\Widgets;

use App\Enums\Role;
use App\Enums\ShipmentStatus;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\ShipmentResource;
use App\Models\Client;
use App\Services\SettingsService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class OpenShipmentsPerClientWidget extends BaseWidget
{
    protected static ?int $sort = -2;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        if (! (auth()->user()?->role?->isAtLeast(Role::Manager) ?? false)) {
            return false;
        }

        /** @var SettingsService $settings */
        $settings = app(SettingsService::class);

        return $settings->get('multi_client_enabled', false);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Open Shipments by Client')
            ->query(
                Client::query()
                    ->withCount([
                        'shipments as open_shipments_count' => fn (Builder $query) => $query->where('status', ShipmentStatus::Open),
                    ])
                    ->where('active', true)
                    ->orderByDesc('open_shipments_count')
            )
            ->columns([
                TextColumn::make('name')
                    ->label('Client')
                    ->url(fn (Client $record): string => ClientResource::getUrl('edit', ['record' => $record]))
                    ->weight('medium'),
                TextColumn::make('open_shipments_count')
                    ->label('Open Shipments')
                    ->numeric()
                    ->sortable()
                    ->url(fn (Client $record): string => ShipmentResource::getUrl('index').'?status_tab='.ShipmentStatus::Open->value.'&filters[client][value]='.$record->id)
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'success'),
            ])
            ->paginated(false);
    }
}
