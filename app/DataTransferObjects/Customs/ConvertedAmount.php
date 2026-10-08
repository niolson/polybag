<?php

namespace App\DataTransferObjects\Customs;

use Carbon\CarbonImmutable;

/**
 * An amount converted at an ECB reference rate, with the day of the rate used.
 *
 * `rateDate` can be earlier than the day asked for: the ECB publishes no rate
 * on weekends and TARGET holidays, and the latest earlier day stands in.
 */
readonly class ConvertedAmount
{
    public function __construct(
        public float $amount,
        public string $currency,
        public CarbonImmutable $rateDate,
    ) {}
}
