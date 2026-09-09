<?php

namespace App\Services\ShipmentImport;

use App\DataTransferObjects\ShipmentImport\ShopifyFulfillmentOrderIdentity;
use App\Enums\AuditAction;
use App\Enums\ShipmentStatus;
use App\Models\AuditLog;
use App\Models\DataSource;
use App\Models\Shipment;
use App\Services\ShopifyGoodsFingerprint;
use Illuminate\Support\Collection;

/**
 * Moves a shipment onto the fulfillment order that replaced the one it was
 * imported against.
 *
 * Voiding a Shopify Shipping label does not reopen the fulfillment order it
 * was bought against: Shopify closes that one permanently and creates a fresh
 * one for the same line items at the same location. The replacement carries a
 * GID the import has never seen, so without this the next run imports it as
 * new work and the order ends up with two shipments — one of which can never
 * buy a label, because the fulfillment order it names is the closed one.
 *
 * Shopify publishes no supersession link, so a replacement is recognised by
 * inference: an open shipment for the same order at the same location, holding
 * the same goods, whose own fulfillment order this run is no longer offered.
 * That last clause is what separates a replacement from a sibling — one order
 * legitimately has several fulfillment orders, split across locations or by
 * `fulfillmentOrderSplit`, and a sibling is still on offer alongside the new
 * arrival.
 *
 * Every ambiguity resolves toward doing nothing. A record imported twice is
 * visible in the packing queue and can be voided; a shipment re-pointed at
 * another parcel's work merges two lots of goods with nothing to show for it.
 */
class ShopifyFulfillmentOrderRepointer
{
    public function __construct(
        private readonly ShopifyGoodsFingerprint $goods,
    ) {}

    /**
     * @param  Collection<int, ShopifyFulfillmentOrderIdentity>  $identities  every fulfillment order this run fetched
     * @return int how many shipments were re-pointed
     */
    public function repoint(DataSource $dataSource, Collection $identities): int
    {
        $offeredIds = $identities
            ->map(fn (ShopifyFulfillmentOrderIdentity $identity): string => $identity->fulfillmentOrderId)
            ->all();

        if ($offeredIds === []) {
            return 0;
        }

        $unrecognised = $this->unrecognised($dataSource, $identities, $offeredIds);

        if ($unrecognised->isEmpty()) {
            return 0;
        }

        $candidates = $this->candidates(
            $dataSource,
            $unrecognised->map(fn (ShopifyFulfillmentOrderIdentity $identity): string => (string) $identity->orderId)
                ->unique()
                ->values()
                ->all(),
            $offeredIds,
        );

        if ($candidates->isEmpty()) {
            return 0;
        }

        return $this->applyPairs($this->pair($unrecognised, $candidates));
    }

    /**
     * The fetched fulfillment orders that no shipment of this source names,
     * and that carry enough identity to be matched at all.
     *
     * @param  Collection<int, ShopifyFulfillmentOrderIdentity>  $identities
     * @param  array<int, string>  $offeredIds
     * @return Collection<int, ShopifyFulfillmentOrderIdentity>
     */
    private function unrecognised(DataSource $dataSource, Collection $identities, array $offeredIds): Collection
    {
        $known = Shipment::query()
            ->where('data_source_id', $dataSource->id)
            ->whereIn('source_record_id', $offeredIds)
            ->pluck('source_record_id')
            ->all();

        return $identities
            ->reject(fn (ShopifyFulfillmentOrderIdentity $identity): bool => in_array(
                $identity->fulfillmentOrderId,
                $known,
                true,
            ))
            ->filter(fn (ShopifyFulfillmentOrderIdentity $identity): bool => filled($identity->orderId)
                && filled($identity->locationId))
            ->values();
    }

