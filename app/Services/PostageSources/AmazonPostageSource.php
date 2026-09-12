<?php

namespace App\Services\PostageSources;

use App\Contracts\PostageSourceOperations;
use App\DataTransferObjects\Shipping\CancelResponse;
use App\DataTransferObjects\Tracking\TrackingEventData;
use App\DataTransferObjects\Tracking\TrackShipmentResponse;
use App\Enums\TrackingStatus;
use App\Exceptions\Carriers\AmazonLabelPurchaseException;
use App\Models\Package;
use App\Services\AmazonBuyShippingService;
use App\Services\Carriers\AmazonBuyShippingAdapter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * The operations that belong to Amazon because Amazon bought the label.
 *
 * All three are keyed on identifiers Amazon issued rather than on anything the
 * carrier knows, and that is the point: `01` returned OnTrac as the cheapest
 * eligible offer, and we hold no OnTrac row, no OnTrac account and no OnTrac
 * adapter. Voiding and tracking such a parcel is possible only through the
 * party that bought it — the `shipmentId` cancels it and Amazon's own
 * `carrierId` tracks it, both stored on the package at purchase.
 *
 * Manifesting is the exception that proves the rule: a SCAN form is a claim
 * that we tendered these parcels on our own account, which is false however
 * Amazon's postage is carried.
 */
class AmazonPostageSource implements PostageSourceOperations
{
    /**
     * Amazon's `Status` onto our own tracking vocabulary.
     *
     * Lossy in one direction only. Amazon distinguishes four unhappy endings —
     * lost, rejected, undeliverable, attempted — where we have one
     * `Exception`, and the distinction survives in `statusLabel` and the raw
     * details rather than being thrown away. `AwaitingCustomerPickup` maps to
     * `OutForDelivery` because that is what it means to the recipient: the
     * parcel has arrived and is waiting to be collected.
     *
     * @var array<string, TrackingStatus>
     */
    private const STATUS_MAP = [
        'PreTransit' => TrackingStatus::PreTransit,
        'InTransit' => TrackingStatus::InTransit,
        'OutForDelivery' => TrackingStatus::OutForDelivery,
        'AwaitingCustomerPickup' => TrackingStatus::OutForDelivery,
        'Delivered' => TrackingStatus::Delivered,
        'Lost' => TrackingStatus::Exception,
        'Rejected' => TrackingStatus::Exception,
        'Undeliverable' => TrackingStatus::Exception,
        'DeliveryAttempted' => TrackingStatus::Exception,
        'PickupCancelled' => TrackingStatus::Exception,
    ];

    /**
     * Event codes that describe a parcel going back rather than forward.
     *
     * `Status` has no equivalent of `TrackingStatus::Returned`, so the only
     * place a return is visible is the event history.
     *
     * @var array<string, TrackingStatus>
     */
    private const EVENT_STATUS_MAP = [
        'ReadyForReceive' => TrackingStatus::PreTransit,
        'PickupDone' => TrackingStatus::InTransit,
        'Departed' => TrackingStatus::InTransit,
        'ArrivedAtCarrierFacility' => TrackingStatus::InTransit,
        'OutForDelivery' => TrackingStatus::OutForDelivery,
        'AvailableForPickup' => TrackingStatus::OutForDelivery,
        'Delivered' => TrackingStatus::Delivered,
        'DeliveryAttempted' => TrackingStatus::Exception,
        'Lost' => TrackingStatus::Exception,
        'Rejected' => TrackingStatus::Exception,
        'Undeliverable' => TrackingStatus::Exception,
        'PickupCancelled' => TrackingStatus::Exception,
        'ReturnInitiated' => TrackingStatus::Returned,
        'RecipientRequestedAlternateDeliveryTiming' => TrackingStatus::InTransit,
    ];

    public function __construct(
        private readonly AmazonBuyShippingService $buyShipping,
    ) {}

    /**
     * Void the shipment Amazon created, by the ID Amazon gave it.
     *
     * A package with no stored shipment ID is not a package to try voiding a
     * different way: it means Amazon did not sell this label, and the tracking
     * number is somebody else's handle on the parcel.
     */
    public function voidLabel(Package $package): CancelResponse
    {
        $shipmentId = AmazonBuyShippingAdapter::shipmentIdFor($package);

        if ($shipmentId === null) {
            return CancelResponse::failure(
                'This package records no Amazon shipment, so there is nothing for Amazon to void. '
                .'Cancel it in Seller Central if a label exists there.'
            );
        }

        try {
            $response = $this->buyShipping->cancel($package, $shipmentId);
        } catch (AmazonLabelPurchaseException $e) {
            return CancelResponse::failure($e->getMessage());
        } catch (\Throwable $e) {
            logger()->error('Amazon void failed', [
                'package_id' => $package->id,
                'amazon_shipment_id' => $shipmentId,
                'error' => $e->getMessage(),
            ]);

            return CancelResponse::failure('Amazon could not be reached to void this label. Try again in a moment.');
        }

        if (! $response->successful()) {
            return CancelResponse::failure(
                'Amazon refused to void this label: '.$this->describeErrors($response->json('errors', []))
            );
        }

        // Amazon's identifiers go with the label. The shipment ID in particular
        // is what tells the channel export "Amazon already confirmed this order,
        // do not confirm it again" — left behind after a void, a re-ship on a
        // direct carrier account would skip the confirmation and the order
        // would never be marked shipped on Amazon. Same treatment Shopify's
        // void gives its label identifiers.
        $package->metadata = collect($package->metadata ?? [])
            ->except([
                AmazonBuyShippingAdapter::SHIPMENT_ID_KEY,
                AmazonBuyShippingAdapter::CARRIER_ID_KEY,
                AmazonBuyShippingAdapter::SERVICE_ID_KEY,
            ])
            ->all();
        $package->save();

        return CancelResponse::success('Amazon cancelled the shipment.');
    }

