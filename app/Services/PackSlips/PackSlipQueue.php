<?php

namespace App\Services\PackSlips;

use App\DataTransferObjects\PackSlips\PackSlipQueueFilters;
use App\DataTransferObjects\PackSlips\PackSlipRun;
use App\Enums\PickBatchStatus;
use App\Enums\ShipmentStatus;
use App\Models\PickBatch;
use App\Models\PickBatchShipment;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Services\SettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Which open Shipments still need a pack slip, and in what order.
 *
 * Selection is by urgency alone: expedited first, then oldest. Client plays no part,
 * so one Client's backlog never holds back another's expedited orders. Once chosen, a
 * run is grouped by Client for the paper. The Print Pack Slips page, "Print next N",
 * "Print selected" and View all go through here, so they cannot disagree.
 */
class PackSlipQueue
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Whether pack slips print from pick batches instead, because picking is
     * required before shipping. The queue then lists nothing.
     */
    public function printsFromPickBatches(): bool
    {
        return (bool) $this->settings->get('picking_enabled', false)
            && (bool) $this->settings->get('require_picking_before_shipping', false);
    }

    /**
     * Open Shipments never printed or whose slip is out of date, outside any
     * in-progress pick batch, most urgent first.
     *
     * @return Builder<Shipment>
     */
    public function notPrinted(PackSlipQueueFilters $filters = new PackSlipQueueFilters): Builder
    {
        $query = $this->needingSlip($filters)
            ->whereDoesntHave('pickBatchShipments', $this->inActiveBatch(...));

        if ($this->printsFromPickBatches()) {
            $query->whereRaw('1 = 0');
        }

        return $this->inUrgencyOrder($query);
    }

    /**
     * The IDs of the top N Shipments waiting, in urgency order.
     *
     * @return list<int>
     */
    public function next(int $count, PackSlipQueueFilters $filters = new PackSlipQueueFilters): array
    {
        return $this->notPrinted($filters)
            ->limit(max(0, $count))
            ->pluck('shipments.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Shipments that would be waiting but are in an in-progress pick batch, so
     * their slips belong to the batch (with its tote codes), and those batches.
     *
     * @return array{count: int, batches: EloquentCollection<int, PickBatch>}
     */
    public function leftOffForPickBatches(PackSlipQueueFilters $filters = new PackSlipQueueFilters): array
    {
        if ($this->printsFromPickBatches()) {
            return ['count' => 0, 'batches' => new EloquentCollection];
        }

        $shipmentIds = $this->needingSlip($filters)
            ->whereHas('pickBatchShipments', $this->inActiveBatch(...))
            ->pluck('shipments.id');

        $batches = PickBatch::query()
            ->where('status', PickBatchStatus::InProgress)
            ->whereHas('pickBatchShipments', fn (Builder $query) => $query->whereIn('shipment_id', $shipmentIds))
            ->orderBy('id')
            ->get();

        return ['count' => $shipmentIds->count(), 'batches' => $batches];
    }

    /**
     * The given Shipments as a run for the paper: grouped by Client in name order,
     * urgency order kept within each Client. Shipments no longer open are dropped.
     *
     * @param  array<int, int>  $shipmentIds
     */
    public function run(array $shipmentIds): PackSlipRun
    {
        $shipments = $this->inUrgencyOrder(
            Shipment::query()
                ->whereKey($shipmentIds)
                ->where('status', ShipmentStatus::Open)
        )
            ->with('client:id,name')
            ->get(['shipments.id', 'shipments.client_id']);

        $ids = $shipments
            ->groupBy(fn (Shipment $shipment): int => (int) $shipment->client_id)
            ->sortBy(fn (Collection $group): string => (string) $group->first()->client?->name, SORT_NATURAL | SORT_FLAG_CASE)
            ->flatten(1)
            ->map(fn (Shipment $shipment): int => $shipment->id)
            ->values()
            ->all();

        return new PackSlipRun($ids);
    }

    /**
     * Open Shipments whose slip was never printed or is out of date.
     *
     * @return Builder<Shipment>
     */
    private function needingSlip(PackSlipQueueFilters $filters): Builder
    {
        return Shipment::query()
            ->where('shipments.status', ShipmentStatus::Open)
            ->where(fn (Builder $query) => $query
                ->whereNull('shipments.pack_slip_items_version')
                ->orWhereColumn('shipments.items_version', '>', 'shipments.pack_slip_items_version'))
            ->when($filters->clientId, fn (Builder $query, int $id) => $query->where('shipments.client_id', $id))
            ->when($filters->channelId, fn (Builder $query, int $id) => $query->where('shipments.channel_id', $id))
            ->when($filters->shippingMethodId, fn (Builder $query, int $id) => $query->where('shipments.shipping_method_id', $id));
    }

    /**
     * @param  Builder<PickBatchShipment>  $query
     */
    private function inActiveBatch(Builder $query): void
    {
        $query->whereHas('pickBatch', fn (Builder $batch) => $batch->where('status', PickBatchStatus::InProgress));
    }

    /**
     * Expedited first (a Shipment with no shipping method counts as standard),
     * then oldest first.
     *
     * @param  Builder<Shipment>  $query
     * @return Builder<Shipment>
     */
    private function inUrgencyOrder(Builder $query): Builder
    {
        return $query
            ->orderByDesc(ShippingMethod::query()
                ->selectRaw('COALESCE(MAX(is_expedited), 0)')
                ->whereColumn('shipping_methods.id', 'shipments.shipping_method_id'))
            ->orderBy('shipments.created_at')
            ->orderBy('shipments.id');
    }
}
