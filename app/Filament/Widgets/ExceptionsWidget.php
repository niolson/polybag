<?php

namespace App\Filament\Widgets;

use App\Enums\Deliverability;
use App\Enums\LabelBatchItemStatus;
use App\Enums\PackageStatus;
use App\Enums\ShipmentStatus;
use App\Enums\TrackingStatus;
use App\Filament\Pages\UnmappedShippingReferences;
use App\Filament\Resources\PackageResource;
use App\Filament\Resources\ShipmentResource;
use App\Models\LabelBatchItem;
use App\Models\Package;
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
        $counts = Cache::remember('widget:exceptions', 300, fn () => $this->queryCounts());

        return [
            Stat::make('Undeliverable Shipments', $counts['undeliverable'])
                ->description('Last 90 days, deliverability "No"')
                ->descriptionIcon('heroicon-m-x-circle')
                ->color($counts['undeliverable'] > 0 ? 'danger' : 'success')
                ->url(ShipmentResource::getUrl('index')),
            Stat::make('Failed Batch Items', $counts['failed_batch_items'])
                ->description('Last 7 days')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($counts['failed_batch_items'] > 0 ? 'danger' : 'success'),
            Stat::make('Tracking Exceptions', $counts['tracking_exceptions'])
                ->description('Shipped packages needing attention')
                ->descriptionIcon('heroicon-m-truck')
                ->color($counts['tracking_exceptions'] > 0 ? 'danger' : 'success')
                ->url(PackageResource::getUrl('index')),
            Stat::make('Stuck Pre-Transit', $counts['stuck_pre_transit'])
                ->description('Pre-transit for more than 48 hours')
                ->descriptionIcon('heroicon-m-clock')
                ->color($counts['stuck_pre_transit'] > 0 ? 'warning' : 'success')
                ->url(PackageResource::getUrl('index')),
            Stat::make('Unmapped Shipping References', $counts['unmapped_references'])
                ->description('Last 90 days, need mapping')
                ->descriptionIcon('heroicon-m-link')
                ->color($counts['unmapped_references'] > 0 ? 'warning' : 'success')
                ->url(UnmappedShippingReferences::getUrl()),
        ];
    }

    private function queryCounts(): array
    {
        return [
            'undeliverable' => Shipment::query()
                ->where('status', ShipmentStatus::Open)
                ->where('deliverability', Deliverability::No)
                ->where('created_at', '>=', now()->subDays(90))
                ->count(),
            'failed_batch_items' => LabelBatchItem::query()
                ->where('status', LabelBatchItemStatus::Failed)
                ->where('created_at', '>=', now()->subDays(7))
                ->count(),
            'unmapped_references' => Shipment::query()
                ->where('status', ShipmentStatus::Open)
                ->where('created_at', '>=', now()->subDays(90))
                ->whereNotNull('shipping_method_reference')
                ->whereNull('shipping_method_id')
                ->distinct('shipping_method_reference')
                ->count('shipping_method_reference'),
            'tracking_exceptions' => Package::query()
                ->where('status', PackageStatus::Shipped)
                ->where('tracking_status', TrackingStatus::Exception)
                ->count(),
            'stuck_pre_transit' => Package::query()
                ->where('status', PackageStatus::Shipped)
                ->where('tracking_status', TrackingStatus::PreTransit)
                ->where('shipped_at', '<=', now()->subHours(48))
                ->count(),
        ];
    }
}