    /**
     * Never. Amazon tenders these parcels on its own account, so a SCAN form we
     * create would claim credit for a handover we did not make.
     */
    public function supportsPackageManifest(Package $package): bool
    {
        return false;
    }

    public function trackShipment(Package $package): TrackShipmentResponse
    {
        $carrierId = $package->metadata[AmazonBuyShippingAdapter::CARRIER_ID_KEY] ?? null;

        if (blank($carrierId) || blank($package->tracking_number)) {
            // Not "unsupported": Amazon does track its shipments, and this
            // particular package simply lacks the identifiers to ask with —
            // a label bought before this adapter existed, or one whose
            // provenance was rewritten.
            return TrackShipmentResponse::failure(
                'This package does not record the Amazon carrier it was shipped with, so Amazon cannot be asked about it.'
            );
        }

        try {
            $response = $this->buyShipping->track($package, (string) $package->tracking_number, (string) $carrierId);
        } catch (\Throwable $e) {
            logger()->warning('Amazon tracking request failed', [
                'package_id' => $package->id,
                'error' => $e->getMessage(),
            ]);

            return TrackShipmentResponse::failure('Amazon could not be reached for tracking on this package.');
        }

        if (! $response->successful()) {
            return TrackShipmentResponse::failure(
                'Amazon reported no tracking for this package: '.$this->describeErrors($response->json('errors', []))
            );
        }

        return $this->trackingFrom($response->json('payload', []));
    }

    /**
     * Read tracking out of a `getTracking` payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function trackingFrom(array $payload): TrackShipmentResponse
    {
        $rawStatus = $payload['summary']['status'] ?? null;
        $events = $this->events($payload['eventHistory'] ?? []);
        $status = self::STATUS_MAP[$rawStatus] ?? null;

        // A return is only ever visible in the event history — Amazon's summary
        // vocabulary has no case for it — so an initiated return outranks the
        // summary rather than being lost behind "in transit".
        $returned = collect($events)->contains(
            fn (TrackingEventData $event): bool => $event->status === TrackingStatus::Returned->value
        );

        if ($returned && $status !== TrackingStatus::Delivered) {
            $status = TrackingStatus::Returned;
        }

        if ($status === null) {
            return TrackShipmentResponse::failure(
                'Amazon has not reported a delivery status for this shipment yet.',
                ['raw' => $payload],
            );
        }

        $deliveredAt = $status === TrackingStatus::Delivered
            ? collect($events)
                ->first(fn (TrackingEventData $event): bool => $event->statusCode === 'Delivered')
                ?->timestamp
            : null;

        return TrackShipmentResponse::success(
            status: $status,
            events: $events,
            // Amazon's `promisedDeliveryDate` is the commitment made at
            // purchase, not a live estimate — but it is the only forward-looking
            // date the API publishes, and an operator reads it the same way.
            estimatedDeliveryAt: $this->timestamp($payload['promisedDeliveryDate'] ?? null),
            deliveredAt: $deliveredAt,
            statusLabel: $this->humanize($rawStatus),
            details: ['raw' => $payload],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $history
     * @return array<int, TrackingEventData>
     */
    private function events(array $history): array
    {
        return collect($history)
            ->map(fn (array $event): TrackingEventData => new TrackingEventData(
                timestamp: $this->timestamp($event['eventTime'] ?? null),
                location: $this->location($event['location'] ?? []),
                description: $this->humanize($event['eventCode'] ?? null),
                statusCode: $event['eventCode'] ?? null,
                status: (self::EVENT_STATUS_MAP[$event['eventCode'] ?? ''] ?? null)?->value,
                raw: $event,
            ))
            ->sortByDesc(fn (TrackingEventData $event): int => $event->timestamp?->getTimestamp() ?? 0)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $location
     */
    private function location(array $location): ?string
    {
        $parts = array_filter([
            $location['city'] ?? null,
            $location['stateOrRegion'] ?? null,
            $location['postalCode'] ?? null,
            $location['countryCode'] ?? null,
        ], fn (?string $part): bool => filled($part));

        return $parts === [] ? null : implode(', ', $parts);
    }

    private function timestamp(?string $value): ?CarbonImmutable
    {
        return filled($value) ? CarbonImmutable::parse($value) : null;
    }

    /**
     * Amazon's identifiers are PascalCase (`OutForDelivery`), not the
     * SCREAMING_SNAKE Shopify uses, so they split on case rather than on
     * underscores.
     */
    private function humanize(?string $status): string
    {
        return filled($status)
            ? Str::of($status)->kebab()->replace('-', ' ')->ucfirst()->toString()
            : 'Tracking event';
    }

    /**
     * @param  array<int, array<string, mixed>>  $errors
     */
    private function describeErrors(array $errors): string
    {
        $messages = collect($errors)
            ->map(fn (array $error): string => trim((string) ($error['message'] ?? $error['code'] ?? '')))
            ->filter()
            ->all();

        return $messages === [] ? 'Amazon gave no reason.' : implode('; ', $messages);
    }
}
