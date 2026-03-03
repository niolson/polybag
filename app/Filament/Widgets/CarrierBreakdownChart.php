<?php

namespace App\Filament\Widgets;

use App\Models\DailyShippingStat;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Cache;

class CarrierBreakdownChart extends ChartWidget
{
    protected ?string $heading = 'Carrier Breakdown';

    protected static ?int $sort = -1;

    protected int|string|array $columnSpan = 1;

    public ?string $filter = 'week';

    protected ?string $pollingInterval = '60s';

    protected function getFilters(): ?array
    {
        return [
            'week' => 'This Week',
            'month' => 'This Month',
        ];
    }

    protected function getData(): array
    {
        return Cache::remember("widget:carrier_breakdown:{$this->filter}", 60, fn () => $this->buildData());
    }

    private function buildData(): array
    {
        $startDate = $this->filter === 'month'
            ? now()->startOfMonth()->toDateString()
            : now()->startOfWeek()->toDateString();

        $breakdown = DailyShippingStat::query()
            ->whereNotNull('carrier')
            ->where('date', '>=', $startDate)
            ->selectRaw('carrier, SUM(package_count) as count')
            ->groupBy('carrier')
            ->orderByDesc('count')
            ->pluck('count', 'carrier')
            ->toArray();

        $colors = [
            'rgba(251, 191, 36, 0.8)',  // amber
            'rgba(59, 130, 246, 0.8)',   // blue
            'rgba(34, 197, 94, 0.8)',    // green
            'rgba(168, 85, 247, 0.8)',   // purple
            'rgba(239, 68, 68, 0.8)',    // red
        ];

        return [
            'datasets' => [
                [
                    'data' => array_values($breakdown),
                    'backgroundColor' => array_slice($colors, 0, count($breakdown)),
                ],
            ],
            'labels' => array_keys($breakdown),
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
