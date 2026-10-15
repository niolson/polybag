<?php

namespace App\Filament\Pages\Reports;

use App\Enums\Role;
use App\Filament\Resources\ShipmentResource;
use App\Models\DailyShippingStat;
use BackedEnum;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class VolumeReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Volume';

    protected static ?string $title = 'Volume';

    protected static UnitEnum|string|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.pages.reports.volume-report';

    public string $groupBy = 'channel';

    public static function canAccess(): bool
    {
        return auth()->user()?->role->isAtLeast(Role::Manager) ?? false;
    }

    public function updatedGroupBy(): void
    {
        if (! in_array($this->groupBy, ['channel', 'shipping_method', 'day', 'week', 'month'])) {
            $this->groupBy = 'channel';
        }

        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        $query = match ($this->groupBy) {
            'shipping_method' => DailyShippingStat::query()
                ->leftJoin('shipping_methods', 'daily_shipping_stats.shipping_method_id', '=', 'shipping_methods.id')
                ->select([
                    DB::raw('COALESCE(shipping_methods.name, "Unmapped") as group_name'),
                    DB::raw('daily_shipping_stats.shipping_method_id as group_id'),
                    DB::raw('SUM(daily_shipping_stats.package_count) as package_count'),
                    DB::raw($this->uncostedCountExpression('daily_shipping_stats.')),
                    DB::raw('SUM(daily_shipping_stats.total_cost) as total_cost'),
                    DB::raw($this->averageCostExpression('daily_shipping_stats.')),
                    DB::raw('MIN(daily_shipping_stats.id) as id'),
                ])
                ->groupBy('group_name', 'group_id'),
            'day', 'week', 'month' => DailyShippingStat::query()
                ->select([
                    DB::raw($this->periodGroupExpression()),
                    DB::raw('SUM(package_count) as package_count'),
                    DB::raw($this->uncostedCountExpression()),
                    DB::raw('SUM(total_cost) as total_cost'),
                    DB::raw($this->averageCostExpression()),
                    DB::raw('MIN(id) as id'),
                ])
                ->groupBy('group_name')
                ->orderByDesc('group_name'),
            default => DailyShippingStat::query()
                ->leftJoin('channels', 'daily_shipping_stats.channel_id', '=', 'channels.id')
                ->select([
                    DB::raw('COALESCE(channels.name, "Unknown") as group_name'),
                    DB::raw('daily_shipping_stats.channel_id as group_id'),
                    DB::raw('SUM(daily_shipping_stats.package_count) as package_count'),
                    DB::raw($this->uncostedCountExpression('daily_shipping_stats.')),
                    DB::raw('SUM(daily_shipping_stats.total_cost) as total_cost'),
                    DB::raw($this->averageCostExpression('daily_shipping_stats.')),
                    DB::raw('MIN(daily_shipping_stats.id) as id'),
                ])
                ->groupBy('group_name', 'group_id'),
        };

        $defaultFrom = match ($this->groupBy) {
            'month' => now()->subYear()->format('Y-m-d'),
            'week' => now()->subDays(90)->format('Y-m-d'),
            default => now()->subDays(30)->format('Y-m-d'),
        };

        return $table
            ->query($query)
            ->defaultSort('package_count', 'desc')
            ->defaultKeySort(false)
            ->columns([
                Tables\Columns\TextColumn::make('group_name')
                    ->label(match ($this->groupBy) {
                        'shipping_method' => 'Shipping Method',
                        'day', 'week', 'month' => 'Period',
                        default => 'Channel',
                    })
                    ->sortable()
                    ->url(fn (Model $record): ?string => $this->buildDrillDownUrl($record)),
                Tables\Columns\TextColumn::make('package_count')
                    ->label('Packages')
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_cost')
                    ->label('Total Cost')
                    ->money('USD')
                    ->description(fn (Model $record): ?string => $this->uncostedNote($record))
                    ->sortable(),
                Tables\Columns\TextColumn::make('avg_cost')
                    ->label('Avg Cost')
                    ->money('USD')
                    ->placeholder('Unknown')
                    ->description(fn (Model $record): ?string => $this->averageBasisNote($record))
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('date_range')
                    ->form([
                        DatePicker::make('from')
                            ->default($defaultFrom),
                        DatePicker::make('until'),
                    ])
                    ->columns(2)
                    ->default()
                    ->query(function ($query, array $data) {
                        $col = in_array($this->groupBy, ['channel', 'shipping_method'])
                            ? 'daily_shipping_stats.date'
                            : 'date';

                        return $query
                            ->when($data['from'], fn ($q, $date) => $q->where($col, '>=', $date))
                            ->when($data['until'], fn ($q, $date) => $q->where($col, '<=', $date));
                    }),
            ], layout: FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->filtersFormColumns(2);
    }

    public function resolveTableRecord(?string $key): ?Model
    {
        return DailyShippingStat::find($key);
    }

    /**
     * Packages the rollup counted but could not price.
     *
     * A null `costed_package_count` is a row aggregated before the column
     * existed; falling back to `package_count` reports no gap, which is how
     * those rows already read.
     */
    private function uncostedCountExpression(string $prefix = ''): string
    {
        return "SUM({$prefix}package_count) - SUM(COALESCE({$prefix}costed_package_count, {$prefix}package_count)) as uncosted_package_count";
    }

    /**
     * Average cost over the packages that actually reported one.
     *
     * Dividing by `package_count` would put unpriced packages in the
     * denominator with nothing in the numerator, dragging the average toward
     * zero. When nothing in the group reported a cost the average is unknown,
     * not zero.
     */
    private function averageCostExpression(string $prefix = ''): string
    {
        $costed = "SUM(COALESCE({$prefix}costed_package_count, {$prefix}package_count))";

        return "CASE WHEN {$costed} > 0 THEN SUM({$prefix}total_cost) / {$costed} ELSE NULL END as avg_cost";
    }

    private function uncostedNote(Model $record): ?string
    {
        $uncosted = (int) ($record->uncosted_package_count ?? 0);

        if ($uncosted < 1) {
            return null;
        }

        return 'excludes '.number_format($uncosted).' with no reported cost';
    }

    private function averageBasisNote(Model $record): ?string
    {
        $uncosted = (int) ($record->uncosted_package_count ?? 0);

        if ($uncosted < 1) {
            return null;
        }

        $costed = (int) ($record->package_count ?? 0) - $uncosted;

        return 'over '.number_format($costed).' priced';
    }

    private function buildDrillDownUrl(Model $record): ?string
    {
        $filters = [];

        match ($this->groupBy) {
            'channel' => $filters['channel'] = ['value' => $record->group_id],
            'shipping_method' => $filters['shipping_method'] = ['value' => $record->group_id],
            'day' => $filters['created_at'] = [
                'created_from' => $record->group_name,
                'created_until' => $record->group_name,
            ],
            'week' => $filters['created_at'] = $this->weekDateRange($record->group_name),
            'month' => $filters['created_at'] = $this->monthDateRange($record->group_name),
        };

        if (empty($filters)) {
            return null;
        }

        return ShipmentResource::getUrl('index', [
            'filters' => $filters,
        ]);
    }

    /**
     * Parse "YYYY-W##" into a created_from/created_until range.
     *
     * @return array{created_from: string, created_until: string}
     */
    private function weekDateRange(string $groupName): array
    {
        // Format: "2026-W10"
        [$year, $week] = explode('-W', $groupName);
        $start = Carbon::now()->setISODate((int) $year, (int) $week)->startOfWeek();

        return [
            'created_from' => $start->format('Y-m-d'),
            'created_until' => $start->copy()->endOfWeek()->format('Y-m-d'),
        ];
    }

    /**
     * Parse "YYYY-MM" into a created_from/created_until range.
     *
     * @return array{created_from: string, created_until: string}
     */
    private function monthDateRange(string $groupName): array
    {
        $start = Carbon::createFromFormat('Y-m', $groupName)->startOfMonth();

        return [
            'created_from' => $start->format('Y-m-d'),
            'created_until' => $start->copy()->endOfMonth()->format('Y-m-d'),
        ];
    }

    private function periodGroupExpression(): string
    {
        $driver = DB::getDriverName();

        return match ($this->groupBy) {
            'day' => match ($driver) {
                'sqlite' => 'strftime("%Y-%m-%d", date) as group_name',
                default => 'DATE(date) as group_name',
            },
            'week' => match ($driver) {
                'sqlite' => 'strftime("%Y-W%W", date) as group_name',
                default => 'CONCAT(YEAR(date), "-W", LPAD(WEEK(date, 3), 2, "0")) as group_name',
            },
            default => match ($driver) {
                'sqlite' => 'strftime("%Y-%m", date) as group_name',
                default => 'DATE_FORMAT(date, "%Y-%m") as group_name',
            },
        };
    }
}
