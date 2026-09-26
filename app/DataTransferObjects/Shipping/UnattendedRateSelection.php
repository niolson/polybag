<?php

namespace App\DataTransferObjects\Shipping;

use App\DataTransferObjects\PostageSources\ObservedServiceIdentity;
use App\Enums\AmazonChannelType;
use App\Services\RateSelector;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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
     * @param  Collection<int, RateResponse>|null  $late  Approved rates refused because the shipping method excludes late rates
     * @param  Collection<int, RateResponse>|null  $unprotected  Approved rates refused because the shipping method requires OTDR protection
     * @param  OfferRequirements|null  $requirements  What the order's shipping method required, if anything
     * @param  bool  $deadlineMissing  Whether on-time delivery was required of an Amazon order with no due-by date, so no rate could meet it
     * @param  Collection<int, RateResponse>|null  $contentRestricted  Rates refused because they are valid only for contents nothing in PolyBag vouches for. Not `withheld`: no approval can release them
     * @param  Collection<int, RateResponse>|null  $heldByPostageSetting  Rates refused because the connection sells postage to a packer only (ADR-0006 decision 6). Decided before approvals, so not `withheld`: no approval can release them
     * @param  Collection<int, BlindPurchaseOffer>|null  $blindOffersHeldByPostageSetting  Blind offers a rule or the sole-choice rule would have bought, refused for the same reason
     * @param  string|null  $postageSettingConnection  The name of the connection whose postage setting held them
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
        public ?Collection $contentRestricted = null,
        public ?Collection $heldByPostageSetting = null,
        public ?Collection $blindOffersHeldByPostageSetting = null,
        public ?string $postageSettingConnection = null,
    ) {
        if ($rate !== null && $blindOffer !== null) {
            throw new \InvalidArgumentException('Unattended shipping may select either a rate or a blind purchase, never both.');
        }
    }

    /**
     * Whether the shipping method's on-time or protection requirement is
     * what stood between automation and a rate.
     */
    public function refusedForRequirements(): bool
    {
        return ($this->late?->isNotEmpty() ?? false) || ($this->unprotected?->isNotEmpty() ?? false);
    }

    public function contentRestrictedAnything(): bool
    {
        return $this->contentRestricted?->isNotEmpty() ?? false;
    }

    /**
     * The content-restricted services as an operator would name them.
     */
    public function contentRestrictedSummary(): string
    {
        return ($this->contentRestricted ?? collect())
            ->map(fn (RateResponse $rate): string => trim("{$rate->carrier} {$rate->serviceName}"))
            ->unique()
            ->implode(', ');
    }

    public function heldByPostageSettingAnything(): bool
    {
        return ($this->heldByPostageSetting?->isNotEmpty() ?? false)
            || ($this->blindOffersHeldByPostageSetting?->isNotEmpty() ?? false);
    }

    /**
     * The channel postage the connection's setting held back, as an operator
     * would name it.
     */
    public function heldByPostageSettingSummary(): string
    {
        return ($this->heldByPostageSetting ?? collect())
            ->map(fn (RateResponse $rate): string => trim("{$rate->carrier} {$rate->serviceName}"))
            ->merge(($this->blindOffersHeldByPostageSetting ?? collect())
                ->map(fn (BlindPurchaseOffer $offer): string => "{$offer->sourceLabel} {$offer->selectionLabel}"))
            ->unique()
            ->implode(', ');
    }

    /**
     * The same selection, also holding back these blind offers, which a rule
     * or the sole-choice rule would have bought.
     *
     * @param  Collection<int, BlindPurchaseOffer>  $offers
     */
    public function holdingBlindOffers(Collection $offers, string $connection): self
    {
        if ($offers->isEmpty()) {
            return $this;
        }

        return new self(
            rate: $this->rate,
            withheld: $this->withheld,
            blindOffer: $this->blindOffer,
            attendedAlternativeAvailable: true,
            late: $this->late,
            unprotected: $this->unprotected,
            requirements: $this->requirements,
            deadlineMissing: $this->deadlineMissing,
            contentRestricted: $this->contentRestricted,
            heldByPostageSetting: $this->heldByPostageSetting,
            blindOffersHeldByPostageSetting: ($this->blindOffersHeldByPostageSetting ?? collect())->merge($offers)->values(),
            postageSettingConnection: $connection,
        );
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
                .($rate->observedService === null ? '' : ' (via '.self::approvalScope($rate->observedService).')'))
            ->unique()
            ->implode(', ');
    }

    /**
     * Where the approval that is missing would be filed. An off-Amazon rate is
     * said so, because an approval for Amazon orders does not cover it.
     */
    private static function approvalScope(ObservedServiceIdentity $identity): string
    {
        return $identity->channelType === AmazonChannelType::External
            ? "{$identity->source}, ".Str::lower($identity->channelType->label())
            : $identity->source;
    }

    /**
     * @return array<int, array{source: string, environment: string, channel_type: string, carrier: string, service: string}>
     */
    public function withheldForLog(): array
    {
        return $this->withheld
            ->filter(fn (RateResponse $rate): bool => $rate->observedService !== null)
            ->map(fn (RateResponse $rate): array => [
                'source' => $rate->observedService->source,
                'environment' => $rate->observedService->environment->value,
                'channel_type' => $rate->observedService->channelType->value,
                'carrier' => $rate->observedService->externalCarrierId,
                'service' => $rate->observedService->externalServiceId,
            ])
            ->values()
            ->all();
    }
}
