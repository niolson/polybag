<?php

namespace App\DataTransferObjects\Shipping;

readonly class RuleEvaluationResult
{
    /**
     * @param  array<int, string>  $excludedServiceCodes
     * @param  array<int, string>  $excludedBlindPurchaseIds
     */
    public function __construct(
        public ?RateResponse $preSelectedRate = null,
        public ?string $preSelectedBlindPurchaseId = null,
        public array $excludedServiceCodes = [],
        public array $excludedBlindPurchaseIds = [],
    ) {
        if ($preSelectedRate !== null && $preSelectedBlindPurchaseId !== null) {
            throw new \InvalidArgumentException('A shipping rule may pre-select either a rate or a blind purchase, never both.');
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

    public function shouldFilterRates(): bool
    {
        return $this->excludedServiceCodes !== [];
    }
}
