<?php

namespace App\Filament\Pages;

use App\DataTransferObjects\PackSlips\PackSlipPrintJob;
use App\DataTransferObjects\PackSlips\PackSlipQueueFilters;
use App\DataTransferObjects\PackSlips\PackSlipRun;
use App\Enums\PackSlipQueueTab;
use App\Enums\PackSlipState;
use App\Filament\Resources\PickBatches\PickBatchResource;
use App\Models\Channel;
use App\Models\Client;
use App\Models\PickBatch;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\PackSlips\PackSlipQueue;
use App\Services\PackSlips\PackSlipRenderer;
use App\Services\PackSlips\PackSlipViews;
use App\Services\SettingsService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use LogicException;
use Throwable;
use UnitEnum;

/**
 * Prints pack slips for open Shipments without any picking: the next N most urgent,
 * or a selection. A Shipment leaves the Not printed tab only once its slip is recorded
 * printed, by QZ Tray's acknowledgment or by Mark as printed on a view. The Printed tab
 * lists current slips, newest first, for reprinting a run that jammed.
 */
class PrintPackSlips extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Print Pack Slips';

    protected static UnitEnum|string|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'print-pack-slips';

    protected ?string $heading = 'Print Pack Slips';

    protected string $view = 'filament.pages.print-pack-slips';

    #[Url(as: 'tab')]
    public string $activeTab = PackSlipQueueTab::NotPrinted->value;

    public static function canAccess(): bool
    {
        return auth()->check() && app(SettingsService::class)->packSlipsEnabled();
    }

    /**
     * QZ Tray's acknowledgment recorded a run, so its Shipments leave the list.
     */
    #[On('pack-slips-printed')]
    public function refreshQueue(): void
    {
        $this->deselectAllTableRecords();
    }

    public function updatedActiveTab(): void
    {
        $this->resetPage();
        $this->deselectAllTableRecords();
    }

    public function activeTab(): PackSlipQueueTab
    {
        return PackSlipQueueTab::tryFrom($this->activeTab) ?? PackSlipQueueTab::NotPrinted;
    }

    public function printsFromPickBatches(): bool
    {
        return app(PackSlipQueue::class)->printsFromPickBatches();
    }

    /**
     * @return array{count: int, batches: EloquentCollection<int, PickBatch>}
     */
    public function leftOffForPickBatches(): array
    {
        return app(PackSlipQueue::class)->leftOffForPickBatches($this->queueFilters(), $this->activeTab());
    }

    public function pickBatchUrl(?PickBatch $batch = null): ?string
    {
        if (! PickBatchResource::canAccess()) {
            return null;
        }

        return $batch
            ? PickBatchResource::getUrl('view', ['record' => $batch])
            : PickBatchResource::getUrl();
    }

    public function table(Table $table): Table
    {
        $multiClient = (bool) app(SettingsService::class)->get('multi_client_enabled', false);

        return $table
            ->query(fn (): Builder => app(PackSlipQueue::class)->tab($this->activeTab(), $this->queueFilters()))
            ->defaultKeySort(false)
            ->columns([
                Tables\Columns\TextColumn::make('shipment_reference')
                    ->label('Reference')
                    ->searchable(),
                Tables\Columns\TextColumn::make('pack_slip_state')
                    ->label('Pack Slip')
                    ->state(fn (Shipment $record): ?PackSlipState => $record->packSlipIsOutOfDate() ? PackSlipState::ChangedSincePrinted : null)
                    ->badge()
                    ->visible(fn (): bool => $this->activeTab() === PackSlipQueueTab::NotPrinted),
                Tables\Columns\TextColumn::make('client.name')
                    ->label('Client')
                    ->visible($multiClient),
                Tables\Columns\TextColumn::make('channel.name')
                    ->label('Channel'),
                Tables\Columns\TextColumn::make('shippingMethod.name')
                    ->label('Shipping Method')
                    ->badge(fn (Shipment $record): bool => (bool) $record->shippingMethod?->is_expedited)
                    ->color(fn (Shipment $record): ?string => $record->shippingMethod?->is_expedited ? 'warning' : null),
                Tables\Columns\TextColumn::make('full_name')
                    ->label('Name')
                    ->state(fn (Shipment $record): string => trim("{$record->first_name} {$record->last_name}")),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->since()
                    ->dateTimeTooltip(),
                Tables\Columns\TextColumn::make('pack_slip_printed_at')
                    ->label('Printed')
                    ->since()
                    ->dateTimeTooltip()
                    ->visible(fn (): bool => $this->activeTab() === PackSlipQueueTab::Printed),
                Tables\Columns\TextColumn::make('packSlipPrintedBy.name')
                    ->label('Printed by')
                    ->placeholder('—')
                    ->visible(fn (): bool => $this->activeTab() === PackSlipQueueTab::Printed),
            ])
            ->filters([
                // The queue applies these, so the table and "Print next N" read the same state.
                Tables\Filters\SelectFilter::make('client')
                    ->options(fn (): array => Client::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query): Builder => $query)
                    ->visible($multiClient),
                Tables\Filters\SelectFilter::make('channel')
                    ->options(fn (): array => Channel::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query): Builder => $query),
                Tables\Filters\SelectFilter::make('shipping_method')
                    ->label('Shipping Method')
                    ->options(fn (): array => ShippingMethod::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query): Builder => $query),
            ])
            ->deferFilters(false)
            ->toolbarActions([
                BulkAction::make('printSelected')
                    ->label('Print selected')
                    ->icon('heroicon-o-printer')
                    ->visible(fn (): bool => $this->activeTab() === PackSlipQueueTab::NotPrinted)
                    ->action(fn (EloquentCollection $records) => $this->print(
                        app(PackSlipQueue::class)->run($records->modelKeys()),
                    ))
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('reprintSelected')
                    ->label('Reprint')
                    ->icon('heroicon-o-printer')
                    ->visible(fn (): bool => $this->activeTab() === PackSlipQueueTab::Printed)
                    ->action(fn (EloquentCollection $records) => $this->print(
                        app(PackSlipQueue::class)->run($records->modelKeys()),
                    ))
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('viewSelected')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->action(function (EloquentCollection $records): void {
                        $url = route('pack-slips.view', app(PackSlipViews::class)->put(
                            app(PackSlipQueue::class)->run($records->modelKeys()),
                        ));

                        $this->js('window.open('.json_encode($url, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG).', "_blank")');

                        Notification::make()
                            ->title('Pack slips opened in a new tab')
                            ->body('Mark them as printed there once they are on paper.')
                            ->actions([
                                Action::make('open')
                                    ->label('Open again')
                                    ->url($url, shouldOpenInNewTab: true),
                            ])
                            ->send();
                    }),
            ])
            ->emptyStateHeading(fn (): string => match ($this->activeTab()) {
                PackSlipQueueTab::NotPrinted => 'No pack slips waiting',
                PackSlipQueueTab::Printed => 'No pack slips printed',
            })
            ->emptyStateDescription(fn (): string => match ($this->activeTab()) {
                PackSlipQueueTab::NotPrinted => 'Every open Shipment has a current pack slip.',
                PackSlipQueueTab::Printed => 'Open Shipments appear here once their pack slip is printed.',
            })
            ->maxSelectableRecords(1000);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('printNext')
                ->label(fn (): string => 'Print next '.$this->user()->pack_slip_batch_size)
                ->icon('heroicon-o-printer')
                ->hidden(fn (): bool => $this->printsFromPickBatches() || $this->activeTab() === PackSlipQueueTab::Printed)
                ->action(fn () => $this->print(app(PackSlipQueue::class)->run(
                    app(PackSlipQueue::class)->next($this->user()->pack_slip_batch_size, $this->queueFilters()),
                ))),
            Action::make('batchSize')
                ->label('Batch size')
                ->icon('heroicon-o-adjustments-horizontal')
                ->color('gray')
                ->hidden(fn (): bool => $this->printsFromPickBatches() || $this->activeTab() === PackSlipQueueTab::Printed)
                ->fillForm(fn (): array => ['pack_slip_batch_size' => $this->user()->pack_slip_batch_size])
                ->schema([
                    TextInput::make('pack_slip_batch_size')
                        ->label('Batch size')
                        ->helperText('How many pack slips "Print next" takes. Remembered for you.')
                        ->integer()
                        ->minValue(1)
                        ->maxValue(1000)
                        ->required(),
                ])
                ->modalWidth('sm')
                ->action(function (array $data): void {
                    $this->user()->update(['pack_slip_batch_size' => (int) $data['pack_slip_batch_size']]);
                }),
        ];
    }

    private function print(PackSlipRun $run): void
    {
        if ($run->count() === 0) {
            Notification::make()->warning()->title('No pack slips to print')->send();

            return;
        }

        try {
            $jobs = app(PackSlipRenderer::class)->printJobs($run, $this->user());
        } catch (Throwable $e) {
            Notification::make()
                ->danger()
                ->title('PDF renderer unavailable')
                ->body($e->getMessage())
                ->send();

            return;
        }

        // Recorded on each Shipment only when the browser redeems a job's receipt,
        // once QZ Tray reports that job sent.
        $this->dispatch('print-pack-slips', jobs: array_map(
            fn (PackSlipPrintJob $job): array => $job->toBrowserPayload(),
            $jobs,
        ));
    }

    private function queueFilters(): PackSlipQueueFilters
    {
        $value = fn (string $filter): ?int => filled($state = $this->getTableFilterState($filter)['value'] ?? null)
            ? (int) $state
            : null;

        return new PackSlipQueueFilters(
            clientId: $value('client'),
            channelId: $value('channel'),
            shippingMethodId: $value('shipping_method'),
        );
    }

    private function user(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new LogicException('Print Pack Slips needs a signed-in user.');
        }

        return $user;
    }
}
