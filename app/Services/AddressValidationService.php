<?php

namespace App\Services;

use App\Contracts\AddressValidationInterface;
use App\Enums\AddressValidationOutcome;
use App\Enums\Deliverability;
use App\Events\AddressValidationFailed;
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
     *
     * Returns Settled when a validator settled the address, Inconclusive when
     * validators answered but none settled it, and Unavailable when none ran.
     * An attempt is recorded for the first two, so the scheduled run stops
     * re-sending an address no validator can settle.
     */
    public function validate(Shipment $shipment): AddressValidationOutcome
    {
        $country = $shipment->country ?? 'US';
        $outcome = AddressValidationOutcome::Unavailable;

        foreach ($this->validators as $validator) {
            if (! $validator->supports($country)) {
                continue;
            }

            $result = $validator->validate($shipment);

            if ($result->answered()) {
                $outcome = $result;
            }

            if ($result === AddressValidationOutcome::Settled) {
                break;
            }
        }

        if ($outcome->answered()) {
            // A re-validation that ends inconclusive replaces an earlier
            // settled result, so the Shipment must not still read as checked.
            if ($outcome === AddressValidationOutcome::Inconclusive) {
                $shipment->checked = false;
            }

            $shipment->validation_attempted_at = now();
            $shipment->save();
        }

        // Dispatched once the whole fallback chain has had its turn, so an
        // early validator's inconclusive "no" (still open to fallback) can't
        // produce a failure log a later validator then contradicts.
        if ($shipment->deliverability === Deliverability::No) {
            AddressValidationFailed::dispatch($shipment, $shipment->validation_message ?? 'Address validation failed');
        }

        return $outcome;
    }
}
