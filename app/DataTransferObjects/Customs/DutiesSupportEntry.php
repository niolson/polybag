<?php

namespace App\DataTransferObjects\Customs;

use App\Enums\DutiesSupport;
use App\Enums\TaxRegistrationRegime;
use Carbon\CarbonImmutable;

/**
 * One carrier's duties support for one destination, read from
 * `resources/data/customs/duties-support.json`.
 *
 * @param  string  $authority  Who the carrier's rule comes from, as a reason names it: `IMM` for USPS
 * @param  TaxRegistrationRegime|null  $registration  The declared registration whose `with_registration` override produced this entry; null for the entry's own support
 * @param  bool  $isDefault  The carrier's entry for countries it does not list, rather than one for this country
 */
readonly class DutiesSupportEntry
{
    public function __construct(
        public string $carrier,
        public string $country,
        public DutiesSupport $support,
        public string $source,
        public CarbonImmutable $checked,
        public string $authority,
        public bool $isDefault = false,
        public ?CarbonImmutable $effectiveFrom = null,
        public ?TaxRegistrationRegime $registration = null,
    ) {}
}
