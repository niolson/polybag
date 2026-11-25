<?php

namespace App\Filament\Pages\Reports;

use App\Enums\PostageSource;
use App\Enums\Role;
use App\Enums\VoidReason;
use App\Filament\Concerns\DescribesReportRange;
use App\Filament\Support\LocalDateRange;
use App\Models\Carrier;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Location;
use App\Models\PackageLabel;
use App\Services\SettingsService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Columns\Summarizers\Count;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Labels voided in range, for checking that their postage comes back
 * (`reports-review/08`).
 *
 * A voided Label stays in `package_labels` as history. Carriers refund the
 * postage later, or only when asked, and PolyBag records no refund, so this
 * is the list to reconcile against the carrier's or the channel's statement.
 */
class VoidedLabelsReport extends Page implements HasTable
{
    use DescribesReportRange;
    use InteractsWithTable;

    /** The Postage Source filter's value for every direct carrier account. */
    public const DIRECT_SOURCE = 'direct';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-receipt-refund';

    protected static ?string $navigationLabel = 'Voided Labels';

    protected static ?string $title = 'Voided Labels';

    protected static UnitEnum|string|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 37;

    protected string $view = 'filament.pages.reports.voided-labels-report';

    public static function canAccess(): bool
    {
        return auth()->user()?->role->isAtLeast(Role::Manager) ?? false;
    }

