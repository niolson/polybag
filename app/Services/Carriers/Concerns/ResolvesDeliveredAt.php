<?php

namespace App\Services\Carriers\Concerns;

use App\DataTransferObjects\Tracking\TrackingEventData;
use Carbon\CarbonImmutable;

/**
 * Shared two-phase rule for deriving a package's actual delivery timestamp.
 *
 * Phase 1 prefers a delivered scan event that carries a real timestamp; only
 * when none exists does Phase 2 defer to the carrier's summary-status fallback.
 *
 * Splitting the phases forces every carrier to declare its fallback explicitly
 * via deliveredAtFallback() — USPS opts out on purpose because its predicted
 * dates are documented as unreliable. Bug #1 was exactly this fallback being
 * silently half-implemented in one adapter but not another; the abstract hook
 * makes that omission impossible to write by accident.
 *
 * Note: a delivered event whose timestamp is null is skipped in favor of the
 * fallback (rather than short-circuiting to null), so a carrier that publishes a
 * separate summary delivery date still surfaces it.
 */
trait ResolvesDeliveredAt
{
    /**
     * @param  array<int, TrackingEventData>  $events
     * @param  array<string, mixed>  $summary  The carrier's parsed tracking payload
     */
    private function resolveDeliveredAt(array $events, array $summary = []): ?CarbonImmutable
    {
        $timestamp = collect($events)
            ->filter(fn (TrackingEventData $event): bool => $this->isDeliveredEvent($event))
            ->map(fn (TrackingEventData $event): ?CarbonImmutable => $event->timestamp)
            ->first(fn (?CarbonImmutable $timestamp): bool => $timestamp instanceof CarbonImmutable);

        return $timestamp ?? $this->deliveredAtFallback($summary);
    }

    /**
     * Does this scan event represent a stop-the-clock delivery?
     */
    abstract protected function isDeliveredEvent(TrackingEventData $event): bool;

    /**
     * Carrier delivery timestamp when no delivered scan event carries a usable
     * timestamp. Return null to explicitly opt out of a summary fallback.
     *
     * @param  array<string, mixed>  $summary
     */
    abstract protected function deliveredAtFallback(array $summary): ?CarbonImmutable;
}
