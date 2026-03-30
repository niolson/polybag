<?php

namespace App\Filament\Widgets;

use App\Models\DailyShippingStat;
use App\Models\Location;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Cache;

class CostPerPackageTrend extends ChartWidget
{
    protected ?string $heading = 'Avg Cost Per Package';

    protected ?string $description = 'Daily average over the last 30 days';

    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 1;

    protected ?string $pollingInterval = '60s';

    protected function getData(): array
    {
        return Cache::remember('widget:cost_trend', 60, fn () => $this->buildData());
    }

    private function buildData(): array
    {
        $startDate = now(Location::timezone())->subDays(29)->startOfDay();

        $dailyAvg = DailyShippingStat::query()
            ->where('date', '>=', $startDate->toDateString())
            ->where('package_count', '>', 0)
            ->selectRaw('date, SUM(total_cost) / SUM(package_count) as avg_cost')
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('avg_cost', 'date')
            ->toArray();

        $labels = [];
        $data = [];

        for ($i = 0; $i < 30; $i++) {
            $date = $startDate->copy()->addDays($i);
            $dateKey = $date->format('Y-m-d');
            $labels[] = $date->format('M j');
            $data[] = isset($dailyAvg[$dateKey]) ? round((float) $dailyAvg[$dateKey], 2) : null;
        }

        return [
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
    }

    protected function getType(): string
    {
        return 'line';
    }
}
