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
 * Both answers live on the same fulfillment, so both come out of one poll,
 * which reads many packages' fulfillment orders to a request. It runs every
 * fifteen minutes, which also keeps `tracking_checked_at` fresher
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
     * One request per connection per batch of fulfillment orders, not one per
     * package (`shopify-shipping-carrier/27`): a parcel is polled ninety-six
     * times a day for as long as it is in transit, and a request each made
     * that tens of thousands a day for a busy warehouse.
     *
     * @return array{checked: int, voided: int, tracked: int, failed: int}
     */
    public function sync(?int $limit = null): array
    {
        $packages = $this->candidates($limit);
        $counts = ['voided' => 0, 'tracked' => 0, 'failed' => 0];

        // Read before any void is applied, and kept: a void re-points the
        // shipment's fulfillment order, and a sibling package later in the batch
        // was fetched under the one it had, which is the one that answers for it.
        // Today the sibling's loaded shipment happens not to see the re-point
        // (`clearShipping()` reloads the voided package's own); this does not
        // rely on that.
        $fulfillmentOrderIds = $packages->mapWithKeys(fn (Package $package): array => [
            $package->id => $this->labelService->fulfillmentOrderId($package),
        ]);

        // A package with no connection to ask, no fulfillment order or no
        // tracking number has no answer to be had, the same as before batching:
        // checked, and neither tracked nor failed.
        $askable = $packages->filter(fn (Package $package): bool => $package->tracking_number !== null
            && $fulfillmentOrderIds[$package->id] !== null
            && $this->labelService->postageSourceFor($package) !== null);

        foreach ($askable->groupBy(fn (Package $package): int => $this->labelService->postageSourceFor($package)->id) as $connectionPackages) {
            $dataSource = $this->labelService->postageSourceFor($connectionPackages->first());
            $byFulfillmentOrder = $connectionPackages->groupBy(fn (Package $package): string => $fulfillmentOrderIds[$package->id]);

            // Packages sharing a fulfillment order are asked about once.
            foreach ($byFulfillmentOrder->keys()->chunk(ShopifyShippingLabelService::FULFILLMENT_LOOKUP_BATCH_SIZE) as $batch) {
                $batchPackages = $batch->flatMap(fn (string $id): Collection => $byFulfillmentOrder[$id]);

                try {
                    $lookup = $this->labelService->lookUpFulfillments($dataSource, $batch->values()->all());
                } catch (\Exception $e) {
                    // Left for the next run, like a failed request always was.
                    foreach ($batchPackages as $package) {
                        $this->recordFailure($package, $e->getMessage(), $counts);
                    }

                    continue;
                }

                foreach ($batchPackages as $package) {
                    $fulfillmentOrderId = $fulfillmentOrderIds[$package->id];

                    if (isset($lookup->failures[$fulfillmentOrderId])) {
                        $this->recordFailure($package, $lookup->failures[$fulfillmentOrderId], $counts);

                        continue;
                    }

                    $this->apply(
                        $package,
                        $this->labelService->fulfillmentMatching($lookup->fulfillments[$fulfillmentOrderId] ?? null, $package->tracking_number),
                        $counts,
                    );
                }
            }
        }

        return ['checked' => $packages->count(), ...$counts];
    }

    /**
     * Act on what Shopify said about one package.
     *
     * @param  array<string, mixed>|null  $fulfillment
     * @param  array{voided: int, tracked: int, failed: int}  $counts
     */
    private function apply(Package $package, ?array $fulfillment, array &$counts): void
    {
        try {
            if ($this->labelService->isVoided($fulfillment)) {
                $this->applyVoid($package);
                $counts['voided']++;

                return;
            }

            // No fulfillment we can identify as this package's is no answer
            // to either question. Recording a status from it would attribute
            // another parcel's progress to this one.
            if ($fulfillment === null) {
                return;
            }

            $this->trackingService->record($package, $this->postageSource->trackingFrom($fulfillment));
            $counts['tracked']++;
        } catch (\Exception $e) {
            $this->recordFailure($package, $e->getMessage(), $counts);
        }
    }

    /**
     * @param  array{voided: int, tracked: int, failed: int}  $counts
     */
    private function recordFailure(Package $package, string $error, array &$counts): void
    {
        $counts['failed']++;

        logger()->warning('Shopify fulfillment check failed', [
            'package_id' => $package->id,
            'error' => $error,
        ]);
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
