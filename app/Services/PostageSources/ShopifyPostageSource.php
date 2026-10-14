<?php

namespace App\Services\PostageSources;

use App\Contracts\PostageSourceOperations;
use App\DataTransferObjects\Shipping\CancelResponse;
use App\DataTransferObjects\Tracking\TrackingEventData;
use App\DataTransferObjects\Tracking\TrackShipmentResponse;
use App\Enums\TrackingStatus;
use App\Models\Package;
use App\Services\ShopifyShippingLabelService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * The operations that belong to Shopify because Shopify bought the label.
 *
 * Tracking is the interesting one. USPS stopped entitling us to tracking data
 * for parcels whose barcode carries somebody else's MID (ADR-0002's entitlement
 * note), so a Shopify-bought USPS label cannot be tracked through USPS at any
 * price we would pay. It can be tracked through Shopify: Shopify holds that
 * entitlement, and reports the result on the fulfillment its purchase created
 * as `displayStatus` plus a delivery timeline.
 *
 * What comes back is coarser than a carrier feed — stage-level statuses on
 * Shopify's polling cadence, with scan detail only if `events` happens to be
 * populated — and that is accepted rather than worked around. The alternative
 * is not a better feed; it is no feed.
 */
class ShopifyPostageSource implements PostageSourceOperations
{
    /**
     * Shopify has no void mutation, so the only way out is the admin. Held here
     * rather than on `ShopifyAdapter` because voiding follows the postage
     * source, and `ShopifyAdapter` is no longer on the path: a Shopify-bought
     * package now records its physical carrier, not "Shopify".
     */
    public const VOID_MESSAGE = 'Shopify Shipping labels cannot be voided through the API. Void this label in the Shopify admin. PolyBag returns the package to unshipped once Shopify reports it voided.';

    /**
     * `FulfillmentDisplayStatus` and `FulfillmentEventStatus` onto our own
     * tracking vocabulary. Deliberately lossy: Shopify has no equivalent of
     * `TrackingStatus::Returned`, and every unhappy outcome it does report
     * collapses to `Exception`.
     *
     * `LABEL_VOIDED` and `CANCELED` are absent on purpose — they are a void,
     * which the fulfillment synchronizer acts on, not a delivery status.
     *
     * @var array<string, TrackingStatus>
     */
    private const STATUS_MAP = [
        'LABEL_PURCHASED' => TrackingStatus::PreTransit,
        'LABEL_PRINTED' => TrackingStatus::PreTransit,
        'SUBMITTED' => TrackingStatus::PreTransit,
        'CONFIRMED' => TrackingStatus::PreTransit,
        'FULFILLED' => TrackingStatus::PreTransit,
        'MARKED_AS_FULFILLED' => TrackingStatus::PreTransit,
        'CARRIER_PICKED_UP' => TrackingStatus::InTransit,
        'IN_TRANSIT' => TrackingStatus::InTransit,
        'OUT_FOR_DELIVERY' => TrackingStatus::OutForDelivery,
        'READY_FOR_PICKUP' => TrackingStatus::OutForDelivery,
        'PICKED_UP' => TrackingStatus::Delivered,
        'DELIVERED' => TrackingStatus::Delivered,
        'ATTEMPTED_DELIVERY' => TrackingStatus::Exception,
        'DELAYED' => TrackingStatus::Exception,
        'FAILURE' => TrackingStatus::Exception,
        'NOT_DELIVERED' => TrackingStatus::Exception,
    ];

    public function __construct(
        private readonly ShopifyShippingLabelService $labelService,
    ) {}

    public function voidLabel(Package $package): CancelResponse
    {
        return CancelResponse::failure(self::VOID_MESSAGE);
    }

    /**
     * Shopify manifests its own postage; a SCAN form we create covers labels we
     * bought, and USPS has never confirmed it would accept somebody else's.
     *
     * Answered per package because that is the shape of the question at this
     * seam, not because the answer could vary — no Shopify-bought parcel is
     * ever eligible.
     */
    public function supportsPackageManifest(Package $package): bool
    {
        return false;
    }

    public function trackShipment(Package $package): TrackShipmentResponse
    {
        return $this->trackingFrom($this->labelService->fulfillmentFor($package));
    }

    /**
     * Read tracking out of a fulfillment already fetched.
     *
     * Separate from `trackShipment()` so the fulfillment synchronizer can answer
     * both of its questions — voided? how far along? — from the one poll it
     * already makes, rather than asking Shopify the same thing twice.
     *
     * @param  array<string, mixed>|null  $fulfillment
     */
    public function trackingFrom(?array $fulfillment): TrackShipmentResponse
    {
        // No fulfillment carrying our tracking number is *no answer*, the same
        // way it is for a void: an order fulfilled in several shipments carries
        // other packages' fulfillments, and none of them describe this parcel.
        if ($fulfillment === null) {
            return TrackShipmentResponse::failure('Shopify reports no fulfillment carrying this tracking number.');
        }

        $displayStatus = $fulfillment['displayStatus'] ?? null;

        if ($this->labelService->isVoided($fulfillment)) {
            return TrackShipmentResponse::failure('Shopify reports this label as voided, so it will never be scanned.');
        }

        $status = self::STATUS_MAP[$displayStatus] ?? null;

        if ($status === null) {
            return TrackShipmentResponse::failure(
                'Shopify has not reported a delivery status for this label yet.',
                ['raw' => $fulfillment],
            );
        }

        return TrackShipmentResponse::success(
            status: $status,
            events: $this->events($fulfillment),
            estimatedDeliveryAt: $this->timestamp($fulfillment['estimatedDeliveryAt'] ?? null),
            deliveredAt: $this->timestamp($fulfillment['deliveredAt'] ?? null),
            statusLabel: $this->humanize($displayStatus),
            details: ['raw' => $fulfillment],
        );
    }

    /**
     * `Fulfillment.events` carries the scan-level detail `displayStatus` cannot,
     * but the only documented way events get created is `fulfillmentEventCreate`,
     * which apps and fulfillment services call — nothing says Shopify writes them
     * for the shipments it tracks itself. An empty connection is therefore a
     * normal answer, not a failure, and the status above stands on its own.
     *
     * @param  array<string, mixed>  $fulfillment
     * @return array<int, TrackingEventData>
     */
    private function events(array $fulfillment): array
    {
        return collect($fulfillment['events']['nodes'] ?? [])
            ->map(fn (array $event): TrackingEventData => new TrackingEventData(
                timestamp: $this->timestamp($event['happenedAt'] ?? null),
                location: $this->location($event),
                description: filled($event['message'] ?? null)
                    ? (string) $event['message']
                    : $this->humanize($event['status'] ?? null),
                statusCode: $event['status'] ?? null,
                status: (self::STATUS_MAP[$event['status'] ?? ''] ?? null)?->value,
                raw: $event,
            ))
            ->sortByDesc(fn (TrackingEventData $event): int => $event->timestamp?->getTimestamp() ?? 0)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function location(array $event): ?string
    {
        $parts = array_filter([
            $event['city'] ?? null,
            $event['province'] ?? null,
            $event['zip'] ?? null,
            $event['country'] ?? null,
        ], fn (?string $part): bool => filled($part));

        return empty($parts) ? null : implode(', ', $parts);
    }

    private function timestamp(?string $value): ?CarbonImmutable
    {
        return filled($value) ? CarbonImmutable::parse($value) : null;
    }

    private function humanize(?string $status): string
    {
        return filled($status)
            ? Str::of($status)->lower()->replace('_', ' ')->ucfirst()->toString()
            : 'Tracking event';
    }
}
