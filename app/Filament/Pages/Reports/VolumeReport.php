<?php

namespace App\Filament\Pages\Reports;

use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Filament\Concerns\DescribesReportRange;
use App\Filament\Resources\PackageResource;
use App\Filament\Support\LocalDateRange;
use App\Models\Carrier;
use App\Models\DailyShippingStat;
use App\Models\Location;
use App\Services\SettingsService;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Shipped packages and their postage, from the `daily_shipping_stats` rollup,
 * grouped by one dimension at a time. Every row links to the Packages list
 * filtered to exactly the packages it counts: shipped, by ship date, over the
 * same range and Location.
 */
class VolumeReport extends Page implements HasTable
{
    use DescribesReportRange;
    use InteractsWithTable;

    /**
     * The Group by choices, in the order the control lists them.
     *
     * @var array<string, string>
     */
    public const GROUPINGS = [
        'channel' => 'Channel',
        'shipping_method' => 'Shipping Method',
        'carrier' => 'Carrier',
        'day' => 'Day',
        'week' => 'Week',
        'month' => 'Month',
    ];

    private const PERIODS = ['day', 'week', 'month'];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Volume';

    protected static ?string $title = 'Volume';

    protected static UnitEnum|string|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.pages.reports.volume-report';

    public string $groupBy = 'channel';

