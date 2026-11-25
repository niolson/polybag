<?php

namespace App\Filament\Pages\Reports;

use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Enums\TrackingStatus;
use App\Filament\Concerns\DescribesReportRange;
use App\Filament\Support\LocalDateRange;
use App\Filament\Support\Percentile;
use App\Models\Carrier;
use App\Models\Client;
use App\Models\Location;
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
use stdClass;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * How each carrier and service performs once a parcel has left
 * (`reports-review/07`), over the packages shipped in range.
 *
 * Transit is calendar days from purchase, `shipped_at`, to the tracked
 * `delivered_at`. A package is on time when its delivery's local day is on or
 * before the Shipment's `deliver_by`; it is late when delivered after it, or
 * still undelivered once it has passed. One that has a deliver-by still ahead
 * and is undelivered is not yet either, and is left out of the rate.
 * Exceptions and stuck pre-transit are counted as the dashboard counts them:
 * the Exception status, and Pre-Transit more than 48 hours after shipping.
 */
class DeliveryPerformanceReport extends Page implements HasTable
{
    use DescribesReportRange;
    use InteractsWithTable;

    /**
     * The Group by choices, in the order the control lists them.
     *
     * @var array<string, string>
     */
    public const GROUPINGS = [
        'carrier' => 'Carrier',
        'service' => 'Service',
        'destination' => 'Destination',
        'week' => 'Week Shipped',
    ];

    private const STUCK_PRE_TRANSIT_HOURS = 48;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Delivery Performance';

    protected static ?string $title = 'Delivery Performance';

    protected static UnitEnum|string|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 32;

    protected string $view = 'filament.pages.reports.delivery-performance-report';

    public string $groupBy = 'carrier';

