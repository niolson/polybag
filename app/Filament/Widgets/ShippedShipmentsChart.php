<?php

namespace App\Filament\Widgets;

use App\Models\Shipment;
use Filament\Widgets\ChartWidget;

class ShippedShipmentsChart extends ChartWidget
{
    protected ?string $heading = 'Shipped Shipments';

    protected static ?int $sort = -3;

    protected int|string|array $columnSpan = 1;

    public ?string $filter = 'week';

    protected function getFilters(): ?array
    {
        return [
            'week' => 'Last 7 days',
            'month' => 'Last 30 days',
        ];
    }

    protected function getData(): array
    {
        $days = $this->filter === 'month' ? 30 : 7;
        $startDate = now()->subDays($days - 1)->startOfDay();

        $shipments = Shipment::query()
            ->where('shipped', true)
            ->where('updated_at', '>=', $startDate)
            ->selectRaw('DATE(updated_at) as date, COUNT(*) as count')
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
                    'backgroundColor' => 'rgba(251, 191, 36, 0.5)',
                    'borderColor' => 'rgb(251, 191, 36)',
                    'borderWidth' => 1,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
