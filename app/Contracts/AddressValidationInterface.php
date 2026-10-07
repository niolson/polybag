<?php

namespace App\Contracts;

use App\DataTransferObjects\AddressValidationResult;
use App\Enums\AddressValidator;
use App\Models\Shipment;

interface AddressValidationInterface
{
    /**
     * Which validator this is, as recorded on its answers.
     */
    public function validator(): AddressValidator;

    /**
     * Whether this validator supports the given country code.
     */
    public function supports(string $country): bool;

    /**
     * Validate and update the shipment's address, reporting whether this
     * validator settled it, answered without settling it, or never ran.
     */
    public function validate(Shipment $shipment): AddressValidationResult;
}
