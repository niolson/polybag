<?php

namespace App\Services;

use App\Contracts\AddressValidationInterface;
use App\DataTransferObjects\AddressValidationResult;
use App\Enums\AddressValidationOutcome;
use App\Enums\Deliverability;
use App\Enums\ValidationTrigger;
use App\Events\AddressValidationFailed;
use App\Models\AddressValidationAnswer;
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
     *
     * Every validator that answers is logged as an AddressValidationAnswer,
     * and the one that settles the address becomes `validation_source`.
     */
    public function validate(Shipment $shipment, ValidationTrigger $trigger = ValidationTrigger::Manual): AddressValidationOutcome
    {
        $country = $shipment->country ?? 'US';
        $outcome = AddressValidationOutcome::Unavailable;

        foreach ($this->validators as $validator) {
            if (! $validator->supports($country)) {
                continue;
            }

            $result = $validator->validate($shipment);

            if (! $result->outcome->answered()) {
                continue;
            }

            $outcome = $result->outcome;
            $this->recordAnswer($shipment, $validator, $result, $trigger);

            if ($outcome === AddressValidationOutcome::Settled) {
                $shipment->validation_source = $validator->validator();
                break;
            }
        }

        if ($outcome->answered()) {
            // Every validator that could answer did, and none settled it. A
            // validator's own inconclusive reading is provisional; the chain's
            // is that no one could confirm or reject the address. It also
            // replaces an earlier settled result, so neither that result's
            // checked flag nor its correction may survive to rate or label.
            if ($outcome === AddressValidationOutcome::Inconclusive) {
                $shipment->forceFill([
                    'checked' => false,
                    'deliverability' => Deliverability::Unverified,
                    'validation_source' => null,
                    ...Shipment::NO_VALIDATED_ADDRESS,
                ]);
            }

            $shipment->validation_attempted_at = now();
            $shipment->save();
        }

        // Dispatched once the whole fallback chain has had its turn, so an
        // early validator's inconclusive "no" (still open to fallback) can't
        // produce a failure log a later validator then contradicts.
        if ($shipment->deliverability === Deliverability::No) {
            AddressValidationFailed::dispatch($shipment, $shipment->validation_message ?? 'Address validation failed');
        } elseif ($shipment->deliverability === Deliverability::Unverified) {
            AddressValidationFailed::dispatch($shipment, $this->unverifiedReason($shipment));
        }

        return $outcome;
    }

    /**
     * A settled answer's deliverability is what the validator just wrote to
     * the Shipment. An inconclusive one has none: the provisional `no` some
     * validators write before falling through is not their verdict.
     */
    private function recordAnswer(
        Shipment $shipment,
        AddressValidationInterface $validator,
        AddressValidationResult $result,
        ValidationTrigger $trigger,
    ): void {
        AddressValidationAnswer::create([
            'shipment_id' => $shipment->id,
            'validator' => $validator->validator(),
            'paid' => $validator->validator()->isPaid(),
            'outcome' => $result->outcome,
            'deliverability' => $result->outcome === AddressValidationOutcome::Settled ? $shipment->deliverability : null,
            'reason' => $result->reason,
            'country' => $shipment->country,
            'trigger' => $trigger,
        ]);
    }

    private function unverifiedReason(Shipment $shipment): string
    {
        $reason = 'No validator could confirm or reject the address';

        return filled($shipment->validation_message)
            ? "{$reason}: {$shipment->validation_message}"
            : $reason;
    }
}
