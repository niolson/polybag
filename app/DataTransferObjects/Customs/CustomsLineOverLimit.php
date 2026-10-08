<?php

namespace App\DataTransferObjects\Customs;

/**
 * A customs line whose item value is over a per-item regime's limit — VOEC's
 * NOK 3,000 or ARN's AUD 1,000 (PRD *Tax registration regimes*).
 *
 * Reported, not acted on: `international-customs-terms/05` decides what such
 * a line blocks.
 *
 * @param  int  $line  The line's position among the Package's customs lines, from zero
 * @param  float  $unitValue  One item's customs value in USD
 * @param  float  $convertedUnitValue  The same in `$currency`, at the ECB rate used
 */
readonly class CustomsLineOverLimit
{
    public function __construct(
        public int $line,
        public string $description,
        public int $quantity,
        public float $unitValue,
        public float $convertedUnitValue,
        public string $currency,
    ) {}
}
