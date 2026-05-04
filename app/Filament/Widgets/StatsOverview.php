<?php

namespace App\Filament\Widgets;

use App\Enums\ShipmentStatus;
use App\Models\DailyShippingStat;
use App\Models\Location;
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
        $data = Cache::remember('widget:stats_overview', 60, function () {
            $tz = Location::timezone();
            $localToday = now($tz)->startOfDay();
            $thisWeekStart = now($tz)->startOfWeek();
            $thisMonthStart = now($tz)->startOfMonth();
            $lastWeekStart = now($tz)->subWeek()->startOfWeek();
            $lastWeekEnd = now($tz)->subWeek()->endOfWeek();

            return [
                'pending' => Shipment::query()->where('status', ShipmentStatus::Open)->count(),
                'shipped_today' => (int) DailyShippingStat::where('date', $localToday->toDateString())->sum('package_count'),
                'shipped_week' => (int) DailyShippingStat::where('date', '>=', $thisWeekStart->toDateString())->sum('package_count'),
                'shipped_month' => (int) DailyShippingStat::where('date', '>=', $thisMonthStart->toDateString())->sum('package_count'),
                'cost_this_week' => (float) DailyShippingStat::where('date', '>=', $thisWeekStart->toDateString())->sum('total_cost'),
                'cost_last_week' => (float) DailyShippingStat::whereBetween('date', [$lastWeekStart->toDateString(), $lastWeekEnd->toDateString()])->sum('total_cost'),
            ];
        });

        return [
            Stat::make('Pending Shipments', number_format($data['pending']))
                ->description('Awaiting shipment')
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning'),
            Stat::make('Shipped Today', number_format($data['shipped_today']))
                ->description('Packages shipped today')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),
            Stat::make('Shipped This Week', number_format($data['shipped_week']))
                ->description('Packages this week')
                ->descriptionIcon('heroicon-m-calendar')
                ->color('primary'),
            Stat::make('Shipped This Month', number_format($data['shipped_month']))
                ->description('Packages this month')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('primary'),
            $this->buildCostStat($data['cost_this_week'], $data['cost_last_week']),
        ];
    }

    private function buildCostStat(float $thisWeekCost, float $lastWeekCost): Stat
    {
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
