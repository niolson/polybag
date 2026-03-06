<?php

namespace App\Filament\Pages\Reports;

use App\Enums\Role;
use App\Models\DailyShippingStat;
use BackedEnum;
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

    protected static ?string $navigationLabel = 'Volume Report';

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
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        $query = match ($this->groupBy) {
            'shipping_method' => DailyShippingStat::query()
                ->leftJoin('shipping_methods', 'daily_shipping_stats.shipping_method_id', '=', 'shipping_methods.id')
                ->select([
                    DB::raw('COALESCE(shipping_methods.name, "Unmapped") as group_name'),
                    DB::raw('SUM(daily_shipping_stats.package_count) as package_count'),
                    DB::raw('SUM(daily_shipping_stats.total_cost) as total_cost'),
                    DB::raw('CASE WHEN SUM(daily_shipping_stats.package_count) > 0 THEN SUM(daily_shipping_stats.total_cost) / SUM(daily_shipping_stats.package_count) ELSE 0 END as avg_cost'),
                    DB::raw('MIN(daily_shipping_stats.id) as id'),
                ])
                ->groupBy('group_name'),
            'day', 'week', 'month' => DailyShippingStat::query()
                ->select([
                    DB::raw($this->periodGroupExpression()),
                    DB::raw('SUM(package_count) as package_count'),
                    DB::raw('SUM(total_cost) as total_cost'),
                    DB::raw('CASE WHEN SUM(package_count) > 0 THEN SUM(total_cost) / SUM(package_count) ELSE 0 END as avg_cost'),
                    DB::raw('MIN(id) as id'),
                ])
                ->groupBy('group_name')
                ->orderByDesc('group_name'),
            default => DailyShippingStat::query()
                ->leftJoin('channels', 'daily_shipping_stats.channel_id', '=', 'channels.id')
                ->select([
                    DB::raw('COALESCE(channels.name, "Unknown") as group_name'),
                    DB::raw('SUM(daily_shipping_stats.package_count) as package_count'),
                    DB::raw('SUM(daily_shipping_stats.total_cost) as total_cost'),
                    DB::raw('CASE WHEN SUM(daily_shipping_stats.package_count) > 0 THEN SUM(daily_shipping_stats.total_cost) / SUM(daily_shipping_stats.package_count) ELSE 0 END as avg_cost'),
                    DB::raw('MIN(daily_shipping_stats.id) as id'),
                ])
                ->groupBy('group_name'),
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
                    ->sortable(),
                Tables\Columns\TextColumn::make('package_count')
                    ->label('Packages')
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_cost')
                    ->label('Total Cost')
                    ->money('USD')
                    ->sortable(),
                Tables\Columns\TextColumn::make('avg_cost')
                    ->label('Avg Cost')
                    ->money('USD')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('date_range')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')
                            ->default($defaultFrom),
                        \Filament\Forms\Components\DatePicker::make('until'),
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
