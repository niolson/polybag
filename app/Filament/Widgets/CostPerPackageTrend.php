<?php

namespace App\Filament\Widgets;

use App\Enums\Role;
use App\Models\DailyShippingStat;
use App\Models\Location;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Cache;

class CostPerPackageTrend extends ChartWidget
{
    protected ?string $heading = 'Avg Cost Per Package';

    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 1;

    protected ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        return auth()->user()?->role?->isAtLeast(Role::Manager) ?? false;
    }

    /**
     * Packages that reported a cost. A null `costed_package_count` predates the
     * column, so it falls back to counting every package, as those rows already
     * read. Unpriced packages belong in neither side of the average.
     */
    private const COSTED_COUNT = 'SUM(COALESCE(costed_package_count, package_count))';

    protected function getData(): array
    {
        return $this->cachedPayload()['chart'];
    }

    /**
     * The average plots priced packages only, so say so when any were left out.
     */
    public function getDescription(): ?string
    {
        $uncosted = $this->cachedPayload()['uncosted'];

        if ($uncosted < 1) {
            return 'Daily average over the last 30 days';
        }

        return 'Daily average over the last 30 days, excluding '
            .number_format($uncosted).' packages with no reported cost';
    }

    /**
     * @return array{chart: array<string, mixed>, uncosted: int}
     */
    private function cachedPayload(): array
    {
        return Cache::remember('widget:cost_trend:v2', 60, fn (): array => $this->buildData());
    }

    /**
     * @return array{chart: array<string, mixed>, uncosted: int}
     */
    private function buildData(): array
    {
        $startDate = now(Location::timezone())->subDays(29)->startOfDay();

        $dailyAvg = DailyShippingStat::query()
            ->where('date', '>=', $startDate->toDateString())
            ->where('package_count', '>', 0)
            ->selectRaw('date, CASE WHEN '.self::COSTED_COUNT.' > 0 THEN SUM(total_cost) / '.self::COSTED_COUNT.' ELSE NULL END as avg_cost')
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('avg_cost', 'date')
            ->toArray();

        $uncosted = (int) DailyShippingStat::query()
            ->where('date', '>=', $startDate->toDateString())
            ->selectRaw('SUM(package_count) - '.self::COSTED_COUNT.' as uncosted')
            ->value('uncosted');

        $labels = [];
        $data = [];

        for ($i = 0; $i < 30; $i++) {
            $date = $startDate->copy()->addDays($i);
            $dateKey = $date->format('Y-m-d');
            $labels[] = $date->format('M j');
            $data[] = isset($dailyAvg[$dateKey]) ? round((float) $dailyAvg[$dateKey], 2) : null;
        }

        $chart = [
            'datasets' => [
                [
                    'label' => 'Avg Cost',
                    'data' => $data,
                    'borderColor' => 'rgb(16, 185, 129)',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.08)',
                    'fill' => true,
                    'tension' => 0.4,
                    'pointBackgroundColor' => 'rgb(16, 185, 129)',
                    'pointRadius' => 3,
                    'pointHoverRadius' => 5,
                    'spanGaps' => true,
                ],
            ],
            'labels' => $labels,
        ];

        return ['chart' => $chart, 'uncosted' => $uncosted];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
