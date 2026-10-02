<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\PackageStatus;
use App\Enums\PostageSource;
use App\Enums\TrackingStatus;
use App\Enums\VoidReason;
use App\Models\AuditLog;
use App\Models\Package;
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
        private readonly ShopifyFulfillmentOrderReplacer $replacer,
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

        // clearShipping() drops the Shopify label identifiers with the shipping
        // data (`Package::LABEL_METADATA_KEYS`). They are what
        // ShopifyShippingLabelService uses to recover a half-finished purchase,
        // and a voided label must never be recovered.
        // Nobody in PolyBag asked for this void; Shopify reported it done.
        $package->clearShipping(VoidReason::VoidedUpstream);

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

        $this->replacer->repoint($package);
    }
}
