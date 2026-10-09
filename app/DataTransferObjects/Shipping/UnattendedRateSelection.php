<?php

namespace App\DataTransferObjects\Shipping;

use App\Services\RateSelector;
use Illuminate\Support\Collection;

/**
 * What automation may buy, and what it declined to buy on nobody's authority.
 *
 * {@see RateSelector::selectBest()} answers only the first half, because that
 * is the answer the ADR names and a caller must not be able to get a rate out
 * of it that the shipping method does not allow. The second half is here so
 * that the refusal can be reported rather than presented as "no rates
 * available" — a packer told that goes looking at the carrier, when what
 * actually happened is that a service was quoted and the shipping method does
 * not allow automation to buy it (`carrier-catalog-reset/13`).
 *
 * Both halves come out of one pass, so reporting the reason costs no extra
 * query on a batch of several hundred labels.
 */
readonly class UnattendedRateSelection
{
    /**
     * @param  RateResponse|null  $rate  The quoted rate to buy
     * @param  BlindPurchaseOffer|null  $blindOffer  The explicitly authorized blind purchase to buy
     * @param  Collection<int, RateResponse>  $notAllowed  Rates that were quoted and are outside the shipping method's allowance
     * @param  string|null  $shippingMethodName  The shipping method whose allowance they are outside, for refusal messages
     * @param  bool  $attendedAlternativeAvailable  Whether a person can make a choice automation is forbidden to make
     * @param  Collection<int, RateResponse>|null  $late  Allowed rates refused because the shipping method excludes late rates
     * @param  Collection<int, RateResponse>|null  $unprotected  Allowed rates refused because the shipping method requires OTDR protection
     * @param  OfferRequirements|null  $requirements  What the order's shipping method required, if anything
     * @param  bool  $deadlineMissing  Whether on-time delivery was required of an Amazon order with no due-by date, so no rate could meet it
     * @param  Collection<int, RateResponse>|null  $contentRestricted  Rates refused because they are valid only for contents nothing in PolyBag vouches for. Not `notAllowed`: no allowance can release them
     * @param  Collection<int, RateResponse>|null  $deactivated  Rates refused because they name a catalog service or carrier somebody deactivated. Not `notAllowed`: no allowance can release them
     * @param  Collection<int, RateResponse>|null  $heldByPostageSetting  Rates refused because the connection sells postage to a packer only (ADR-0006 decision 6). Decided before the allowance, so not `notAllowed`: no method can release them
     * @param  Collection<int, BlindPurchaseOffer>|null  $blindOffersHeldByPostageSetting  Blind offers a rule or the sole-choice rule would have bought, refused for the same reason
     * @param  string|null  $postageSettingConnection  The name of the connection whose postage setting held them
     * @param  Collection<int, RateResponse>|null  $heldForSourceTerms  Rates whose source decides the duties terms, refused because the destination is one whose source-decided terms nobody has verified (ADR-0008 decision 5). Decided before the allowance, so not `notAllowed`: no method can release them
     * @param  Collection<int, BlindPurchaseOffer>|null  $blindOffersHeldForSourceTerms  Blind offers a rule or the sole-choice rule would have bought, refused for the same reason
     * @param  string|null  $sourceTermsDestination  The destination country that held them
     */
    public function __construct(
        public ?RateResponse $rate,
        public Collection $notAllowed,
        public ?string $shippingMethodName = null,
        public ?BlindPurchaseOffer $blindOffer = null,
        public bool $attendedAlternativeAvailable = false,
        public ?Collection $late = null,
        public ?Collection $unprotected = null,
        public ?OfferRequirements $requirements = null,
        public bool $deadlineMissing = false,
        public ?Collection $contentRestricted = null,
        public ?Collection $deactivated = null,
        public ?Collection $heldByPostageSetting = null,
        public ?Collection $blindOffersHeldByPostageSetting = null,
        public ?string $postageSettingConnection = null,
        public ?Collection $heldForSourceTerms = null,
        public ?Collection $blindOffersHeldForSourceTerms = null,
        public ?string $sourceTermsDestination = null,
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

    public function deactivatedAnything(): bool
    {
        return $this->deactivated?->isNotEmpty() ?? false;
    }

    /**
     * The deactivated services as an operator would name them.
     */
    public function deactivatedSummary(): string
    {
        return ($this->deactivated ?? collect())
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
     * Whether a destination whose source-decided terms are unverified is what
     * stood between automation and an offer (ADR-0008 decision 5).
     */
    public function heldForSourceTermsAnything(): bool
    {
        return ($this->heldForSourceTerms?->isNotEmpty() ?? false)
            || ($this->blindOffersHeldForSourceTerms?->isNotEmpty() ?? false);
    }

    /**
     * The offers held for the destination, as an operator would name them.
     */
    public function heldForSourceTermsSummary(): string
    {
        return ($this->heldForSourceTerms ?? collect())
            ->map(fn (RateResponse $rate): string => trim("{$rate->carrier} {$rate->serviceName}"))
            ->merge(($this->blindOffersHeldForSourceTerms ?? collect())
                ->map(fn (BlindPurchaseOffer $offer): string => "{$offer->sourceLabel} {$offer->selectionLabel}"))
            ->unique()
            ->implode(', ');
    }

    /**
     * The same selection, also holding back these blind offers, which a rule
     * or the sole-choice rule would have bought: those the connection's
     * postage setting refuses, and those whose source-decided duties terms
     * the destination makes unverified. Each keeps its own reason.
     *
     * @param  Collection<int, BlindPurchaseOffer>  $heldByPostageSetting
     * @param  Collection<int, BlindPurchaseOffer>  $heldForSourceTerms
     */
    public function holdingBlindOffers(
        Collection $heldByPostageSetting,
        ?string $connection,
        Collection $heldForSourceTerms,
        ?string $sourceTermsDestination,
    ): self {
        if ($heldByPostageSetting->isEmpty() && $heldForSourceTerms->isEmpty()) {
            return $this;
        }

        return new self(
            rate: $this->rate,
            notAllowed: $this->notAllowed,
            shippingMethodName: $this->shippingMethodName,
            blindOffer: $this->blindOffer,
            attendedAlternativeAvailable: true,
            late: $this->late,
            unprotected: $this->unprotected,
            requirements: $this->requirements,
            deadlineMissing: $this->deadlineMissing,
            contentRestricted: $this->contentRestricted,
            deactivated: $this->deactivated,
            heldByPostageSetting: $this->heldByPostageSetting,
            blindOffersHeldByPostageSetting: ($this->blindOffersHeldByPostageSetting ?? collect())->merge($heldByPostageSetting)->values(),
            postageSettingConnection: $heldByPostageSetting->isNotEmpty() ? $connection : $this->postageSettingConnection,
            heldForSourceTerms: $this->heldForSourceTerms,
            blindOffersHeldForSourceTerms: ($this->blindOffersHeldForSourceTerms ?? collect())->merge($heldForSourceTerms)->values(),
            sourceTermsDestination: $heldForSourceTerms->isNotEmpty() ? $sourceTermsDestination : $this->sourceTermsDestination,
        );
    }

    public function notAllowedAnything(): bool
    {
        return $this->notAllowed->isNotEmpty();
    }

    /**
     * The services outside the allowance as an operator would name them, for
     * a notification and for the log line beside it.
     */
    public function notAllowedSummary(): string
    {
        return $this->notAllowed
            ->map(fn (RateResponse $rate): string => trim("{$rate->carrier} {$rate->serviceName}")
                .($rate->observedService === null ? '' : " (via {$rate->sourceKind()->label()})"))
            ->unique()
            ->implode(', ');
    }

    /**
     * @return array<int, array{source: string, carrier: string, service: string, carrier_service_id: int|null}>
     */
    public function notAllowedForLog(): array
    {
        return $this->notAllowed
            ->map(fn (RateResponse $rate): array => [
                'source' => $rate->sourceKind()->value,
                'carrier' => $rate->observedService->externalCarrierId ?? $rate->carrier,
                'service' => $rate->observedService->externalServiceId ?? $rate->serviceCode,
                'carrier_service_id' => $rate->carrierServiceId,
            ])
            ->values()
            ->all();
    }
}
