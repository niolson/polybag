<?php

namespace App\Filament\Widgets;

use App\Models\Package;
use App\Models\Shipment;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends BaseWidget
{
    protected static ?int $sort = -4;

    protected function getStats(): array
    {
        $thisWeekStart = now()->startOfWeek();

        $costStat = $this->buildCostStat($thisWeekStart);

        return [
            Stat::make('Pending Shipments', Shipment::query()->where('shipped', false)->count())
                ->description('Awaiting shipment')
                ->color('warning'),
            Stat::make('Shipped Today', Package::query()->where('shipped', true)->whereDate('shipped_at', today())->count())
                ->description('Packages shipped today')
                ->color('success'),
            Stat::make('Shipped This Week', Package::query()->where('shipped', true)->where('shipped_at', '>=', $thisWeekStart)->count())
                ->description('Packages this week')
                ->color('info'),
            Stat::make('Shipped This Month', Package::query()->where('shipped', true)->where('shipped_at', '>=', now()->startOfMonth())->count())
                ->description('Packages this month')
                ->color('info'),
            $costStat,
        ];
    }

    private function buildCostStat(\Carbon\Carbon $thisWeekStart): Stat
    {
        $lastWeekStart = now()->subWeek()->startOfWeek();
        $lastWeekEnd = now()->subWeek()->endOfWeek();

        $thisWeekCost = (float) Package::query()
            ->where('shipped', true)
            ->where('shipped_at', '>=', $thisWeekStart)
            ->sum('cost');

        $lastWeekCost = (float) Package::query()
            ->where('shipped', true)
            ->whereBetween('shipped_at', [$lastWeekStart, $lastWeekEnd])
            ->sum('cost');

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
