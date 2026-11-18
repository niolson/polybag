<?php

namespace App\Services;

use App\Contracts\AddressValidationInterface;
use App\Contracts\AddressValidationPlan;
use App\DataTransferObjects\AddressValidationResult;
use App\Enums\AddressValidationOutcome;
use App\Enums\AddressValidator;
use App\Enums\Deliverability;
use App\Enums\ValidationTrigger;
use App\Events\AddressValidationFailed;
use App\Jobs\ShadowValidateAddress;
use App\Models\AddressValidationAnswer;
use App\Models\Shipment;

class AddressValidationService
{
    public function __construct(
        private readonly AddressValidationPlan $plan,
    ) {}

    /**
     * Validate the shipment's address with the validators its plan names, in
     * order, skipping any that don't support the shipment's country.
     *
     * Returns Settled when a validator settled the address, Inconclusive when
     * every validator answered and none settled it, Incomplete when some
     * answered without settling it while another could not be reached, and
     * Unavailable when none answered. An attempt is recorded for Settled and
     * Inconclusive only, so the scheduled run stops re-sending an address no
     * validator can settle but comes back to one a validator was down for.
     *
     * A scheduled run resumes the chain: a validator that has already answered
     * inconclusively for the current address is not asked, and paid for,
     * again (`release-polish/34`). Validating by hand asks every validator.
     *
     * Every validator that answers is logged as an AddressValidationAnswer,
     * and the one that settles the address becomes `validation_source`. When
     * that isn't FedEx, FedEx may be asked afterwards as a shadow check.
     */
    public function validate(Shipment $shipment, ValidationTrigger $trigger = ValidationTrigger::Manual): AddressValidationOutcome
    {
        $country = $shipment->country ?? 'US';
        $alreadyAnswered = $trigger === ValidationTrigger::Scheduled ? $this->inconclusiveForCurrentAddress($shipment) : [];
        $inconclusive = false;
        $answeredNow = false;
        $unavailable = false;
        $settledAnswer = null;
        $fedexAnswered = false;

        foreach ($this->plan->validatorsFor($shipment) as $validator) {
            if (! $validator->supports($country)) {
                continue;
            }

            if (in_array($validator->validator(), $alreadyAnswered, true)) {
                $inconclusive = true;
                $fedexAnswered = $fedexAnswered || $validator->validator() === AddressValidator::Fedex;

                continue;
            }

            $result = $validator->validate($shipment);

            if (! $result->outcome->answered()) {
                $unavailable = true;

                continue;
            }

            $answeredNow = true;
            $answer = $this->recordAnswer($shipment, $validator, $result, $trigger);
            $fedexAnswered = $fedexAnswered || $validator->validator() === AddressValidator::Fedex;

            if ($result->outcome === AddressValidationOutcome::Settled) {
                $shipment->validation_source = $validator->validator();
                $settledAnswer = $answer;
                break;
            }

            $inconclusive = true;
        }

        $outcome = match (true) {
            $settledAnswer !== null => AddressValidationOutcome::Settled,
            $unavailable && $answeredNow => AddressValidationOutcome::Incomplete,
            $unavailable => AddressValidationOutcome::Unavailable,
            $inconclusive => AddressValidationOutcome::Inconclusive,
            default => AddressValidationOutcome::Unavailable,
        };

        if ($outcome === AddressValidationOutcome::Incomplete) {
            // Nothing judged the address yet: a validator's provisional `no`
            // before falling through is not a verdict, and an earlier result
            // no longer stands. Unattempted, so the schedule resumes the chain.
            $shipment->forceFill(Shipment::UNVALIDATED)->save();
        } elseif ($outcome->answered()) {
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

        // FedEx is asked afterwards only when it had no say in this run: one
        // that answered inconclusively already left its answer. Manual Ship
        // validates inside a transaction, so the job waits for the answer it
        // shadows to be committed.
        if ($settledAnswer !== null && ! $fedexAnswered && ShadowValidateAddress::shouldRun($shipment, $settledAnswer->validator)) {
            ShadowValidateAddress::dispatch($settledAnswer->id)->afterCommit();
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
    ): AddressValidationAnswer {
        return AddressValidationAnswer::create([
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

    /**
     * The validators that have already answered inconclusively for the
     * Shipment's current address. An address change stamps the answers given
     * for the old one, so they no longer count here.
     *
     * @return list<AddressValidator>
     */
    private function inconclusiveForCurrentAddress(Shipment $shipment): array
    {
        return AddressValidationAnswer::live()
            ->where('shipment_id', $shipment->id)
            ->whereNull('address_changed_at')
            ->where('outcome', AddressValidationOutcome::Inconclusive)
            ->pluck('validator')
            ->unique()
            ->values()
            ->all();
    }

    private function unverifiedReason(Shipment $shipment): string
    {
        $reason = 'No validator could confirm or reject the address';

        return filled($shipment->validation_message)
            ? "{$reason}: {$shipment->validation_message}"
            : $reason;
    }
}
