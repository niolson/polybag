<?php

namespace App\Services\Validation;

use App\Contracts\AddressValidationInterface;
use App\DataTransferObjects\AddressValidationResult;
use App\Enums\AddressValidator;
use App\Enums\Deliverability;
use App\Models\Shipment;

class FakeAddressValidator implements AddressValidationInterface
{
    public function validator(): AddressValidator
    {
        return AddressValidator::Fake;
    }

    public function supports(string $country): bool
    {
        return true;
    }

    public function validate(Shipment $shipment): AddressValidationResult
    {
        $shipment->update([
            'checked' => true,
            'deliverability' => Deliverability::Yes->value,
            'validation_message' => 'Address confirmed deliverable (fake)',
        ]);

        return AddressValidationResult::settled();
    }
}