    /**
     * Packages shipped in range, keyed by the filter state they were read
     * under, so a render reads them once.
     *
     * @var array<string, Collection<int, stdClass>>
     */
    private array $packages = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->role->isAtLeast(Role::Manager) ?? false;
    }

    public function updatedGroupBy(): void
    {
        if (! array_key_exists($this->groupBy, self::GROUPINGS)) {
            $this->groupBy = 'carrier';
        }

        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (?string $sortColumn, ?string $sortDirection, int $page, int $recordsPerPage): LengthAwarePaginator => $this->paginate(
                $this->sortedRows($sortColumn, $sortDirection),
                $page,
                $recordsPerPage,
            ))
            ->columns([
                Tables\Columns\TextColumn::make('group')
                    ->label(self::GROUPINGS[$this->groupBy])
                    ->sortable(),
                Tables\Columns\TextColumn::make('packages')
                    ->label('Packages')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('delivered_rate')
                    ->label('Delivered')
                    ->formatStateUsing(fn (?float $state, array $record): string => self::percent($state).' ('.number_format($record['delivered']).')')
                    ->sortable(),
                Tables\Columns\TextColumn::make('median_days')
                    ->label('Median Transit')
                    ->formatStateUsing(fn (?float $state): string => self::days($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('p90_days')
                    ->label('90% Delivered Within')
                    ->formatStateUsing(fn (?float $state): string => self::days($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('on_time_rate')
                    ->label('On Time vs Deliver-By')
                    ->formatStateUsing(fn (?float $state): string => self::percent($state))
                    ->description(fn (array $record): ?string => $record['judged'] > 0 ? 'of '.number_format($record['judged']).' due' : null)
                    ->placeholder('—')
                    ->sortable(),
                Tables\Columns\TextColumn::make('late')
                    ->label('Late')
                    ->numeric()
                    ->color(fn (array $record): ?string => $record['late'] > 0 ? 'danger' : null)
                    ->sortable(),
                Tables\Columns\TextColumn::make('exceptions')
                    ->label('Exceptions')
                    ->numeric()
                    ->color(fn (array $record): ?string => $record['exceptions'] > 0 ? 'danger' : null)
                    ->sortable(),
                Tables\Columns\TextColumn::make('returned')
                    ->label('Returned')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('stuck_pre_transit')
                    ->label('Stuck Pre-Transit')
                    ->numeric()
                    ->color(fn (array $record): ?string => $record['stuck_pre_transit'] > 0 ? 'warning' : null)
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('date_range')
                    ->form([
                        DatePicker::make('from')
                            ->default(LocalDateRange::now()->subDays(30)->format('Y-m-d')),
                        DatePicker::make('until'),
                    ])
                    ->columns(2)
                    ->default(),
                Tables\Filters\SelectFilter::make('location')
                    ->label('Location')
                    ->options(fn (): array => Location::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->visible(fn (): bool => $this->multiLocationEnabled()),
                Tables\Filters\SelectFilter::make('client')
                    ->label('Client')
                    ->options(fn (): array => Client::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->visible(fn (): bool => $this->multiClientEnabled()),
            ], layout: FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->filtersFormColumns(4)
            ->emptyStateHeading('No packages shipped in this range');
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
        $filename = 'delivery-performance-by-'.$this->groupBy.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, [self::GROUPINGS[$this->groupBy], 'Packages', 'Delivered', 'Delivered %', 'Median Transit Days', '90th Percentile Transit Days', 'Due by Deliver-By', 'On Time', 'Late', 'On-Time %', 'Exceptions', 'Returned', 'Stuck Pre-Transit']);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['group'],
                    $row['packages'],
                    $row['delivered'],
                    self::decimal($row['delivered_rate']),
                    self::decimal($row['median_days']),
                    self::decimal($row['p90_days']),
                    $row['judged'],
                    $row['on_time'],
                    $row['late'],
                    self::decimal($row['on_time_rate']),
                    $row['exceptions'],
                    $row['returned'],
                    $row['stuck_pre_transit'],
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * The whole range at once, for the headline.
     *
     * @return array<string, mixed>
     */
    public function getSummary(): array
    {
        return $this->summarize($this->shippedPackages());
    }

    public static function days(?float $days): string
    {
        return $days === null ? '—' : number_format($days, 1).' days';
    }

    public static function percent(?float $rate): string
    {
        return $rate === null ? '—' : number_format($rate, 1).'%';
    }

    private static function decimal(?float $value): ?string
    {
        return $value === null ? null : number_format($value, 1, '.', '');
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
        $column = match (true) {
            $sortColumn === 'group' => 'sort_key',
            filled($sortColumn) => $sortColumn,
            $this->groupBy === 'week' => 'sort_key',
            default => 'packages',
        };
        $descending = blank($sortColumn) ? true : $sortDirection === 'desc';

        return $this->groupedRows()->sortBy(fn (array $row): mixed => $row[$column] ?? -1, SORT_REGULAR, $descending);
    }

    /**
     * One row per group, keyed by the group.
     *
     * @return Collection<array-key, array<string, mixed>>
     */
    private function groupedRows(): Collection
    {
        /** @var Collection<array-key, array<string, mixed>> $rows */
        $rows = $this->shippedPackages()
            ->groupBy(fn (stdClass $package): string => $this->groupKey($package))
            ->map(function (Collection $packages, string $key): array {
                $label = $this->groupLabel($key, $packages->first());

                return [
                    'group' => $label,
                    'sort_key' => $this->groupBy === 'week' ? $key : $label,
                    ...$this->summarize($packages),
                ];
            });

        return $rows;
    }

    /**
     * @param  Collection<int, stdClass>  $packages
     * @return array{packages: int, delivered: int, delivered_rate: ?float, median_days: ?float, p90_days: ?float, judged: int, on_time: int, late: int, on_time_rate: ?float, exceptions: int, returned: int, stuck_pre_transit: int}
     */
    private function summarize(Collection $packages): array
    {
        $today = LocalDateRange::now()->toDateString();
        $stuckBefore = now()->subHours(self::STUCK_PRE_TRANSIT_HOURS)->getTimestamp();

        $delivered = $packages->filter(fn (stdClass $package): bool => $package->delivered_at !== null);
        $transit = $delivered->map(fn (stdClass $package): float => max(0, $package->delivered_ts - $package->shipped_ts) / 86400);

        // Judged against deliver-by: delivered, or still out once it passed.
        $onTime = 0;
        $late = 0;

        foreach ($packages as $package) {
            if ($package->deliver_by === null) {
                continue;
            }

            if ($package->delivered_date !== null) {
                $package->delivered_date <= $package->deliver_by ? $onTime++ : $late++;
            } elseif ($package->deliver_by < $today) {
                $late++;
            }
        }

        $judged = $onTime + $late;

        return [
            'packages' => $packages->count(),
            'delivered' => $delivered->count(),
            'delivered_rate' => $packages->isEmpty() ? null : $delivered->count() / $packages->count() * 100,
            'median_days' => Percentile::of($transit, 50),
            'p90_days' => Percentile::of($transit, 90),
            'judged' => $judged,
            'on_time' => $onTime,
            'late' => $late,
            'on_time_rate' => $judged === 0 ? null : $onTime / $judged * 100,
            'exceptions' => $packages->where('tracking_status', TrackingStatus::Exception->value)->count(),
            'returned' => $packages->where('tracking_status', TrackingStatus::Returned->value)->count(),
            'stuck_pre_transit' => $packages->filter(fn (stdClass $package): bool => $package->tracking_status === TrackingStatus::PreTransit->value
                && $package->shipped_ts <= $stuckBefore)->count(),
        ];
    }

    /**
     * Shipped packages in range, one row each with what the groups and the
     * figures need.
     *
     * @return Collection<int, stdClass>
     */
    private function shippedPackages(): Collection
    {
        $range = $this->tableFilters['date_range'] ?? [];
        $locationId = $this->filterValue('location', $this->multiLocationEnabled());
        $clientId = $this->filterValue('client', $this->multiClientEnabled());
        $key = json_encode([$range['from'] ?? null, $range['until'] ?? null, $locationId, $clientId]);

        return $this->packages[$key] ??= $this->readShippedPackages($range['from'] ?? null, $range['until'] ?? null, $locationId, $clientId);
    }

    /**
     * @return Collection<int, stdClass>
     */
    private function readShippedPackages(?string $from, ?string $until, ?string $locationId, ?string $clientId): Collection
    {
        $bounds = LocalDateRange::utcBounds($from, $until);
        $timezone = Location::timezone();

        return DB::table('packages')
            ->join('shipments', 'packages.shipment_id', '=', 'shipments.id')
            ->leftJoin('locations', 'packages.location_id', '=', 'locations.id')
            ->leftJoin('package_labels as active_label', 'active_label.active_package_id', '=', 'packages.id')
            ->leftJoin('carrier_services', 'active_label.carrier_service_id', '=', 'carrier_services.id')
            ->where('packages.status', PackageStatus::Shipped->value)
            ->whereNotNull('packages.shipped_at')
            ->when($bounds['from'], fn (Builder $query, string $bound): Builder => $query->where('packages.shipped_at', '>=', $bound))
            ->when($bounds['until'], fn (Builder $query, string $bound): Builder => $query->where('packages.shipped_at', '<', $bound))
            ->when($locationId, fn (Builder $query, string $id): Builder => $query->where('packages.location_id', $id))
            ->when($clientId, fn (Builder $query, string $id): Builder => $query->where('shipments.client_id', $id))
            ->get([
                'packages.carrier',
                'packages.shipped_at',
                'packages.delivered_at',
                'packages.tracking_status',
                'shipments.deliver_by',
                'shipments.country as destination_country',
                'locations.country as origin_country',
                'active_label.carrier_service_id',
                'carrier_services.name as service_name',
            ])
            ->map(function (stdClass $package) use ($timezone): stdClass {
                $package->shipped_ts = (int) strtotime($package->shipped_at.' UTC');
                $package->delivered_ts = $package->delivered_at === null ? null : (int) strtotime($package->delivered_at.' UTC');
                $package->delivered_date = $package->delivered_at === null
                    ? null
                    : Carbon::parse($package->delivered_at, 'UTC')->setTimezone($timezone)->toDateString();
                // SQLite returns a date column with a time; MySQL does not.
                $package->deliver_by = $package->deliver_by === null ? null : substr((string) $package->deliver_by, 0, 10);
                $package->shipped_week = Carbon::parse($package->shipped_at, 'UTC')->setTimezone($timezone)->startOfWeek()->toDateString();

                return $package;
            });
    }

    private function groupKey(stdClass $package): string
    {
        return match ($this->groupBy) {
            'service' => ($package->carrier ?? '').'|'.($package->carrier_service_id ?? ''),
            'destination' => $this->isDomestic($package) ? 'domestic' : 'international',
            'week' => $package->shipped_week,
            default => (string) ($package->carrier ?? ''),
        };
    }

    private function groupLabel(string $key, stdClass $package): string
    {
        $carrier = filled($package->carrier) ? Carrier::labelForName((string) $package->carrier) : 'Unknown carrier';

        return match ($this->groupBy) {
            'service' => $carrier.' '.($package->service_name ?? '(not a catalog service)'),
            'destination' => $key === 'domestic' ? 'Domestic' : 'International',
            'week' => (function () use ($key): string {
                $start = Carbon::parse($key);
                $end = $start->copy()->endOfWeek();

                return $start->month === $end->month
                    ? $start->format('M j').'–'.$end->format('j')
                    : $start->format('M j').' – '.$end->format('M j');
            })(),
            default => $carrier,
        };
    }

    /**
     * Domestic when the parcel stays in the country of the Location it left.
     */
    private function isDomestic(stdClass $package): bool
    {
        return strtoupper((string) $package->destination_country) === strtoupper((string) ($package->origin_country ?? 'US'));
    }

    private function filterValue(string $filter, bool $enabled): ?string
    {
        if (! $enabled) {
            return null;
        }

        $value = $this->tableFilters[$filter]['value'] ?? null;

        return filled($value) ? (string) $value : null;
    }

    private function multiClientEnabled(): bool
    {
        return (bool) app(SettingsService::class)->get('multi_client_enabled', false);
    }

    private function multiLocationEnabled(): bool
    {
        return (bool) app(SettingsService::class)->get('multi_location_enabled', false);
    }
}
