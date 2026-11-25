<?php

namespace App\Filament\Pages\Reports;

use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Enums\ShipmentStatus;
use App\Filament\Concerns\DescribesReportRange;
use App\Filament\Resources\ShipmentResource;
use App\Filament\Support\LocalDateRange;
use App\Models\Client;
use App\Models\Shipment;
use App\Services\SettingsService;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * How quickly imported orders leave, and whether they leave by their ship-by
 * date (`reports-review/06`).
 *
 * An order counts in the period its first package shipped. Its time to ship
 * runs from when PolyBag imported it, `shipments.created_at`, not when the
 * customer placed it, which no source reports consistently. Manual Ship
 * orders are left out: they are created at the bench and ship minutes later.
 * On time means the first package's ship date, the Location's calendar day,
 * is on or before `ship_by`; an order with no ship-by is in the counts and
 * times but not in the on-time rate.
 *
 * Medians and percentiles are not portable SQL, so the page reads one row per
 * order shipped in range and groups them here.
 */
class FulfillmentSpeedReport extends Page implements HasTable
{
    use DescribesReportRange;
    use InteractsWithTable;

    /**
     * The Group by choices, in the order the control lists them.
     *
     * @var array<string, string>
     */
    public const GROUPINGS = [
        'day' => 'Day',
        'week' => 'Week',
        'month' => 'Month',
        'channel' => 'Channel',
        'client' => 'Client',
    ];

    private const PERIODS = ['day', 'week', 'month'];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bolt';

    protected static ?string $navigationLabel = 'Fulfillment Speed';

    protected static ?string $title = 'Fulfillment Speed';

    protected static UnitEnum|string|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 31;

    protected string $view = 'filament.pages.reports.fulfillment-speed-report';

    public string $groupBy = 'week';

