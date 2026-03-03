<?php

namespace App\Filament\Widgets;

use App\Models\DailyShippingStat;
use App\Models\Shipment;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

class StatsOverview extends BaseWidget
{
    protected static ?int $sort = -4;

    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        return Cache::remember('widget:stats_overview', 60, function () {
            $thisWeekStart = now()->startOfWeek();

            $costStat = $this->buildCostStat($thisWeekStart);

            return [
                Stat::make('Pending Shipments', Shipment::query()->where('shipped', false)->count())
                    ->description('Awaiting shipment')
                    ->color('warning'),
                Stat::make('Shipped Today', (int) DailyShippingStat::where('date', today()->toDateString())->sum('package_count'))
                    ->description('Packages shipped today')
                    ->color('success'),
                Stat::make('Shipped This Week', (int) DailyShippingStat::where('date', '>=', $thisWeekStart->toDateString())->sum('package_count'))
                    ->description('Packages this week')
                    ->color('info'),
                Stat::make('Shipped This Month', (int) DailyShippingStat::where('date', '>=', now()->startOfMonth()->toDateString())->sum('package_count'))
                    ->description('Packages this month')
                    ->color('info'),
                $costStat,
            ];
        });
    }

    private function buildCostStat(\Carbon\Carbon $thisWeekStart): Stat
    {
        $lastWeekStart = now()->subWeek()->startOfWeek();
        $lastWeekEnd = now()->subWeek()->endOfWeek();

        $thisWeekCost = (float) DailyShippingStat::where('date', '>=', $thisWeekStart->toDateString())
            ->sum('total_cost');

        $lastWeekCost = (float) DailyShippingStat::whereBetween('date', [$lastWeekStart->toDateString(), $lastWeekEnd->toDateString()])
            ->sum('total_cost');

        $stat = Stat::make('Shipping Cost This Week', '$'.number_format($thisWeekCost, 2));

        if ($lastWeekCost > 0) {
            $change = (($thisWeekCost - $lastWeekCost) / $lastWeekCost) * 100;
            $stat = $stat->description(($change >= 0 ? '+' : '').number_format($change, 1).'% vs last week')
                ->descriptionIcon($change <= 0 ? 'heroicon-m-arrow-trending-down' : 'heroicon-m-arrow-trending-up')
                ->color($change <= 0 ? 'success' : 'danger');
        }

        return $stat;
    }
}
