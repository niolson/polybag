<?php

namespace App\Contracts;

use App\Enums\AddressValidationOutcome;
use App\Models\Shipment;

interface AddressValidationInterface
{
    /**
     * Whether this validator supports the given country code.
     */
    public function supports(string $country): bool;

    /**
     * Validate and update the shipment's address, reporting whether this
     * validator settled it, answered without settling it, or never ran.
     */
    public function validate(Shipment $shipment): AddressValidationOutcome;
}
