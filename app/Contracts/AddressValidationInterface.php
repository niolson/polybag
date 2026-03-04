<?php

namespace App\Contracts;

use App\Models\Shipment;

interface AddressValidationInterface
{
    /**
     * Whether this validator supports the given country code.
     */
    public function supports(string $country): bool;

    /**
     * Validate and update the shipment's address.
     */
    public function validate(Shipment $shipment): void;
}
