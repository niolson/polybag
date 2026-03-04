<?php

namespace App\Filament\Widgets;

use App\Enums\Deliverability;
use App\Enums\LabelBatchItemStatus;
use App\Filament\Pages\UnmappedShippingReferences;
use App\Filament\Resources\ShipmentResource;
use App\Models\LabelBatchItem;
use App\Models\Shipment;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

class ExceptionsWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '300s';

    protected function getStats(): array
    {
        return Cache::remember('widget:exceptions', 300, fn () => $this->buildStats());
    }

    private function buildStats(): array
    {
        $undeliverable = Shipment::query()
            ->where('shipped', false)
            ->where('deliverability', Deliverability::No)
            ->where('created_at', '>=', now()->subDays(90))
            ->count();

        $failedBatchItems = LabelBatchItem::query()
            ->where('status', LabelBatchItemStatus::Failed)
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        $unmappedReferences = Shipment::query()
            ->where('shipped', false)
            ->where('created_at', '>=', now()->subDays(90))
            ->whereNotNull('shipping_method_reference')
            ->whereNull('shipping_method_id')
            ->distinct('shipping_method_reference')
            ->count('shipping_method_reference');

        return [
            Stat::make('Undeliverable Shipments', $undeliverable)
                ->description('Last 90 days, deliverability "No"')
                ->descriptionIcon('heroicon-m-x-circle')
                ->color($undeliverable > 0 ? 'danger' : 'success')
                ->url(ShipmentResource::getUrl('index')),
            Stat::make('Failed Batch Items', $failedBatchItems)
                ->description('Last 7 days')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($failedBatchItems > 0 ? 'danger' : 'success'),
            Stat::make('Unmapped Shipping References', $unmappedReferences)
                ->description('Last 90 days, need mapping')
                ->descriptionIcon('heroicon-m-link')
                ->color($unmappedReferences > 0 ? 'warning' : 'success')
                ->url(UnmappedShippingReferences::getUrl()),
        ];
    }
}
