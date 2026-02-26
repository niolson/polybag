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

class ExceptionsWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $undeliverable = Shipment::query()
            ->where('shipped', false)
            ->where('deliverability', Deliverability::No)
            ->count();

        $failedBatchItems = LabelBatchItem::query()
            ->where('status', LabelBatchItemStatus::Failed)
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        $unmappedReferences = Shipment::query()
            ->where('shipped', false)
            ->whereNotNull('shipping_method_reference')
            ->whereNull('shipping_method_id')
            ->distinct('shipping_method_reference')
            ->count('shipping_method_reference');

        return [
            Stat::make('Undeliverable Shipments', $undeliverable)
                ->description('Pending with deliverability "No"')
                ->color($undeliverable > 0 ? 'danger' : 'success')
                ->url(ShipmentResource::getUrl('index')),
            Stat::make('Failed Batch Items', $failedBatchItems)
                ->description('Last 7 days')
                ->color($failedBatchItems > 0 ? 'danger' : 'success'),
            Stat::make('Unmapped Shipping References', $unmappedReferences)
                ->description('Need mapping to shipping methods')
                ->color($unmappedReferences > 0 ? 'warning' : 'success')
                ->url(UnmappedShippingReferences::getUrl()),
        ];
    }
}
