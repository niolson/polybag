<?php

namespace App\Services;

use App\DataTransferObjects\ShipmentImport\ShopifyFulfillmentOrderIdentity;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Package;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Builder;

/**
 * Moves a shipment off the fulfillment order a void closed.
 *
 * Two voids close one. Voiding a Shopify Shipping label in the Shopify admin
 * ({@see ShopifyFulfillmentSynchronizer}), and voiding a direct Label whose
 * export fulfilled the order, which cancels that fulfillment
 * ({@see ShopifyFulfillmentCanceller}). Either way the goods come back on a
 * fulfillment order Shopify chooses, and the shipment has to name it before it
 * can be shipped again.
 */
class ShopifyFulfillmentOrderReplacer
{
    public function __construct(
        private readonly ShopifyShippingLabelService $labelService,
        private readonly ShopifyGoodsFingerprint $goods,
    ) {}

    /**
     * Move the shipment onto the fulfillment order that replaced the one the
     * void closed.
     *
     * A void reopens nothing. Shopify closes the fulfillment order the label
     * was bought against permanently and creates a fresh one for the same line
     * items. A cancelled fulfillment may come back on the same one instead,
     * which this finds as well and leaves alone. A shipment left holding a
     * closed ID still satisfies
     * `ShopifyShippingLabelService::canPurchaseFor()`: the offer is shown to
     * the packer again and the purchase fails with `FULFILLMENT_ORDER_INVALID`
     * after the box is taped shut.
     *
     * `source_record_id` moves with it. It names the same dead fulfillment
     * order, and the next import would otherwise read the replacement as work
     * it has never seen and create a second shipment for the order.
     *
     * Exactly one candidate that is this shipment's own work is the
     * replacement and is taken. None means nothing on the order can be
     * identified as this shipment's any more, so the stored ID goes and the
     * offer withdraws itself rather than failing at the bench — the import
     * re-points the shipment if it later can. Several is no answer, and
     * guessing between them is worse than leaving the ID alone. Nor is a
     * failure to ask fatal: the void itself is already recorded, and
     * `ShopifyFulfillmentOrderRepointer` re-points on the next import run.
     */
    public function repoint(Package $package): void
    {
        $shipment = $package->shipment;

        if (! $shipment) {
            return;
        }

        try {
            $fulfillable = $this->labelService->fulfillableFulfillmentOrders($package);
        } catch (\Exception $e) {
            logger()->warning('Could not re-resolve the Shopify fulfillment order after a void', [
                'package_id' => $package->id,
                'shipment_id' => $shipment->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($fulfillable === null) {
            return;
        }

        $replacements = $this->replacementsFor($shipment, $fulfillable);

        if (count($replacements) > 1) {
            return;
        }

        $metadata = $shipment->metadata ?? [];
        $superseded = $metadata['shopify_fulfillment_order_id'] ?? null;

        if ($replacements === []) {
            if (! array_key_exists('shopify_fulfillment_order_id', $metadata)) {
                return;
            }

            unset($metadata['shopify_fulfillment_order_id']);
            $shipment->metadata = $metadata;
            $shipment->save();

            $this->recordFulfillmentOrderChange($shipment, $superseded, null);

            return;
        }

        $replacement = $replacements[0];

        if ($replacement === $superseded) {
            return;
        }

        $metadata['shopify_fulfillment_order_id'] = $replacement;
        $shipment->metadata = $metadata;

        if ($shipment->source_record_id === $superseded) {
            $shipment->source_record_id = $replacement;
        }

        $shipment->save();

        $this->recordFulfillmentOrderChange($shipment, $superseded, $replacement);
    }

    /**
     * Which of the order's fulfillable fulfillment orders could be this
     * shipment's work.
     *
     * Fulfillable and assigned to the shipment's location is not enough. An
     * order split at a single location has siblings that pass both tests, are
     * for different goods, and may belong to another shipment entirely — and a
     * label bought against one of those ships the wrong parcel, with nothing to
     * show that it did. So the goods have to agree, and a fulfillment order
     * another shipment already names is that shipment's, whatever it is for.
     *
     * A shipment whose goods cannot be read matches nothing, and its stored ID
     * is cleared rather than moved onto a guess. The import knows more than
     * this does and re-points it there.
     *
     * @param  list<ShopifyFulfillmentOrderIdentity>  $fulfillable
     * @return list<string>
     */
    private function replacementsFor(Shipment $shipment, array $fulfillable): array
    {
        $goods = $this->goods->forShipment($shipment);

        if ($goods === null) {
            return [];
        }

        $ours = array_values(array_map(
            fn (ShopifyFulfillmentOrderIdentity $candidate): string => $candidate->fulfillmentOrderId,
            array_filter(
                $fulfillable,
                fn (ShopifyFulfillmentOrderIdentity $candidate): bool => $candidate->goodsFingerprint === $goods,
            ),
        ));

        return array_values(array_diff($ours, $this->claimedElsewhere($shipment, $ours)));
    }

    /**
     * The fulfillment orders among these that another shipment of the same data
     * source already names — as its import key or in its metadata, since either
     * is enough to make a purchase target it.
     *
     * @param  list<string>  $fulfillmentOrderIds
     * @return list<string>
     */
    private function claimedElsewhere(Shipment $shipment, array $fulfillmentOrderIds): array
    {
        if ($fulfillmentOrderIds === []) {
            return [];
        }

        return Shipment::query()
            ->where('data_source_id', $shipment->data_source_id)
            ->whereKeyNot($shipment->getKey())
            ->where(fn (Builder $query): Builder => $query
                ->whereIn('source_record_id', $fulfillmentOrderIds)
                ->orWhereIn('metadata->shopify_fulfillment_order_id', $fulfillmentOrderIds))
            ->get(['id', 'source_record_id', 'metadata'])
            ->flatMap(fn (Shipment $other): array => array_filter([
                $other->source_record_id,
                $other->metadata['shopify_fulfillment_order_id'] ?? null,
            ]))
            ->unique()
            ->values()
            ->all();
    }

    private function recordFulfillmentOrderChange(Shipment $shipment, ?string $superseded, ?string $replacement): void
    {
        AuditLog::record(
            action: AuditAction::ShipmentUpdated,
            auditable: $shipment,
            metadata: [
                'reason' => $replacement === null
                    ? 'Shopify closed the fulfillment order on the void and offered no replacement'
                    : 'Shopify replaced the fulfillment order on the void; the shipment was re-pointed at the replacement',
                'superseded_fulfillment_order_id' => $superseded,
                'fulfillment_order_id' => $replacement,
            ],
        );

        logger()->info('Shopify fulfillment order re-resolved after a void', [
            'shipment_id' => $shipment->id,
            'superseded_fulfillment_order_id' => $superseded,
            'fulfillment_order_id' => $replacement,
        ]);
    }
}
