<?php

namespace App\Services\Validation;

use App\Contracts\AddressValidationInterface;
use App\Models\Shipment;

class FakeAddressValidator implements AddressValidationInterface
{
    public function supports(string $country): bool
    {
        return true;
    }

    public function validate(Shipment $shipment): void
    {
        $shipment->update([
            'address_checked' => true,
            'address_deliverability' => 'Yes',
            'address_validation_message' => 'Address confirmed deliverable (fake)',
        ]);
    }
}
