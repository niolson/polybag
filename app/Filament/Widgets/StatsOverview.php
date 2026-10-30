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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class StatsOverview extends BaseWidget
{
    protected static ?int $sort = -4;

    protected ?string $pollingInterval = '60s';

    protected int|array|null $columns = 3;

    /**
     * Packages counted but never priced.
     */
    private const UNCOSTED = 'SUM(package_count) - SUM(costed_package_count) as uncosted';

    protected function getStats(): array
    {
        if (auth()->user()?->role === Role::Packer) {
            $pending = Cache::remember(
                'widget:stats_overview:packer:v1',
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

        $data = Cache::remember('widget:stats_overview:v5', 60, function (): array {
            $tz = Location::timezone();
            $localToday = now($tz)->startOfDay();
            $thisWeekStart = now($tz)->startOfWeek();
            $lastWeekStart = now($tz)->subWeek()->startOfWeek();
            // The same days of last week: Monday through today's weekday, so an
            // early-week total is not set against a whole week.
            $lastWeekEnd = now($tz)->subWeek()->endOfDay();

            return [
                'pending' => Shipment::query()->where('status', ShipmentStatus::Open)->count(),
                'shipped_today' => (int) DailyShippingStat::where('date', $localToday->toDateString())->sum('package_count'),
                'daily_counts' => $this->dailyCounts($localToday, 60),
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

        $last7 = array_slice($data['daily_counts'], -7);
        $previous7 = array_slice($data['daily_counts'], -14, 7);
        $last30 = array_slice($data['daily_counts'], -30);
        $previous30 = array_slice($data['daily_counts'], 0, 30);

        return [
            Stat::make('Shipped Today', number_format(end($last7)))
                ->description('Since midnight')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),
            $this->buildShippedStat('Shipped Last 7 Days', $last7, $previous7, 'previous 7 days'),
            $this->buildShippedStat('Shipped Last 30 Days', $last30, $previous30, 'previous 30 days'),
            Stat::make('Open Shipments', number_format($data['pending']))
                ->description('Awaiting shipment')
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning')
                ->url(ShipmentResource::getUrl('index').'?status_tab='.ShipmentStatus::Open->value),
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
            $stat = $stat->description(($change >= 0 ? '+' : '').number_format($change, 1).'% vs same days last week')
                ->descriptionIcon($change <= 0 ? 'heroicon-m-arrow-trending-down' : 'heroicon-m-arrow-trending-up')
                ->color($change <= 0 ? 'success' : 'danger');
        }

        return $stat;
    }

    /**
     * A shipped-package total with its daily sparkline and how it moved against
     * the period before. With nothing shipped before, there is no percentage
     * to give, so the card stays neutral.
     *
     * @param  list<float>  $days
     * @param  list<float>  $previousDays
     */
    private function buildShippedStat(string $label, array $days, array $previousDays, string $previousLabel): Stat
    {
        $total = array_sum($days);
        $previous = array_sum($previousDays);

        $stat = Stat::make($label, number_format($total))->chart($days)->color('primary');

        if ($previous <= 0) {
            return $stat->description('Nothing shipped in the '.$previousLabel);
        }

        $change = (($total - $previous) / $previous) * 100;

        return $stat
            ->description(number_format(abs($change), 1).'% '.($change >= 0 ? 'increase' : 'decrease').' on the '.$previousLabel)
            ->descriptionIcon($change >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
            ->color($change >= 0 ? 'success' : 'danger');
    }

    /**
     * Packages shipped on each of the last $days local days, oldest first,
     * today last, with days that shipped nothing as zero.
     *
     * @return list<float> floats because that is what a Stat chart takes
     */
    private function dailyCounts(Carbon $localToday, int $days): array
    {
        $from = $localToday->copy()->subDays($days - 1);

        $counts = DailyShippingStat::query()
            ->where('date', '>=', $from->toDateString())
            ->selectRaw('date, SUM(package_count) as packages')
            ->groupBy('date')
            ->pluck('packages', 'date');

        return collect(range(0, $days - 1))
            ->map(fn (int $offset): float => (float) ($counts[$from->copy()->addDays($offset)->toDateString()] ?? 0))
            ->all();
    }

    private function uncostedNote(int $uncostedThisWeek, int $uncostedLastWeek): string
    {
        if ($uncostedThisWeek > 0 && $uncostedLastWeek > 0) {
            return 'excludes '.number_format($uncostedThisWeek).' this week and '
                .number_format($uncostedLastWeek).' in the same days last week with no reported cost';
        }

        if ($uncostedThisWeek > 0) {
            return 'excludes '.number_format($uncostedThisWeek).' with no reported cost';
        }

        return 'no comparison — the same days last week exclude '
            .number_format($uncostedLastWeek).' with no reported cost';
    }
}
