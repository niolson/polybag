<?php

namespace App\Filament\Widgets;

use App\Enums\Role;
use App\Models\DailyShippingStat;
use App\Models\Location;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Cache;

class CostPerPackageTrend extends ChartWidget
{
    protected ?string $heading = 'Avg Cost Per Package';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 1;

    protected ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        return auth()->user()?->role?->isAtLeast(Role::Manager) ?? false;
    }

    /**
     * A day with fewer packages than this is left off the line: one parcel's
     * price is not an average, and it drags the line away from the real range.
     */
    private const MIN_PACKAGES_PER_DAY = 5;

    /**
     * Packages that reported a cost. Unpriced packages belong in neither side
     * of the average.
     */
    private const COSTED_COUNT = 'SUM(costed_package_count)';

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
            return 'Daily average over the last 30 days, leaving out today and days under '.self::MIN_PACKAGES_PER_DAY.' packages';
        }

        return 'Daily average over the last 30 days, leaving out today and days under '.self::MIN_PACKAGES_PER_DAY.' packages; excluding '
            .number_format($uncosted).' packages with no reported cost';
    }

    /**
     * @return array{chart: array<string, mixed>, uncosted: int}
     */
    private function cachedPayload(): array
    {
        return Cache::remember('widget:cost_trend:v3', 60, fn (): array => $this->buildData());
    }

    /**
     * @return array{chart: array<string, mixed>, uncosted: int}
     */
    private function buildData(): array
    {
        $startDate = now(Location::timezone())->subDays(29)->startOfDay();

        $dailyAvg = DailyShippingStat::query()
            ->where('date', '>=', $startDate->toDateString())
            // Today is still filling up.
            ->where('date', '<', now(Location::timezone())->toDateString())
            ->where('package_count', '>=', self::MIN_PACKAGES_PER_DAY)
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

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
        {
            plugins: { legend: { display: false } },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { callback: (value) => '$' + Number(value).toFixed(2) },
                },
            },
        }
        JS);
    }

    protected function getType(): string
    {
        return 'line';
    }
}
