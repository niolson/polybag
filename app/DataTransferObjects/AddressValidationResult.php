<?php

namespace App\DataTransferObjects;

use App\Enums\AddressValidationOutcome;
use App\Enums\ValidationReason;

/**
 * One validator's answer: whether it settled the address, and why not, or
 * why `no`, in the fixed reason vocabulary. The deliverability itself is
 * written to the Shipment by the validator.
 */
readonly class AddressValidationResult
{
    public function __construct(
        public AddressValidationOutcome $outcome,
        public ?ValidationReason $reason = null,
    ) {}

    public static function settled(?ValidationReason $reason = null): self
    {
        return new self(AddressValidationOutcome::Settled, $reason);
    }

    public static function inconclusive(ValidationReason $reason): self
    {
        return new self(AddressValidationOutcome::Inconclusive, $reason);
    }

    public static function unavailable(): self
    {
        return new self(AddressValidationOutcome::Unavailable);
    }
}
