<?php

namespace App\Services\PackSlips;

use App\DataTransferObjects\PackSlips\PackSlipQueueFilters;
use App\DataTransferObjects\PackSlips\PackSlipRun;
use App\Enums\PackSlipQueueTab;
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
 * "Print selected", Reprint and View all go through here, so they cannot disagree.
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
        return $this->tab(PackSlipQueueTab::NotPrinted, $filters);
    }

    /**
     * Open Shipments whose latest slip is current, outside any in-progress pick
     * batch, most recently printed first, so a jammed run is at the top to reprint.
     *
     * @return Builder<Shipment>
     */
    public function printed(PackSlipQueueFilters $filters = new PackSlipQueueFilters): Builder
    {
        return $this->tab(PackSlipQueueTab::Printed, $filters);
    }

    /**
     * The Shipments a tab of the Print Pack Slips page lists, in its order.
     *
     * @return Builder<Shipment>
     */
    public function tab(PackSlipQueueTab $tab, PackSlipQueueFilters $filters = new PackSlipQueueFilters): Builder
    {
        $query = $this->inStates($tab, $filters)
            ->whereDoesntHave('pickBatchShipments', $this->inActiveBatch(...));

        if ($this->printsFromPickBatches()) {
            $query->whereRaw('1 = 0');
        }

        return match ($tab) {
            PackSlipQueueTab::NotPrinted => $this->inUrgencyOrder($query),
            PackSlipQueueTab::Printed => $this->inPrintedOrder($query),
        };
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
     * Shipments that would be on the tab but are in an in-progress pick batch, so
     * their slips belong to the batch (with its tote codes), and those batches.
     *
     * @return array{count: int, batches: EloquentCollection<int, PickBatch>}
     */
    public function leftOffForPickBatches(
        PackSlipQueueFilters $filters = new PackSlipQueueFilters,
        PackSlipQueueTab $tab = PackSlipQueueTab::NotPrinted,
    ): array {
        if ($this->printsFromPickBatches()) {
            return ['count' => 0, 'batches' => new EloquentCollection];
        }

        $shipmentIds = $this->inStates($tab, $filters)
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
     * Open Shipments whose slip is in one of the tab's states, filtered.
     *
     * @return Builder<Shipment>
     */
    private function inStates(PackSlipQueueTab $tab, PackSlipQueueFilters $filters): Builder
    {
        return Shipment::query()
            ->where('shipments.status', ShipmentStatus::Open)
            ->withPackSlipState(...$tab->states())
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

    /**
     * Most recently printed first. One QZ job's Shipments share a printed time, so
     * the receipt's issue time keeps a job together, and the ID gives a stable
     * order within it.
     *
     * @param  Builder<Shipment>  $query
     * @return Builder<Shipment>
     */
    private function inPrintedOrder(Builder $query): Builder
    {
        return $query
            ->orderByDesc('shipments.pack_slip_printed_at')
            ->orderByDesc('shipments.pack_slip_receipt_issued_at')
            ->orderBy('shipments.id');
    }
}
