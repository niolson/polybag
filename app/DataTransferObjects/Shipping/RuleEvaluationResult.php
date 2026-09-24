<?php

namespace App\DataTransferObjects\Shipping;

use App\Contracts\DiscoversServices;

readonly class RuleEvaluationResult
{
    /**
     * A rule may pre-select one thing at most: a rate for an authored service,
     * a blind purchase, or a source whose services are discovered per quote
     * ({@see DiscoversServices}). The last names no rate — the source's offers
     * are selected among after quoting, each under its own approval.
     *
     * Sources are the `observed_services.source` keys their offers carry.
     *
     * @param  array<int, string>  $excludedServiceCodes
     * @param  array<int, string>  $excludedBlindPurchaseIds
     * @param  array<int, string>  $excludedSources
     */
    public function __construct(
        public ?RateResponse $preSelectedRate = null,
        public ?string $preSelectedBlindPurchaseId = null,
        public array $excludedServiceCodes = [],
        public array $excludedBlindPurchaseIds = [],
        public ?string $preSelectedSource = null,
        public array $excludedSources = [],
    ) {
        $preSelections = array_filter([$preSelectedRate, $preSelectedBlindPurchaseId, $preSelectedSource], fn (mixed $value): bool => $value !== null);

        if (count($preSelections) > 1) {
            throw new \InvalidArgumentException('A shipping rule may pre-select one rate, blind purchase or source, never more than one.');
        }
    }

    public function hasPreSelectedRate(): bool
    {
        return $this->preSelectedRate !== null;
    }

    public function hasPreSelectedBlindPurchase(): bool
    {
        return $this->preSelectedBlindPurchaseId !== null;
    }

    public function hasPreSelectedSource(): bool
    {
        return $this->preSelectedSource !== null;
    }

    public function shouldFilterRates(): bool
    {
        return $this->excludedServiceCodes !== [] || $this->excludedSources !== [];
    }

    /**
     * Whether a rule excludes this rate, by its service code or by the
     * discovering source that quoted it.
     */
    public function excludes(RateResponse $rate): bool
    {
        return in_array($rate->serviceCode, $this->excludedServiceCodes, true)
            || ($rate->observedService !== null && in_array($rate->observedService->source, $this->excludedSources, true));
    }

    /**
     * Whether this rate is an offer from the pre-selected discovering source.
     */
    public function isFromPreSelectedSource(RateResponse $rate): bool
    {
        return $this->preSelectedSource !== null
            && $rate->observedService?->source === $this->preSelectedSource;
    }
}
