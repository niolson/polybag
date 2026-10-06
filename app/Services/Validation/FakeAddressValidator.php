<?php

namespace App\Services\Validation;

use App\Contracts\AddressValidationInterface;
use App\Enums\AddressValidationOutcome;
use App\Enums\Deliverability;
use App\Models\Shipment;

class FakeAddressValidator implements AddressValidationInterface
{
    public function supports(string $country): bool
    {
        return true;
    }

    public function validate(Shipment $shipment): AddressValidationOutcome
    {
        $shipment->update([
            'checked' => true,
            'deliverability' => Deliverability::Yes->value,
            'validation_message' => 'Address confirmed deliverable (fake)',
        ]);

        return AddressValidationOutcome::Settled;
    }
}
