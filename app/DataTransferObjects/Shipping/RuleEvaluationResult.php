<?php

namespace App\DataTransferObjects\Shipping;

use App\Enums\PostageSourceKind;

readonly class RuleEvaluationResult
{
    /**
     * A rule may pre-select one thing at most: a direct rate for one service,
     * a blind purchase, or a scope of quoted rates to choose among. The last
     * names no rate — its rates are selected among after quoting.
     *
     * @param  RateResponse|null  $preSelectedRate  A direct service, resolved by its carrier's adapter without rate shopping
     * @param  list<RuleExclusion>  $exclusions
     */
    public function __construct(
        public ?RateResponse $preSelectedRate = null,
        public ?string $preSelectedBlindPurchaseId = null,
        public ?RuleRateScope $preSelectedScope = null,
        public array $exclusions = [],
    ) {
        $preSelections = array_filter([$preSelectedRate, $preSelectedBlindPurchaseId, $preSelectedScope], fn (mixed $value): bool => $value !== null);

        if (count($preSelections) > 1) {
            throw new \InvalidArgumentException('A shipping rule may pre-select one rate, blind purchase or scope, never more than one.');
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

    public function hasPreSelectedScope(): bool
    {
        return $this->preSelectedScope !== null;
    }

    public function shouldFilterRates(): bool
    {
        return $this->exclusions !== [];
    }

    /**
     * Whether an *Exclude* rule matches this rate.
     */
    public function excludes(RateResponse $rate): bool
    {
        foreach ($this->exclusions as $exclusion) {
            if ($exclusion->matchesRate($rate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an *Exclude* rule matches this blind purchase.
     */
    public function excludesBlindOffer(BlindPurchaseOffer $offer): bool
    {
        foreach ($this->exclusions as $exclusion) {
            if ($exclusion->matchesBlindOffer($offer)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this quoted rate is what the rule chose: the pre-selected
     * direct service from a carrier account, never the same service resold
     * through a channel, or a rate within the pre-selected scope.
     */
    public function isPreSelected(RateResponse $rate): bool
    {
        if ($this->preSelectedRate !== null) {
            return $rate->sourceKind() === PostageSourceKind::Direct
                && $rate->carrierServiceId !== null
                && $rate->carrierServiceId === $this->preSelectedRate->carrierServiceId;
        }

        return $this->preSelectedScope?->matches($rate) ?? false;
    }
}
