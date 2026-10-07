<?php

namespace Tests\Support;

use App\Contracts\AddressValidationInterface;
use App\Contracts\AddressValidationPlan;
use App\Models\Shipment;

/**
 * The same validators for every Shipment, for tests of the fallback chain
 * itself rather than of routing.
 */
class FixedValidationPlan implements AddressValidationPlan
{
    /**
     * @param  list<AddressValidationInterface>  $validators
     */
    public function __construct(
        private readonly array $validators,
    ) {}

    public function validatorsFor(Shipment $shipment): array
    {
        return $this->validators;
    }
}