    /**
     * Total packages in range, keyed by the filter state it was counted under,
     * so the Share column counts it once per render.
     *
     * @var array<string, int>
     */
    private array $totalPackages = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->role->isAtLeast(Role::Manager) ?? false;
    }

    public function updatedGroupBy(): void
    {
        if (! array_key_exists($this->groupBy, self::GROUPINGS)) {
            $this->groupBy = 'channel';
        }

        // Each grouping opens on its own range: a year of months, about a
        // quarter of weeks, a month of anything else.
        $this->tableFilters['date_range'] = ['from' => $this->defaultFrom(), 'until' => null];

        $this->resetTable();
    }

    private function defaultFrom(): string
    {
        return match ($this->groupBy) {
            'month' => LocalDateRange::now()->subYear()->format('Y-m-d'),
            'week' => LocalDateRange::now()->subDays(90)->format('Y-m-d'),
            default => LocalDateRange::now()->subDays(30)->format('Y-m-d'),
        };
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->groupedQuery())
            ->defaultSort($this->isPeriodGrouping() ? 'group_name' : 'package_count', 'desc')
            ->defaultKeySort(false)
            ->columns([
                Tables\Columns\TextColumn::make('group_name')
                    ->label($this->isPeriodGrouping() ? 'Period' : self::GROUPINGS[$this->groupBy])
                    ->sortable()
                    ->formatStateUsing(fn (string $state): string => $this->groupLabel($state))
                    ->description(fn (Model $record): ?string => $this->isCurrentPeriod((string) $record->getAttribute('group_name')) ? 'In progress, not a full '.$this->groupBy : null)
                    ->url(fn (Model $record): ?string => $this->buildDrillDownUrl($record)),
                Tables\Columns\TextColumn::make('package_count')
                    ->label('Packages')
                    ->numeric()
                    ->summarize(Sum::make()->label('Total'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('share')
                    ->label('Share')
                    ->state(fn (Model $record): ?string => $this->shareOf($record)),
                Tables\Columns\TextColumn::make('total_cost')
                    ->label('Total Cost')
                    ->money('USD')
                    ->summarize(Sum::make()->money('USD')->label('Total'))
                    ->description(fn (Model $record): ?string => $this->uncostedNote($record))
                    ->sortable(),
                Tables\Columns\TextColumn::make('avg_cost')
                    ->label('Avg Cost')
                    ->money('USD')
                    ->placeholder('Unknown')
                    ->description(fn (Model $record): ?string => $this->averageBasisNote($record))
                    ->sortable(),
                Tables\Columns\TextColumn::make('avg_weight')
                    ->label('Avg Weight')
                    ->numeric(decimalPlaces: 2)
                    ->suffix(' lb')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('date_range')
                    ->form([
                        DatePicker::make('from')
                            ->default($this->defaultFrom()),
                        DatePicker::make('until'),
                    ])
                    ->columns(2)
                    ->default()
                    ->query(fn (Builder $query, array $data): Builder => $this->withinDates($query, $data['from'] ?? null, $data['until'] ?? null)),
                Tables\Filters\SelectFilter::make('location')
                    ->label('Location')
                    ->options(fn (): array => Location::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => $this->atLocation($query, $data['value'] ?? null))
                    ->visible(fn (): bool => $this->multiLocationEnabled()),
            ], layout: FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->filtersFormColumns(3);
    }

    public function resolveTableRecord(?string $key): ?Model
    {
        return DailyShippingStat::find($key);
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
     * The rows the table shows, filtered and sorted as shown.
     */
    public function exportCsv(): StreamedResponse
    {
        $rows = $this->getFilteredSortedTableQuery()?->get() ?? collect();
        $heading = $this->isPeriodGrouping() ? 'Period' : self::GROUPINGS[$this->groupBy];
        $filename = 'volume-by-'.str_replace('_', '-', $this->groupBy).'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows, $heading): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, [$heading, 'Packages', 'Share', 'Total Cost', 'Unpriced Packages', 'Avg Cost', 'Avg Weight (lb)']);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $this->groupLabel((string) $row->getAttribute('group_name')),
                    (int) $row->getAttribute('package_count'),
                    $this->shareOf($row),
                    number_format((float) $row->getAttribute('total_cost'), 2, '.', ''),
                    (int) $row->getAttribute('uncosted_package_count'),
                    $row->getAttribute('avg_cost') === null ? null : number_format((float) $row->getAttribute('avg_cost'), 2, '.', ''),
                    $row->getAttribute('avg_weight') === null ? null : number_format((float) $row->getAttribute('avg_weight'), 2, '.', ''),
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * The rollup grouped by the chosen dimension: a display name, the id a
     * drill-down filters by, and the aggregates.
     *
     * @return Builder<DailyShippingStat>
     */
    private function groupedQuery(): Builder
    {
        $query = DailyShippingStat::query();

        [$nameExpression, $idExpression] = match ($this->groupBy) {
            'shipping_method' => ["COALESCE(shipping_methods.name, 'Unmapped')", 'daily_shipping_stats.shipping_method_id'],
            'carrier' => ["COALESCE(daily_shipping_stats.carrier, 'Unknown')", 'daily_shipping_stats.carrier'],
            'day', 'week', 'month' => [$this->periodGroupExpression(), null],
            default => ["COALESCE(channels.name, 'Unknown')", 'daily_shipping_stats.channel_id'],
        };

        match ($this->groupBy) {
            'shipping_method' => $query->leftJoin('shipping_methods', 'daily_shipping_stats.shipping_method_id', '=', 'shipping_methods.id'),
            'channel' => $query->leftJoin('channels', 'daily_shipping_stats.channel_id', '=', 'channels.id'),
            default => null,
        };

        $query->select([
            DB::raw("{$nameExpression} as group_name"),
            DB::raw('SUM(daily_shipping_stats.package_count) as package_count'),
            DB::raw('SUM(daily_shipping_stats.package_count) - SUM(daily_shipping_stats.costed_package_count) as uncosted_package_count'),
            DB::raw('SUM(daily_shipping_stats.total_cost) as total_cost'),
            DB::raw($this->averageCostExpression()),
            DB::raw('CASE WHEN SUM(daily_shipping_stats.package_count) > 0 THEN 1.0 * SUM(daily_shipping_stats.total_weight) / SUM(daily_shipping_stats.package_count) ELSE NULL END as avg_weight'),
            DB::raw('MIN(daily_shipping_stats.id) as id'),
        ]);

        if ($idExpression === null) {
            return $query->groupBy('group_name');
        }

        return $query
            ->addSelect(DB::raw("{$idExpression} as group_id"))
            ->groupBy('group_name', 'group_id');
    }

    /**
     * Average cost over the packages that actually reported one.
     *
     * Dividing by `package_count` would put unpriced packages in the
     * denominator with nothing in the numerator, dragging the average toward
     * zero. When nothing in the group reported a cost the average is unknown,
     * not zero.
     */
    private function averageCostExpression(): string
    {
        $costed = 'SUM(daily_shipping_stats.costed_package_count)';

        return "CASE WHEN {$costed} > 0 THEN 1.0 * SUM(daily_shipping_stats.total_cost) / {$costed} ELSE NULL END as avg_cost";
    }

    /**
     * @param  Builder<DailyShippingStat>  $query
     * @return Builder<DailyShippingStat>
     */
    private function withinDates(Builder $query, ?string $from, ?string $until): Builder
    {
        return $query
            ->when(filled($from), fn (Builder $query): Builder => $query->where('daily_shipping_stats.date', '>=', $from))
            ->when(filled($until), fn (Builder $query): Builder => $query->where('daily_shipping_stats.date', '<=', $until));
    }

    /**
     * @param  Builder<DailyShippingStat>  $query
     * @return Builder<DailyShippingStat>
     */
    private function atLocation(Builder $query, mixed $locationId): Builder
    {
        return $query->when(filled($locationId), fn (Builder $query): Builder => $query->where('daily_shipping_stats.location_id', $locationId));
    }

    /**
     * The row's packages as a share of every package in range.
     */
    private function shareOf(Model $record): ?string
    {
        $total = $this->totalPackagesInRange();

        if ($total < 1) {
            return null;
        }

        return number_format((int) $record->getAttribute('package_count') / $total * 100, 1).'%';
    }

    private function totalPackagesInRange(): int
    {
        ['from' => $from, 'until' => $until] = $this->dateRange();
        $locationId = $this->locationId();
        $key = json_encode([$from, $until, $locationId]);

        return $this->totalPackages[$key] ??= (int) $this->atLocation(
            $this->withinDates(DailyShippingStat::query(), $from, $until),
            $locationId,
        )->sum('daily_shipping_stats.package_count');
    }

    /**
     * @return array{from: ?string, until: ?string}
     */
    private function dateRange(): array
    {
        $state = $this->tableFilters['date_range'] ?? [];

        return [
            'from' => filled($state['from'] ?? null) ? (string) $state['from'] : null,
            'until' => filled($state['until'] ?? null) ? (string) $state['until'] : null,
        ];
    }

    private function locationId(): ?string
    {
        if (! $this->multiLocationEnabled()) {
            return null;
        }

        $value = $this->tableFilters['location']['value'] ?? null;

        return filled($value) ? (string) $value : null;
    }

    private function multiLocationEnabled(): bool
    {
        return (bool) app(SettingsService::class)->get('multi_location_enabled', false);
    }

    private function uncostedNote(Model $record): ?string
    {
        $uncosted = (int) ($record->getAttribute('uncosted_package_count') ?? 0);

        if ($uncosted < 1) {
            return null;
        }

        return 'excludes '.number_format($uncosted).' with no reported cost';
    }

    private function averageBasisNote(Model $record): ?string
    {
        $uncosted = (int) ($record->getAttribute('uncosted_package_count') ?? 0);

        if ($uncosted < 1) {
            return null;
        }

        $costed = (int) ($record->getAttribute('package_count') ?? 0) - $uncosted;

        return 'over '.number_format($costed).' priced';
    }

    private function isPeriodGrouping(): bool
    {
        return in_array($this->groupBy, self::PERIODS, true);
    }

    /**
     * The row's heading: a week as its dates ("Oct 12–18"), a month by name,
     * a carrier by its display name, anything else as it is.
     */
    private function groupLabel(string $groupName): string
    {
        return match ($this->groupBy) {
            'week' => $this->weekLabel($groupName),
            'month' => Carbon::createFromFormat('Y-m', $groupName)->format('F Y'),
            'carrier' => Carrier::labelForName($groupName),
            default => $groupName,
        };
    }

    private function weekLabel(string $groupName): string
    {
        $range = $this->weekDateRange($groupName);
        $start = Carbon::parse($range['from']);
        $end = Carbon::parse($range['until']);

        return $start->month === $end->month
            ? $start->format('M j').'–'.$end->format('j')
            : $start->format('M j').' – '.$end->format('M j');
    }

    /**
     * Whether the period is still running, so its figures are partial.
     */
    private function isCurrentPeriod(string $groupName): bool
    {
        $today = LocalDateRange::now()->toDateString();

        return match ($this->groupBy) {
            'day' => $groupName === $today,
            'week' => $this->weekDateRange($groupName)['from'] <= $today && $this->weekDateRange($groupName)['until'] >= $today,
            'month' => $groupName === LocalDateRange::now()->format('Y-m'),
            default => false,
        };
    }

    /**
     * The Packages list, filtered to the packages the row counts.
     *
     * A row with no id to filter by — an unknown channel, an unmapped method,
     * no carrier — has no link, rather than one listing something else.
     */
    private function buildDrillDownUrl(Model $record): ?string
    {
        $groupName = (string) $record->getAttribute('group_name');
        $groupId = $record->getAttribute('group_id');

        if (! $this->isPeriodGrouping() && blank($groupId)) {
            return null;
        }

        $range = $this->dateRange();

        if ($this->isPeriodGrouping()) {
            $period = match ($this->groupBy) {
                'week' => $this->weekDateRange($groupName),
                'month' => $this->monthDateRange($groupName),
                default => ['from' => $groupName, 'until' => $groupName],
            };

            // The first and last periods may be cut by the report's own range.
            $range = [
                'from' => $range['from'] !== null && $range['from'] > $period['from'] ? $range['from'] : $period['from'],
                'until' => $range['until'] !== null && $range['until'] < $period['until'] ? $range['until'] : $period['until'],
            ];
        }

        $filters = [
            'status' => ['value' => PackageStatus::Shipped->value],
            'ship_date' => array_filter([
                'ship_date_from' => $range['from'],
                'ship_date_until' => $range['until'],
            ]),
        ];

        if ($this->locationId() !== null) {
            $filters['location'] = ['value' => $this->locationId()];
        }

        match ($this->groupBy) {
            'channel' => $filters['channel'] = ['value' => $groupId],
            'shipping_method' => $filters['shipping_method'] = ['value' => $groupId],
            'carrier' => $filters['carrier'] = ['value' => $groupId],
            default => null,
        };

        return PackageResource::getUrl('index', ['filters' => $filters]);
    }

    /**
     * A week is named by its Monday ("YYYY-MM-DD").
     *
     * @return array{from: string, until: string}
     */
    private function weekDateRange(string $groupName): array
    {
        $start = Carbon::parse($groupName)->startOfDay();

        return [
            'from' => $start->format('Y-m-d'),
            'until' => $start->copy()->endOfWeek()->format('Y-m-d'),
        ];
    }

    /**
     * Parse "YYYY-MM" into its first and last day.
     *
     * @return array{from: string, until: string}
     */
    private function monthDateRange(string $groupName): array
    {
        $start = Carbon::createFromFormat('Y-m', $groupName)->startOfMonth();

        return [
            'from' => $start->format('Y-m-d'),
            'until' => $start->copy()->endOfMonth()->format('Y-m-d'),
        ];
    }

    private function periodGroupExpression(): string
    {
        $driver = DB::getDriverName();

        return match ($this->groupBy) {
            'day' => match ($driver) {
                'sqlite' => "strftime('%Y-%m-%d', daily_shipping_stats.date)",
                default => 'DATE(daily_shipping_stats.date)',
            },
            'week' => match ($driver) {
                'sqlite' => "date(daily_shipping_stats.date, 'weekday 0', '-6 days')",
                default => 'DATE_SUB(DATE(daily_shipping_stats.date), INTERVAL WEEKDAY(daily_shipping_stats.date) DAY)',
            },
            default => match ($driver) {
                'sqlite' => "strftime('%Y-%m', daily_shipping_stats.date)",
                default => "DATE_FORMAT(daily_shipping_stats.date, '%Y-%m')",
            },
        };
    }
}
