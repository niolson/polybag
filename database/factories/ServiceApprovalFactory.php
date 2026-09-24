<?php

namespace Database\Factories;

use App\Enums\AmazonChannelType;
use App\Enums\ApprovalEffect;
use App\Enums\SourceEnvironment;
use App\Models\Client;
use App\Models\ObservedService;
use App\Models\ServiceApproval;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceApproval>
 */
class ServiceApprovalFactory extends Factory
{
    protected $model = ServiceApproval::class;

    public function definition(): array
    {
        return [
            'source' => 'amazon',
            'environment' => SourceEnvironment::Production,
            'channel_type' => AmazonChannelType::Amazon,
            'external_carrier_id' => 'ONTRAC',
            'external_service_id' => 'ONTRAC_MFN_GROUND',
            'effect' => ApprovalEffect::Allow,
            'client_id' => Client::factory(),
            'approved_by_user_id' => User::factory(),
            // Derived from the user above rather than invented beside it. The
            // two columns are a foreign key and a snapshot of the same person
            // (see the migration), so a factory that let them disagree would
            // build a row the gate cannot produce and quietly weaken every test
            // that reads either one.
            //
            // Null is the one case where they legitimately part company: the
            // account was deleted and only the snapshot is left. That is what
            // {@see formerApprover()} is for.
            'approved_by_name' => fn (array $attributes): string => $attributes['approved_by_user_id'] === null
                ? fake()->name()
                : User::findOrFail($attributes['approved_by_user_id'])->name,
            'approved_at' => now(),
        ];
    }

    /**
     * Approved by a particular person, with both columns saying so.
     */
    public function approvedBy(User $user): static
    {
        return $this->state(fn (): array => [
            'approved_by_user_id' => $user->getKey(),
            'approved_by_name' => $user->name,
        ]);
    }

    /**
     * Approved by someone whose account has since been deleted — the foreign
     * key gone, the attribution still on the row.
     */
    public function formerApprover(string $name = 'Dana Reyes'): static
    {
        return $this->state(fn (): array => [
            'approved_by_user_id' => null,
            'approved_by_name' => $name,
        ]);
    }

    /**
     * Approve the service an observation names, in the world it was seen in.
     */
    public function forObservation(ObservedService $observation): static
    {
        return $this->state(fn (): array => [
            'source' => $observation->source,
            'environment' => $observation->environment,
            'external_carrier_id' => $observation->external_carrier_id,
            'external_service_id' => $observation->external_service_id,
        ]);
    }

    /**
     * Every service of one carrier, including ones first seen later.
     */
    public function wholeCarrier(string $externalCarrierId = 'ONTRAC'): static
    {
        return $this->state(fn (): array => [
            'external_carrier_id' => $externalCarrierId,
            'external_service_id' => ServiceApproval::WILDCARD,
        ]);
    }

    /**
     * Everything the source offers, including services first seen later.
     */
    public function everything(): static
    {
        return $this->state(fn (): array => [
            'external_carrier_id' => ServiceApproval::WILDCARD,
            'external_service_id' => ServiceApproval::WILDCARD,
        ]);
    }

    /**
     * An exception rather than an approval.
     */
    public function exception(): static
    {
        return $this->state(fn (): array => [
            'effect' => ApprovalEffect::Deny,
        ]);
    }

    /**
     * For orders from other channels, sold Amazon Shipping off Amazon.
     */
    public function offAmazon(): static
    {
        return $this->state(fn (): array => [
            'channel_type' => AmazonChannelType::External,
        ]);
    }

    public function sandbox(): static
    {
        return $this->state(fn (): array => [
            'environment' => SourceEnvironment::Sandbox,
        ]);
    }
}
