<?php

namespace App\DataTransferObjects\Shipping;

readonly class RuleEvaluationResult
{
    /**
     * A rule may pre-select one thing at most: a blind purchase, or a scope
     * of quoted rates to choose among. A scope names no rate — its rates are
     * selected among after quoting, a direct service's included
     * (`project-review/18`).
     *
     * @param  list<RuleExclusion>  $exclusions
     */
    public function __construct(
        public ?string $preSelectedBlindPurchaseId = null,
        public ?RuleRateScope $preSelectedScope = null,
        public array $exclusions = [],
    ) {
        if ($preSelectedBlindPurchaseId !== null && $preSelectedScope !== null) {
            throw new \InvalidArgumentException('A shipping rule may pre-select one blind purchase or scope, never both.');
        }
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
     * Whether this quoted rate is what the rule chose: a rate within the
     * pre-selected scope. A *Direct* rule's scope takes the service from a
     * carrier account, never the same service resold through a channel.
     */
    public function isPreSelected(RateResponse $rate): bool
    {
        return $this->preSelectedScope?->matches($rate) ?? false;
    }
}
