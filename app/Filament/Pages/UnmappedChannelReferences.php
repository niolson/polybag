<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Models\Channel;
use App\Models\ChannelAlias;
use App\Models\Shipment;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class UnmappedChannelReferences extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationLabel = 'Map Channel Refs';

    protected static ?string $title = 'Map Channel References';

    protected static UnitEnum|string|null $navigationGroup = 'Integrations';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.unmapped-channel-references';

    public static function canAccess(): bool
    {
        return auth()->user()->role->isAtLeast(Role::Manager);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Cache::remember('nav:unmapped_channel_references', 60, fn (): int => DB::query()
            ->fromSub(static::unmappedReferences(), 'unmapped')
            ->count());

        return $count > 0 ? number_format($count) : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /**
     * One row per unmapped reference, as the table lists them.
     *
     * @return Builder<Shipment>
     */
    public static function unmappedReferences(): Builder
    {
        return Shipment::query()
            ->selectRaw('MIN(id) as id, channel_reference, COUNT(*) as shipment_count')
            ->whereNotNull('channel_reference')
            ->where('channel_reference', '!=', '')
            ->whereNull('channel_id')
            ->whereNotIn('channel_reference', ChannelAlias::query()->select('reference'))
            ->groupBy('channel_reference');
    }

    public function table(Table $table): Table
    {
        return $table
            ->emptyStateHeading('No unmapped references')
            ->query(static::unmappedReferences())
            ->defaultSort('channel_reference')
            ->defaultKeySort(false)
            ->columns([
                Tables\Columns\TextColumn::make('channel_reference')
                    ->label('Reference')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('shipment_count')
                    ->label('Shipments')
                    ->sortable(),
            ])
            ->recordActions([
                Actions\Action::make('assign')
                    ->label('Map')
                    ->modalSubmitActionLabel('Map')
                    ->icon('heroicon-o-link')
                    ->form([
                        Forms\Components\Select::make('channel_id')
                            ->label('Channel')
                            ->options(Channel::query()->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (Shipment $record, array $data): void {
                        $reference = $record->channel_reference;
                        $channelId = $data['channel_id'];

                        ChannelAlias::create([
                            'client_id' => $record->client_id,
                            'reference' => $reference,
                            'channel_id' => $channelId,
                        ]);

                        $updated = Shipment::where('channel_reference', $reference)
                            ->whereNull('channel_id')
                            ->update(['channel_id' => $channelId]);

                        Notification::make()
                            ->success()
                            ->title("Mapped. {$updated} shipment(s) updated")
                            ->send();
                    }),
            ]);
    }

    public function resolveTableRecord(?string $key): ?Model
    {
        return Shipment::find($key);
    }
}
