<?php

namespace App\DataTransferObjects\Shipping;

readonly class RuleEvaluationResult
{
    /**
     * @param  array<int, string>  $excludedServiceCodes
     */
    public function __construct(
        public ?RateResponse $preSelectedRate = null,
        public array $excludedServiceCodes = [],
    ) {}

    public function hasPreSelectedRate(): bool
    {
        return $this->preSelectedRate !== null;
    }

    public function shouldFilterRates(): bool
    {
        return $this->excludedServiceCodes !== [];
    }
}
