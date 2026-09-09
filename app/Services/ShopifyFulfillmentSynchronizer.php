<?php

namespace App\Services;

use App\DataTransferObjects\ShipmentImport\ShopifyFulfillmentOrderIdentity;
use App\Enums\AuditAction;
use App\Enums\PackageStatus;
use App\Enums\PostageSource;
use App\Enums\TrackingStatus;
use App\Models\AuditLog;
use App\Models\Package;
use App\Models\Shipment;
use App\Services\PostageSources\ShopifyPostageSource;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Keeps PolyBag's copy of a Shopify-bought shipment in step with Shopify's.
 *
 * Two things can change on Shopify's side and only Shopify knows about either.
 * A label can be voided — only possible in the Shopify admin, since the Admin
 * API has no mutation for it — which would otherwise leave PolyBag holding a
 * shipped package with a dead tracking number and a label that will never scan.
 * And the parcel can move, which is the only tracking available for postage
 * bought on Shopify's account: USPS entitles us to nothing for a barcode
 * carrying Shopify's MID (ADR-0002).
 *
 * Both answers live on the same fulfillment, so both come out of one poll. It
 * runs every fifteen minutes, which also keeps `tracking_checked_at` fresher
 * than the four-hourly `packages:refresh-tracking` sweep needs, so these
 * packages never cost a second request.
 *
 * Polling at all is forced: Shopify publishes no webhook for a voided label,
 * only `FULFILLMENTS_UPDATE`, which needs a public callback URL an on-prem
 * install may not have.
 */
class ShopifyFulfillmentSynchronizer
{
    public function __construct(
        private readonly ShopifyShippingLabelService $labelService,
        private readonly ShopifyPostageSource $postageSource,
        private readonly TrackingService $trackingService,
        private readonly ShopifyGoodsFingerprint $goods,
    ) {}

    /**
     * Ask Shopify about every live Shopify-shipped package: un-ship the voided
     * ones, record where the rest have got to.
     *
     * @return array{checked: int, voided: int, tracked: int, failed: int}
     */
    public function sync(?int $limit = null): array
    {
        $packages = $this->candidates($limit);
        $voided = 0;
        $tracked = 0;
        $failed = 0;

        foreach ($packages as $package) {
            try {
                $fulfillment = $this->labelService->fulfillmentFor($package);

                if ($this->labelService->isVoided($fulfillment)) {
                    $this->applyVoid($package);
                    $voided++;

                    continue;
                }

                // No fulfillment we can identify as this package's is no answer
                // to either question. Recording a status from it would attribute
                // another parcel's progress to this one.
                if ($fulfillment === null) {
                    continue;
                }

                $this->trackingService->record($package, $this->postageSource->trackingFrom($fulfillment));
                $tracked++;
            } catch (\Exception $e) {
                $failed++;

                logger()->warning('Shopify fulfillment check failed', [
                    'package_id' => $package->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['checked' => $packages->count(), 'voided' => $voided, 'tracked' => $tracked, 'failed' => $failed];
    }

    /**
     * Packages Shopify might still have something to say about.
     *
     * Bounded by how recently the package shipped as well as by delivery. The
     * ship-date bound carried the whole filter when nothing ever set
     * `delivered_at` on these packages; now that this sync records tracking, a
     * delivered parcel drops out on its own — its label can no longer be voided
     * and its journey is over — and the date bound is what stops a parcel that
     * never reports delivery from being polled for good.
     *
     * @return Collection<int, Package>
     */
    public function candidates(?int $limit = null): Collection
    {
        $days = (int) config('services.shopify.label_void_check_days', 30);

        return Package::query()
            ->where('postage_source', PostageSource::PostageDataSource)
            ->whereHas(
                'postageDataSource',
                fn (Builder $query): Builder => $query->where('source_type', ShopifySource::class),
            )
            ->where('status', PackageStatus::Shipped)
            ->whereNotNull('tracking_number')
            ->where('shipped_at', '>=', now()->subDays($days))
            ->whereNull('delivered_at')
            // Shopify reports `deliveredAt` as nullable even on a DELIVERED
            // fulfillment, so the timestamp alone is not a reliable end state:
            // a package delivered without one would otherwise be polled every
            // fifteen minutes for the rest of the window. The status is what
            // says the journey is over.
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('tracking_status')
                ->orWhereNotIn('tracking_status', [
                    TrackingStatus::Delivered->value,
                    TrackingStatus::Returned->value,
                ]))
            ->with(['shipment', 'postageDataSource'])
            ->when($limit !== null, fn (Builder $query): Builder => $query->limit($limit))
            ->get();
    }

    /**
     * Reverse the shipment locally, leaving the package ready to ship again.
     */
    private function applyVoid(Package $package): void
    {
        $trackingNumber = $package->tracking_number;

        // Drop the Shopify label identifiers along with the shipping data. They
        // are what ShopifyShippingLabelService uses to recover a half-finished
        // purchase, and a voided label must never be recovered — re-shipping
        // this package has to buy a new one.
        $package->metadata = collect($package->metadata ?? [])
            ->except([
                'shopify_shipping_label_id',
                'shopify_purchase_result_id',
                'shopify_label_document_url',
                'shopify_customs_form_url',
            ])
            ->all();
        $package->save();

        $package->clearShipping();

        AuditLog::record(
            action: AuditAction::PackageCancelled,
            auditable: $package,
            metadata: [
                'reason' => 'Label voided in Shopify',
                'tracking_number' => $trackingNumber,
            ],
        );

        logger()->info('Shopify label voided outside PolyBag; package returned to unshipped', [
            'package_id' => $package->id,
            'tracking_number' => $trackingNumber,
        ]);

        $this->repointFulfillmentOrder($package);
    }

    /**
     * Move the shipment onto the fulfillment order that replaced the one the
     * voided label was bought against.
     *
     * A void reopens nothing. Shopify closes the fulfillment order the label
     * was bought against permanently and creates a fresh one for the same line
     * items, so a shipment left holding the closed ID still satisfies
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
    private function repointFulfillmentOrder(Package $package): void
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
