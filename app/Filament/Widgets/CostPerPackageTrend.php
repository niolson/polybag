<?php

namespace App\Filament\Widgets;

use App\Models\DailyShippingStat;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Cache;

class CostPerPackageTrend extends ChartWidget
{
    protected ?string $heading = 'Avg Cost Per Package (30 Days)';

    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 1;

    protected ?string $pollingInterval = '60s';

    protected function getData(): array
    {
        return Cache::remember('widget:cost_trend', 60, fn () => $this->buildData());
    }

    private function buildData(): array
    {
        $startDate = now()->subDays(29)->startOfDay();

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
                    'borderColor' => 'rgb(251, 191, 36)',
                    'backgroundColor' => 'rgba(251, 191, 36, 0.1)',
                    'fill' => true,
                    'tension' => 0.3,
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
