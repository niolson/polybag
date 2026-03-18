<?php

namespace App\Filament\Widgets;

use App\Models\DailyShippingStat;
use App\Models\Location;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Cache;

class ShippedShipmentsChart extends ChartWidget
{
    protected ?string $heading = 'Shipped Shipments';

    protected ?string $description = 'Total packages shipped per day';

    protected static ?int $sort = -3;

    protected int|string|array $columnSpan = 1;

    public ?string $filter = 'week';

    protected ?string $pollingInterval = '60s';

    protected function getFilters(): ?array
    {
        return [
            'week' => 'Last 7 days',
            'month' => 'Last 30 days',
        ];
    }

    protected function getData(): array
    {
        return Cache::remember("widget:shipped_chart:{$this->filter}", 60, function () {
            $days = $this->filter === 'month' ? 30 : 7;
            $startDate = now(Location::timezone())->subDays($days - 1)->startOfDay();

            $shipments = DailyShippingStat::query()
                ->where('date', '>=', $startDate->toDateString())
                ->selectRaw('date, SUM(package_count) as count')
                ->groupBy('date')
                ->orderBy('date')
                ->pluck('count', 'date')
                ->toArray();

            $labels = [];
            $data = [];

            for ($i = 0; $i < $days; $i++) {
                $date = $startDate->copy()->addDays($i);
                $dateKey = $date->format('Y-m-d');
                $labels[] = $date->format('M j');
                $data[] = $shipments[$dateKey] ?? 0;
            }

            return [
                'datasets' => [
                    [
                        'label' => 'Shipments',
                        'data' => $data,
                        'backgroundColor' => 'rgba(79, 106, 245, 0.65)',
                        'borderColor' => 'rgb(79, 106, 245)',
                        'borderWidth' => 0,
                        'borderRadius' => 6,
                    ],
                ],
                'labels' => $labels,
            ];
        });
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