    /**
     * Orders shipped in range, keyed by the filter state they were read under,
     * so a render reads them once.
     *
     * @var array<string, Collection<int, \stdClass>>
     */
    private array $orders = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->role->isAtLeast(Role::Manager) ?? false;
    }

    /**
     * The Group by choices this install offers: Client only in multi-client
     * mode.
     *
     * @return array<string, string>
     */
    public function groupings(): array
    {
        return $this->multiClientEnabled()
            ? self::GROUPINGS
            : array_diff_key(self::GROUPINGS, ['client' => true]);
    }

    public function updatedGroupBy(): void
    {
        if (! array_key_exists($this->groupBy, $this->groupings())) {
            $this->groupBy = 'week';
        }

        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        $filters = [
            Tables\Filters\Filter::make('date_range')
                ->form([
                    DatePicker::make('from')
                        ->default(LocalDateRange::now()->subDays(30)->format('Y-m-d')),
                    DatePicker::make('until'),
                ])
                ->columns(2)
                ->default(),
            Tables\Filters\SelectFilter::make('client')
                ->label('Client')
                ->options(fn (): array => Client::query()->orderBy('name')->pluck('name', 'id')->all())
                ->visible(fn (): bool => $this->multiClientEnabled()),
        ];

        return $table
            ->records(fn (?string $sortColumn, ?string $sortDirection, int $page, int $recordsPerPage): LengthAwarePaginator => $this->paginate(
                $this->sortedRows($sortColumn, $sortDirection),
                $page,
                $recordsPerPage,
            ))
            ->columns([
                Tables\Columns\TextColumn::make('group')
                    ->label($this->isPeriodGrouping() ? 'Period' : self::GROUPINGS[$this->groupBy])
                    ->description(fn (array $record): ?string => $record['in_progress'] ? 'In progress, not a full '.$this->groupBy : null)
                    ->sortable(),
                Tables\Columns\TextColumn::make('orders')
                    ->label('Orders Shipped')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('median_hours')
                    ->label('Median Time to Ship')
                    ->formatStateUsing(fn (?float $state): string => self::duration($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('p90_hours')
                    ->label('90% Shipped Within')
                    ->formatStateUsing(fn (?float $state): string => self::duration($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('with_ship_by')
                    ->label('With Ship-By')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('on_time')
                    ->label('On Time')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('late')
                    ->label('Late')
                    ->numeric()
                    ->color(fn (array $record): ?string => $record['late'] > 0 ? 'danger' : null)
                    ->sortable(),
                Tables\Columns\TextColumn::make('on_time_rate')
                    ->label('On-Time %')
                    ->formatStateUsing(fn (?float $state): string => $state === null ? '—' : number_format($state, 1).'%')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters($filters, layout: FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->filtersFormColumns(3)
            ->emptyStateHeading('No imported orders shipped in this range');
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
     * Every row, in the table's sort, not only the page shown.
     */
    public function exportCsv(): StreamedResponse
    {
        $rows = $this->sortedRows($this->getTableSortColumn(), $this->getTableSortDirection());
        $heading = $this->isPeriodGrouping() ? 'Period' : self::GROUPINGS[$this->groupBy];
        $filename = 'fulfillment-speed-by-'.$this->groupBy.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows, $heading): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, [$heading, 'Orders Shipped', 'Median Hours to Ship', '90th Percentile Hours', 'With Ship-By', 'On Time', 'Late', 'On-Time %']);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['group'],
                    $row['orders'],
                    $row['median_hours'] === null ? null : number_format($row['median_hours'], 1, '.', ''),
                    $row['p90_hours'] === null ? null : number_format($row['p90_hours'], 1, '.', ''),
                    $row['with_ship_by'],
                    $row['on_time'],
                    $row['late'],
                    $row['on_time_rate'] === null ? null : number_format($row['on_time_rate'], 1, '.', ''),
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * The whole range at once, for the headline.
     *
     * @return array{orders: int, median_hours: ?float, on_time_rate: ?float, late: int}
     */
    public function getSummary(): array
    {
        $totals = $this->summarize($this->shippedOrders());

        return [
            'orders' => $totals['orders'],
            'median_hours' => $totals['median_hours'],
            'on_time_rate' => $totals['on_time_rate'],
            'late' => $totals['late'],
        ];
    }

    /**
     * Open orders already past their ship-by date, whatever the range, and
     * the Shipments list filtered to them.
     *
     * @return array{count: int, url: string}
     */
    public function getPastShipBy(): array
    {
        $query = Shipment::query()->dueToShip(false);

        if ($clientId = $this->clientId()) {
            $query->where('client_id', $clientId);
        }

        return [
            'count' => $query->count(),
            'url' => ShipmentResource::getUrl('index', ['filters' => array_filter([
                'ship_by_due' => ['value' => 'overdue'],
                'client' => $clientId === null ? null : ['value' => $clientId],
            ])]),
        ];
    }

    public static function duration(?float $hours): string
    {
        if ($hours === null) {
            return '—';
        }

        if ($hours < 48) {
            return number_format($hours, 1).' h';
        }

        return number_format($hours / 24, 1).' days';
    }

    /**
     * @param  Collection<array-key, array<string, mixed>>  $rows
     * @return LengthAwarePaginator<array-key, array<string, mixed>>
     */
    private function paginate(Collection $rows, int $page, int $recordsPerPage): LengthAwarePaginator
    {
        return new LengthAwarePaginator($rows->forPage($page, $recordsPerPage), $rows->count(), $recordsPerPage, $page);
    }

    /**
     * @return Collection<array-key, array<string, mixed>>
     */
    private function sortedRows(?string $sortColumn, ?string $sortDirection): Collection
    {
        $rows = $this->groupedRows();

        $column = match (true) {
            $sortColumn === 'group' => 'sort_key',
            $sortColumn !== null && $sortColumn !== '' => $sortColumn,
            $this->isPeriodGrouping() => 'sort_key',
            default => 'orders',
        };
        $descending = $sortColumn === null || $sortColumn === '' ? true : $sortDirection === 'desc';

        return $rows->sortBy(fn (array $row): mixed => $row[$column] ?? -1, SORT_REGULAR, $descending);
    }

    /**
     * One row per group, keyed by the group.
     *
     * @return Collection<array-key, array<string, mixed>>
     */
    private function groupedRows(): Collection
    {
        /** @var Collection<array-key, array<string, mixed>> $rows */
        $rows = $this->shippedOrders()
            ->groupBy(fn (object $order): string => $this->groupKey($order))
            ->map(function (Collection $orders, string $key): array {
                $first = $orders->first();

                return [
                    'group' => $this->groupLabel($key, $first),
                    'sort_key' => $this->isPeriodGrouping() ? $key : $this->groupLabel($key, $first),
                    'in_progress' => $this->isCurrentPeriod($key),
                    ...$this->summarize($orders),
                ];
            });

        return $rows;
    }

    /**
     * @param  Collection<int, \stdClass>  $orders
     * @return array{orders: int, median_hours: ?float, p90_hours: ?float, with_ship_by: int, on_time: int, late: int, on_time_rate: ?float}
     */
    private function summarize(Collection $orders): array
    {
        $hours = $orders->map(fn (object $order): float => $order->hours_to_ship)->sort()->values();
        $withShipBy = $orders->filter(fn (object $order): bool => $order->ship_by !== null);
        $onTime = $withShipBy->filter(fn (object $order): bool => $order->first_ship_date <= $order->ship_by)->count();

        return [
            'orders' => $orders->count(),
            'median_hours' => self::percentile($hours, 50),
            'p90_hours' => self::percentile($hours, 90),
            'with_ship_by' => $withShipBy->count(),
            'on_time' => $onTime,
            'late' => $withShipBy->count() - $onTime,
            'on_time_rate' => $withShipBy->isEmpty() ? null : $onTime / $withShipBy->count() * 100,
        ];
    }

    /**
     * The nearest-rank percentile of sorted values.
     *
     * @param  Collection<int, float>  $sorted
     */
    public static function percentile(Collection $sorted, int $percentile): ?float
    {
        if ($sorted->isEmpty()) {
            return null;
        }

        $rank = (int) ceil($percentile / 100 * $sorted->count());

        return $sorted->get(max(1, $rank) - 1);
    }

    /**
     * Imported orders whose first package shipped in range, one row each:
     * the hours from import to that package, its ship date, and the ship-by.
     *
     * Packages are read through the `shipped_at` index for the range, and an
     * order with a package shipped before the range is left out, since its
     * first package shipped then.
     *
     * @return Collection<int, \stdClass>
     */
    private function shippedOrders(): Collection
    {
        $range = $this->tableFilters['date_range'] ?? [];
        $clientId = $this->clientId();
        $key = json_encode([$range['from'] ?? null, $range['until'] ?? null, $clientId]);

        return $this->orders[$key] ??= $this->readShippedOrders($range['from'] ?? null, $range['until'] ?? null, $clientId);
    }

    /**
     * @return Collection<int, \stdClass>
     */
    private function readShippedOrders(?string $from, ?string $until, ?string $clientId): Collection
    {
        $bounds = LocalDateRange::utcBounds($from, $until);

        $firstPackages = DB::table('packages')
            ->where('packages.status', PackageStatus::Shipped->value)
            ->whereNotNull('packages.shipped_at')
            ->when($bounds['from'], fn (Builder $query, string $bound): Builder => $query->where('packages.shipped_at', '>=', $bound))
            ->when($bounds['until'], fn (Builder $query, string $bound): Builder => $query->where('packages.shipped_at', '<', $bound))
            ->when($bounds['from'], fn (Builder $query, string $bound): Builder => $query->whereNotExists(fn (Builder $earlier): Builder => $earlier
                ->selectRaw('1')
                ->from('packages as earlier')
                ->whereColumn('earlier.shipment_id', 'packages.shipment_id')
                ->where('earlier.status', PackageStatus::Shipped->value)
                ->where('earlier.shipped_at', '<', $bound)))
            ->select('packages.shipment_id')
            ->selectRaw('MIN(packages.shipped_at) as first_shipped_at')
            ->selectRaw('MIN(packages.ship_date) as first_ship_date')
            ->groupBy('packages.shipment_id');

        return DB::table('shipments')
            ->joinSub($firstPackages, 'first_packages', 'first_packages.shipment_id', '=', 'shipments.id')
            ->leftJoin('channels', 'shipments.channel_id', '=', 'channels.id')
            ->leftJoin('clients', 'shipments.client_id', '=', 'clients.id')
            ->where('shipments.status', '!=', ShipmentStatus::Draft->value)
            ->whereNotNull('shipments.data_source_id')
            ->when($clientId, fn (Builder $query, string $id): Builder => $query->where('shipments.client_id', $id))
            ->get([
                'shipments.created_at',
                'shipments.ship_by',
                'shipments.channel_id',
                'shipments.client_id',
                'channels.name as channel_name',
                'clients.name as client_name',
                'first_packages.first_shipped_at',
                'first_packages.first_ship_date',
            ])
            ->map(function (object $order): object {
                // SQLite returns a date column with a time; MySQL does not.
                $order->first_ship_date = substr((string) $order->first_ship_date, 0, 10);
                $order->ship_by = $order->ship_by === null ? null : substr((string) $order->ship_by, 0, 10);
                $order->hours_to_ship = max(0, (strtotime($order->first_shipped_at.' UTC') - strtotime($order->created_at.' UTC')) / 3600);

                return $order;
            });
    }

    private function groupKey(object $order): string
    {
        return match ($this->groupBy) {
            'day' => $order->first_ship_date,
            'week' => Carbon::parse($order->first_ship_date)->startOfWeek()->toDateString(),
            'month' => substr($order->first_ship_date, 0, 7),
            'channel' => (string) ($order->channel_id ?? 'none'),
            default => (string) ($order->client_id ?? 'none'),
        };
    }

    private function groupLabel(string $key, object $order): string
    {
        return match ($this->groupBy) {
            'day' => Carbon::parse($key)->format('M j, Y'),
            'week' => (function () use ($key): string {
                $start = Carbon::parse($key);
                $end = $start->copy()->endOfWeek();

                return $start->month === $end->month
                    ? $start->format('M j').'–'.$end->format('j')
                    : $start->format('M j').' – '.$end->format('M j');
            })(),
            'month' => Carbon::createFromFormat('Y-m', $key)->format('F Y'),
            'channel' => $order->channel_name ?? 'Unknown',
            default => $order->client_name ?? 'No client',
        };
    }

    private function isCurrentPeriod(string $key): bool
    {
        $today = LocalDateRange::now();

        return match ($this->groupBy) {
            'day' => $key === $today->toDateString(),
            'week' => $key === $today->startOfWeek()->toDateString(),
            'month' => $key === $today->format('Y-m'),
            default => false,
        };
    }

    private function isPeriodGrouping(): bool
    {
        return in_array($this->groupBy, self::PERIODS, true);
    }

    private function clientId(): ?string
    {
        if (! $this->multiClientEnabled()) {
            return null;
        }

        $value = $this->tableFilters['client']['value'] ?? null;

        return filled($value) ? (string) $value : null;
    }

    private function multiClientEnabled(): bool
    {
        return (bool) app(SettingsService::class)->get('multi_client_enabled', false);
    }
}
