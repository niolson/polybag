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
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class UnmappedChannelReferences extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static ?string $navigationLabel = 'Unmapped Channels';

    protected static UnitEnum|string|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 91;

    protected string $view = 'filament.pages.unmapped-channel-references';

    public static function canAccess(): bool
    {
        return auth()->user()->role->isAtLeast(Role::Manager);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Shipment::query()
                    ->selectRaw('MIN(id) as id, channel_reference, COUNT(*) as shipment_count')
                    ->whereNotNull('channel_reference')
                    ->where('channel_reference', '!=', '')
                    ->whereNull('channel_id')
                    ->whereNotIn('channel_reference', ChannelAlias::query()->select('reference'))
                    ->groupBy('channel_reference')
            )
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
                    ->label('Assign')
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
                            'reference' => $reference,
                            'channel_id' => $channelId,
                        ]);

                        $updated = Shipment::where('channel_reference', $reference)
                            ->whereNull('channel_id')
                            ->update(['channel_id' => $channelId]);

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
