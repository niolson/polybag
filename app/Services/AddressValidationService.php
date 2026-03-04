<?php

namespace App\Services;

use App\Contracts\AddressValidationInterface;
use App\Models\Shipment;

class AddressValidationService
{
    /**
     * @param  array<AddressValidationInterface>  $validators
     */
    public function __construct(
        private readonly array $validators = [],
    ) {}

    /**
     * Validate the shipment's address by dispatching to the appropriate
     * country-specific validator. Skips gracefully if no validator supports
     * the shipment's country.
     */
    public function validate(Shipment $shipment): void
    {
        $country = $shipment->country ?? 'US';

        foreach ($this->validators as $validator) {
            if ($validator->supports($country)) {
                $validator->validate($shipment);

                return;
            }
        }

        // No validator for this country — skip validation silently
    }
}
