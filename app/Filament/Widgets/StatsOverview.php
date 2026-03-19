<?php

namespace App\Filament\Widgets;

use App\Enums\ShipmentStatus;
use App\Models\DailyShippingStat;
use App\Models\Location;
use App\Models\Shipment;
use Carbon\Carbon;
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
            $tz = Location::timezone();
            $localToday = now($tz)->startOfDay();
            $thisWeekStart = now($tz)->startOfWeek();
            $thisMonthStart = now($tz)->startOfMonth();

            $costStat = $this->buildCostStat($thisWeekStart, $tz);

            return [
                Stat::make('Pending Shipments', number_format(Shipment::query()->where('status', ShipmentStatus::Open)->count()))
                    ->description('Awaiting shipment')
                    ->descriptionIcon('heroicon-m-clock')
                    ->color('warning'),
                Stat::make('Shipped Today', number_format((int) DailyShippingStat::where('date', $localToday->toDateString())->sum('package_count')))
                    ->description('Packages shipped today')
                    ->descriptionIcon('heroicon-m-check-circle')
                    ->color('success'),
                Stat::make('Shipped This Week', number_format((int) DailyShippingStat::where('date', '>=', $thisWeekStart->toDateString())->sum('package_count')))
                    ->description('Packages this week')
                    ->descriptionIcon('heroicon-m-calendar')
                    ->color('primary'),
                Stat::make('Shipped This Month', number_format((int) DailyShippingStat::where('date', '>=', $thisMonthStart->toDateString())->sum('package_count')))
                    ->description('Packages this month')
                    ->descriptionIcon('heroicon-m-calendar-days')
                    ->color('primary'),
                $costStat,
            ];
        });
    }

    private function buildCostStat(Carbon $thisWeekStart, string $tz): Stat
    {
        $lastWeekStart = now($tz)->subWeek()->startOfWeek();
        $lastWeekEnd = now($tz)->subWeek()->endOfWeek();

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