    /**
     * Voided Labels, with what the columns need joined on.
     *
     * @return Builder<PackageLabel>
     */
    private function voidedLabels(): Builder
    {
        return PackageLabel::query()
            ->whereNotNull('package_labels.voided_at')
            ->join('packages', 'package_labels.package_id', '=', 'packages.id')
            ->join('shipments', 'packages.shipment_id', '=', 'shipments.id')
            ->select([
                'package_labels.*',
                'shipments.shipment_reference as order_reference',
                'shipments.client_id as client_id',
            ])
            // Bought again: the Package has an active Label now, which is never this one.
            ->selectSub(fn (QueryBuilder $query): QueryBuilder => $query
                ->selectRaw('COUNT(*)')
                ->from('package_labels as rebought')
                ->whereColumn('rebought.active_package_id', 'package_labels.package_id'), 'rebought')
            ->with(['voidedBy', 'carrierAccount', 'postageDataSource', 'carrierService']);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->voidedLabels())
            ->defaultSort('package_labels.voided_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('voided_at')
                    ->label('Voided')
                    ->dateTime('M j, Y g:i A', timezone: Location::timezone())
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('package_labels.voided_at', $direction)),
                Tables\Columns\TextColumn::make('order_reference')
                    ->label('Order')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('shipments.shipment_reference', 'like', "%{$search}%")),
                Tables\Columns\TextColumn::make('tracking_number')
                    ->label('Tracking')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('package_labels.tracking_number', 'like', "%{$search}%")),
                Tables\Columns\TextColumn::make('carrier')
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? Carrier::labelForName($state) : '—'),
                Tables\Columns\TextColumn::make('service_label')
                    ->label('Service')
                    ->state(fn (PackageLabel $record): ?string => $record->carrierService->name ?? $record->service),
                Tables\Columns\TextColumn::make('postage_source_label')
                    ->label('Bought Through')
                    ->state(fn (PackageLabel $record): string => $this->postageSourceLabel($record)),
                Tables\Columns\TextColumn::make('cost')
                    ->label('Postage')
                    ->money('USD')
                    ->placeholder('Not reported')
                    ->summarize([
                        Count::make()->label('Labels'),
                        Sum::make()->money('USD')->label('Postage'),
                    ])
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('package_labels.cost', $direction)),
                Tables\Columns\TextColumn::make('voidedBy.name')
                    ->label('Voided By')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('void_reason')
                    ->label('How')
                    ->formatStateUsing(fn (?VoidReason $state): string => $state?->getLabel() ?? '—'),
                Tables\Columns\IconColumn::make('rebought')
                    ->label('Bought Again')
                    ->state(fn (PackageLabel $record): bool => (int) $record->getAttribute('rebought') > 0)
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\Filter::make('date_range')
                    ->form([
                        DatePicker::make('from')
                            ->default(LocalDateRange::now()->subDays(30)->format('Y-m-d')),
                        DatePicker::make('until'),
                    ])
                    ->columns(2)
                    ->default()
                    ->query(fn (Builder $query, array $data): Builder => LocalDateRange::apply($query, 'package_labels.voided_at', $data['from'] ?? null, $data['until'] ?? null)),
                Tables\Filters\SelectFilter::make('carrier')
                    ->label('Carrier')
                    ->options(fn (): array => PackageLabel::query()
                        ->whereNotNull('voided_at')
                        ->whereNotNull('carrier')
                        ->distinct()
                        ->pluck('carrier')
                        ->mapWithKeys(fn (string $carrier): array => [$carrier => Carrier::labelForName($carrier)])
                        ->sort()
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $carrier): Builder => $query->where('package_labels.carrier', $carrier),
                    )),
                Tables\Filters\SelectFilter::make('postage_source')
                    ->label('Bought Through')
                    ->options(fn (): array => [self::DIRECT_SOURCE => 'Direct carrier accounts']
                        + DataSource::query()
                            ->whereIn('id', PackageLabel::query()->whereNotNull('voided_at')->whereNotNull('postage_data_source_id')->select('postage_data_source_id'))
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                    ->query(fn (Builder $query, array $data): Builder => match ($value = $data['value'] ?? null) {
                        null, '' => $query,
                        self::DIRECT_SOURCE => $query->where('package_labels.postage_source', PostageSource::CarrierAccount->value),
                        default => $query->where('package_labels.postage_data_source_id', $value),
                    }),
                Tables\Filters\SelectFilter::make('client')
                    ->label('Client')
                    ->options(fn (): array => Client::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, $clientId): Builder => $query->where('shipments.client_id', $clientId),
                    ))
                    ->visible(fn (): bool => (bool) app(SettingsService::class)->get('multi_client_enabled', false)),
            ], layout: FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->filtersFormColumns(4)
            ->emptyStateHeading('No labels voided in this range');
    }

    /**
     * Where the Label was bought: the carrier account or the Connection by
     * name.
     */
    private function postageSourceLabel(PackageLabel $label): string
    {
        return match ($label->postage_source) {
            PostageSource::PostageDataSource => $label->postageDataSource->name ?? 'A connection',
            PostageSource::CarrierAccount => $label->carrierAccount->name ?? 'A carrier account',
            default => '—',
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export_csv')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn (): StreamedResponse => $this->exportCsv()),
        ];
    }

    /**
     * The rows the table shows, filters, search and sort included, read in
     * chunks with the Label id as the last key so rows sharing a void time
     * never move between chunks.
     */
    public function exportCsv(int $chunkSize = 500): StreamedResponse
    {
        $query = $this->getFilteredSortedTableQuery();
        $filename = 'voided-labels-'.now()->format('Y-m-d').'.csv';
        $timezone = Location::timezone();

        return response()->streamDownload(function () use ($query, $chunkSize, $timezone): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Voided', 'Order', 'Tracking', 'Carrier', 'Service', 'Bought Through', 'Postage', 'Voided By', 'How', 'Bought Again']);

            foreach ($query?->orderBy('package_labels.id')->lazy($chunkSize) ?? [] as $label) {
                if (! $label instanceof PackageLabel) {
                    continue;
                }

                fputcsv($out, [
                    $label->voided_at?->setTimezone($timezone)->format('Y-m-d H:i:s'),
                    $label->getAttribute('order_reference'),
                    $label->tracking_number,
                    $label->carrier,
                    $label->carrierService->name ?? $label->service,
                    $this->postageSourceLabel($label),
                    $label->cost,
                    $label->voidedBy?->name,
                    $label->void_reason?->getLabel(),
                    (int) $label->getAttribute('rebought') > 0 ? 'yes' : 'no',
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
