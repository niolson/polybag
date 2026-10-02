<?php

namespace App\Filament\Widgets;

use App\Enums\Role;
use App\Enums\ShipmentStatus;
use App\Filament\Resources\ShipmentResource;
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

    /**
     * Packages counted but never priced. A null `costed_package_count` predates
     * the column, so it falls back to `package_count` and reports no gap.
     */
    private const UNCOSTED = 'SUM(package_count) - SUM(COALESCE(costed_package_count, package_count)) as uncosted';

    protected function getStats(): array
    {
        if (auth()->user()?->role === Role::User) {
            $pending = Cache::remember(
                'widget:stats_overview:shipper:v1',
                60,
                fn (): int => Shipment::query()->where('status', ShipmentStatus::Open)->count(),
            );

            return [
                Stat::make('Open Shipments', number_format($pending))
                    ->description('Not shipped yet')
                    ->descriptionIcon('heroicon-m-clock')
                    ->color('warning')
                    ->url(ShipmentResource::getUrl('index').'?status_tab='.ShipmentStatus::Open->value),
            ];
        }

        $data = Cache::remember('widget:stats_overview:v2', 60, function (): array {
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
                'uncosted_week' => (int) DailyShippingStat::where('date', '>=', $thisWeekStart->toDateString())
                    ->selectRaw(self::UNCOSTED)
                    ->value('uncosted'),
                'uncosted_last_week' => (int) DailyShippingStat::whereBetween('date', [$lastWeekStart->toDateString(), $lastWeekEnd->toDateString()])
                    ->selectRaw(self::UNCOSTED)
                    ->value('uncosted'),
            ];
        });

        return [
            Stat::make('Pending Shipments', number_format($data['pending']))
                ->description('Awaiting shipment')
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning')
                ->url(ShipmentResource::getUrl('index').'?status_tab='.ShipmentStatus::Open->value),
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
            $this->buildCostStat(
                $data['cost_this_week'],
                $data['cost_last_week'],
                $data['uncosted_week'],
                $data['uncosted_last_week'],
            ),
        ];
    }

    /**
     * The total is a sum of what was reported, not of what was spent: postage
     * bought where the seller reports no price contributes nothing to it. Say
     * how many packages that covers rather than letting the figure read as
     * complete.
     *
     * Where either week left postage unpriced, the week-over-week change is
     * withheld along with its color and arrow. A percentage between two
     * subtotals is not a percentage between two spends, and an understated
     * last week inflates it without looking any different — the strongest
     * claim on the card would be the one nobody measured.
     */
    private function buildCostStat(
        float $thisWeekCost,
        float $lastWeekCost,
        int $uncostedThisWeek,
        int $uncostedLastWeek,
    ): Stat {
        $stat = Stat::make('Shipping Cost This Week', '$'.number_format($thisWeekCost, 2));

        if ($uncostedThisWeek > 0 || $uncostedLastWeek > 0) {
            return $stat->description($this->uncostedNote($uncostedThisWeek, $uncostedLastWeek));
        }

        if ($lastWeekCost > 0) {
            $change = (($thisWeekCost - $lastWeekCost) / $lastWeekCost) * 100;
            $stat = $stat->description(($change >= 0 ? '+' : '').number_format($change, 1).'% vs last week')
                ->descriptionIcon($change <= 0 ? 'heroicon-m-arrow-trending-down' : 'heroicon-m-arrow-trending-up')
                ->color($change <= 0 ? 'success' : 'danger');
        }

        return $stat;
    }

    private function uncostedNote(int $uncostedThisWeek, int $uncostedLastWeek): string
    {
        if ($uncostedThisWeek > 0 && $uncostedLastWeek > 0) {
            return 'excludes '.number_format($uncostedThisWeek).' this week and '
                .number_format($uncostedLastWeek).' last week with no reported cost';
        }

        if ($uncostedThisWeek > 0) {
            return 'excludes '.number_format($uncostedThisWeek).' with no reported cost';
        }

        return 'no comparison — last week excludes '
            .number_format($uncostedLastWeek).' with no reported cost';
    }
}
