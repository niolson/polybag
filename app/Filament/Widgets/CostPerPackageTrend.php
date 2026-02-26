<?php

namespace App\Filament\Widgets;

use App\Models\Package;
use Filament\Widgets\ChartWidget;

class CostPerPackageTrend extends ChartWidget
{
    protected ?string $heading = 'Avg Cost Per Package (30 Days)';

    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 1;

    protected function getData(): array
    {
        $startDate = now()->subDays(29)->startOfDay();

        $dailyAvg = Package::query()
            ->where('shipped', true)
            ->where('shipped_at', '>=', $startDate)
            ->whereNotNull('cost')
            ->selectRaw('DATE(shipped_at) as date, AVG(cost) as avg_cost')
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