    /**
     * Shipments a replacement could belong to.
     *
     * Open only, and deliberately: a shipped shipment's package is out of the
     * door against the fulfillment order it names, and re-pointing it would
     * move that ground under a live label. `ShopifyFulfillmentSynchronizer`
     * un-ships it when it reads the void, and the run after that finds it here.
     *
     * @param  array<int, string>  $orderIds
     * @param  array<int, string>  $offeredIds
     * @return Collection<int, Shipment>
     */
    private function candidates(DataSource $dataSource, array $orderIds, array $offeredIds): Collection
    {
        if ($orderIds === []) {
            return collect();
        }

        return Shipment::query()
            ->where('data_source_id', $dataSource->id)
            ->where('status', ShipmentStatus::Open)
            ->whereIn('metadata->shopify_order_id', $orderIds)
            ->whereNotIn('source_record_id', $offeredIds)
            ->with('shipmentItems.product')
            ->get();
    }

    /**
     * Match each replacement to the one shipment it can only be, dropping any
     * pairing that is not unique from both ends.
     *
     * @param  Collection<int, ShopifyFulfillmentOrderIdentity>  $unrecognised
     * @param  Collection<int, Shipment>  $candidates
     * @return array<int, array{0: ShopifyFulfillmentOrderIdentity, 1: Shipment}>
     */
    private function pair(Collection $unrecognised, Collection $candidates): array
    {
        $pairs = [];

        foreach ($unrecognised as $identity) {
            $matches = $candidates->filter(
                fn (Shipment $shipment): bool => $this->isReplacementFor($shipment, $identity),
            );

            if ($matches->count() === 1) {
                $pairs[] = [$identity, $matches->first()];
            }
        }

        $claims = collect($pairs)->countBy(fn (array $pair): int => $pair[1]->id);

        return array_values(array_filter(
            $pairs,
            fn (array $pair): bool => $claims[$pair[1]->id] === 1,
        ));
    }

    /**
     * @param  array<int, array{0: ShopifyFulfillmentOrderIdentity, 1: Shipment}>  $pairs
     */
    private function applyPairs(array $pairs): int
    {
        foreach ($pairs as [$identity, $shipment]) {
            $this->apply($shipment, $identity);
        }

        return count($pairs);
    }

    private function isReplacementFor(Shipment $shipment, ShopifyFulfillmentOrderIdentity $identity): bool
    {
        $metadata = $shipment->metadata ?? [];

        return ($metadata['shopify_order_id'] ?? null) === $identity->orderId
            && ($metadata['shopify_location_id'] ?? null) === $identity->locationId
            && $this->sameGoods($shipment, $identity);
    }

    /**
     * Whether the shipment holds what the replacement is for.
     *
     * The test that makes a split order safe: two fulfillment orders for one
     * order at one location differ only in their line items, so without this a
     * sibling that closed for its own reasons would be read as a replacement.
     *
     * Missing evidence is not a match. Two unreadable sets of goods are not the
     * same goods, and treating them as such is how a merge happens silently —
     * so a shipment with no fingerprint and no items stays unpaired, and the
     * replacement imports as its own shipment where a person can see it.
     */
    private function sameGoods(Shipment $shipment, ShopifyFulfillmentOrderIdentity $identity): bool
    {
        $stored = $this->goods->forShipment($shipment);

        return $stored !== null && $stored === $identity->goodsFingerprint;
    }

    private function apply(Shipment $shipment, ShopifyFulfillmentOrderIdentity $identity): void
    {
        $superseded = $shipment->source_record_id;

        $shipment->source_record_id = $identity->fulfillmentOrderId;
        // Written here as well as by the batch write that follows, because a
        // source set to skip existing shipments never reaches that write and
        // would be left keyed on the replacement while still naming the dead
        // fulfillment order in its metadata.
        $shipment->metadata = array_merge($shipment->metadata ?? [], [
            'shopify_fulfillment_order_id' => $identity->fulfillmentOrderId,
        ]);
        $shipment->save();

        AuditLog::record(
            action: AuditAction::ShipmentUpdated,
            auditable: $shipment,
            metadata: [
                'reason' => 'Shopify replaced the fulfillment order; the shipment was re-pointed at the replacement',
                'superseded_fulfillment_order_id' => $superseded,
                'fulfillment_order_id' => $identity->fulfillmentOrderId,
            ],
        );

        logger()->info('Re-pointed a shipment at its replacement Shopify fulfillment order', [
            'shipment_id' => $shipment->id,
            'superseded_fulfillment_order_id' => $superseded,
            'fulfillment_order_id' => $identity->fulfillmentOrderId,
        ]);
    }
}
