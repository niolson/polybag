<?php

namespace App\Filament\Widgets;

use App\Models\DailyShippingStat;
use App\Models\Location;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Cache;

class CarrierBreakdownChart extends ChartWidget
{
    protected ?string $heading = 'Carrier Breakdown';

    protected ?string $description = 'Package distribution by carrier';

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
        $tz = Location::timezone();
        $startDate = $this->filter === 'month'
            ? now($tz)->startOfMonth()->toDateString()
            : now($tz)->startOfWeek()->toDateString();

        $breakdown = DailyShippingStat::query()
            ->whereNotNull('carrier')
            ->where('date', '>=', $startDate)
            ->selectRaw('carrier, SUM(package_count) as count')
            ->groupBy('carrier')
            ->orderByDesc('count')
            ->pluck('count', 'carrier')
            ->toArray();

        $colors = [
            'rgba(79, 106, 245, 0.85)',  // blue-purple
            'rgba(14, 165, 233, 0.85)',  // sky
            'rgba(16, 185, 129, 0.85)',  // emerald
            'rgba(168, 85, 247, 0.85)',  // purple
            'rgba(244, 63, 94, 0.85)',   // rose
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
