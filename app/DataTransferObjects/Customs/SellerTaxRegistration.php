<?php

namespace App\DataTransferObjects\Customs;

use App\Enums\CustomsTermsOrigin;
use App\Enums\TaxRegistrationRegime;

/**
 * A seller tax registration chosen for a Shipment, and whose it is: the
 * order's, which replaces the client's, or the client's (ADR-0008 decision 3).
 */
readonly class SellerTaxRegistration
{
    public function __construct(
        public TaxRegistrationRegime $regime,
        public string $number,
        public CustomsTermsOrigin $origin,
    ) {}
}
