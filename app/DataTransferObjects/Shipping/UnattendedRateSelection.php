<?php

namespace App\DataTransferObjects\Shipping;

use App\Services\RateSelector;
use Illuminate\Support\Collection;

/**
 * What automation may buy, and what it declined to buy on nobody's authority.
 *
 * {@see RateSelector::selectBest()} answers only the first half, because that
 * is the answer the ADR names and a caller must not be able to get a rate out
 * of it that nobody approved. The second half is here so that the refusal can
 * be reported rather than presented as "no rates available" — a packer told
 * that goes looking at the carrier, when what actually happened is that a
 * service was quoted and an administrator has not approved it (ADR-0003
 * decision 4).
 *
 * Both halves come out of one pass, so reporting the reason costs no extra
 * query on a batch of several hundred labels.
 */
readonly class UnattendedRateSelection
{
    /**
     * @param  RateResponse|null  $rate  The quoted rate to buy
     * @param  BlindPurchaseOffer|null  $blindOffer  The explicitly authorized blind purchase to buy
     * @param  Collection<int, RateResponse>  $withheld  Rates that were quoted and are not approved for automated purchase
     * @param  bool  $attendedAlternativeAvailable  Whether a person can make a choice automation is forbidden to make
     * @param  Collection<int, RateResponse>|null  $late  Approved rates refused because the Amazon connection requires on-time delivery
     * @param  Collection<int, RateResponse>|null  $unprotected  Approved rates refused because the Amazon connection requires OTDR protection
     * @param  OfferRequirements|null  $requirements  What the order's Amazon connection required, if anything
     * @param  bool  $deadlineMissing  Whether on-time delivery was required of an order with no deliver-by date, so no rate could meet it
     */
    public function __construct(
        public ?RateResponse $rate,
        public Collection $withheld,
        public ?BlindPurchaseOffer $blindOffer = null,
        public bool $attendedAlternativeAvailable = false,
        public ?Collection $late = null,
        public ?Collection $unprotected = null,
        public ?OfferRequirements $requirements = null,
        public bool $deadlineMissing = false,
    ) {
        if ($rate !== null && $blindOffer !== null) {
            throw new \InvalidArgumentException('Unattended shipping may select either a rate or a blind purchase, never both.');
        }
    }

    /**
     * Whether an Amazon connection's on-time or protection requirement is
     * what stood between automation and a rate.
     */
    public function refusedForRequirements(): bool
    {
        return ($this->late?->isNotEmpty() ?? false) || ($this->unprotected?->isNotEmpty() ?? false);
    }

    public function withheldAnything(): bool
    {
        return $this->withheld->isNotEmpty();
    }

    /**
     * The withheld services as an operator would name them, for a notification
     * and for the log line beside it.
     */
    public function withheldSummary(): string
    {
        return $this->withheld
            ->map(fn (RateResponse $rate): string => trim("{$rate->carrier} {$rate->serviceName}")
                .($rate->observedService === null ? '' : " (via {$rate->observedService->source})"))
            ->unique()
            ->implode(', ');
    }

    /**
     * @return array<int, array{source: string, environment: string, carrier: string, service: string}>
     */
    public function withheldForLog(): array
    {
        return $this->withheld
            ->filter(fn (RateResponse $rate): bool => $rate->observedService !== null)
            ->map(fn (RateResponse $rate): array => [
                'source' => $rate->observedService->source,
                'environment' => $rate->observedService->environment->value,
                'carrier' => $rate->observedService->externalCarrierId,
                'service' => $rate->observedService->externalServiceId,
            ])
            ->values()
            ->all();
    }
}
