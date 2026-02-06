<?php

namespace App\Filament\Widgets;

use App\Models\Package;
use App\Models\Shipment;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends BaseWidget
{
    protected static ?int $sort = -4;

    protected function getStats(): array
    {
        return [
            Stat::make('Pending Shipments', Shipment::query()->where('shipped', false)->count())
                ->description('Awaiting shipment')
                ->color('warning'),
            Stat::make('Shipped Today', Package::query()->where('shipped', true)->whereDate('shipped_at', today())->count())
                ->description('Packages shipped today')
                ->color('success'),
        ];
    }
}
