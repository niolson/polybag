<?php

namespace Database\Factories;

use App\Enums\AddressValidationOutcome;
use App\Enums\AddressValidator;
use App\Enums\Deliverability;
use App\Enums\ValidationReason;
use App\Enums\ValidationTrigger;
use App\Models\AddressValidationAnswer;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AddressValidationAnswer>
 */
class AddressValidationAnswerFactory extends Factory
{
    protected $model = AddressValidationAnswer::class;

    public function definition(): array
    {
        return [
            'shipment_id' => Shipment::factory(),
            'validator' => AddressValidator::Usps,
            'paid' => AddressValidator::Usps->isPaid(),
            'outcome' => AddressValidationOutcome::Settled,
            'deliverability' => Deliverability::Yes,
            'reason' => null,
            'country' => 'US',
            'trigger' => ValidationTrigger::Scheduled,
            'created_at' => now(),
        ];
    }

    public function by(AddressValidator $validator): static
    {
        return $this->state(fn (): array => [
            'validator' => $validator,
            'paid' => $validator->isPaid(),
        ]);
    }

    public function inconclusive(ValidationReason $reason = ValidationReason::NoMatch): static
    {
        return $this->state(fn (): array => [
            'outcome' => AddressValidationOutcome::Inconclusive,
            'deliverability' => null,
            'reason' => $reason,
        ]);
    }

    public function manual(): static
    {
        return $this->state(fn (): array => [
            'trigger' => ValidationTrigger::Manual,
        ]);
    }
}
