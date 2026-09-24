<?php

namespace App\DataTransferObjects\PostageSources;

use App\Enums\SourceEnvironment;
use App\Models\ObservedService;
use App\Models\ServiceApproval;
use App\Services\PostageSources\ServiceApprovalGate;
use App\Services\RateSelector;

/**
 * Which discovered service a rate is an offer of.
 *
 * The identity {@see ServiceApprovalGate} is keyed on, travelling on the rate
 * so that {@see RateSelector} can ask the approval question without going back
 * to the database for every offer. `ObservedService` is the durable row;
 * {@see ServiceObservation} is one sighting on its way into that row; this is
 * the same identity attached to a *price*, which is the only form the selection
 * paths ever see.
 *
 * A rate that carries none of this is not "unidentified" — it is a rate for an
 * authored `CarrierService`, quoted from a carrier account we hold, which is
 * what every rate was before discovery existed. Approval governs discovered
 * services (ADR-0003 decision 4); it was never a gate on the seeded catalog,
 * and putting one there would mean an install that has approved nothing can no
 * longer buy anything.
 *
 * Marketplace is deliberately absent, matching `service_approvals`: a second
 * marketplace reporting the same service must not read as a different service
 * and switch automation off through nobody's decision.
 */
readonly class ObservedServiceIdentity
{
    public function __construct(
        public string $source,
        public SourceEnvironment $environment,
        public string $externalCarrierId,
        public string $externalServiceId,
    ) {}

    public static function fromObservation(ObservedService $observation): self
    {
        return new self(
            source: $observation->source,
            environment: $observation->environment,
            externalCarrierId: $observation->external_carrier_id,
            externalServiceId: $observation->external_service_id,
        );
    }

    /**
     * The full scope an approval covers, as a single comparable string.
     *
     * `ObservedService::serviceKey()` plus the environment, because an
     * approval is scoped to one environment — see
     * {@see ServiceApproval::scopeInWorld()}. Comparing on the service key
     * alone would treat a sandbox offer and a production one as the same
     * service, which is the one collapse ADR-0003 decision 3 exists to prevent.
     */
    public function approvalKey(): string
    {
        return self::approvalKeyFor(
            $this->environment,
            ObservedService::serviceKey($this->source, $this->externalCarrierId, $this->externalServiceId),
        );
    }

    /**
     * The same key built from a service key, which carries no environment of
     * its own.
     */
    public static function approvalKeyFor(SourceEnvironment $environment, string $serviceKey): string
    {
        return $environment->value.'|'.$serviceKey;
    }

    /**
     * @return array{source: string, environment: string, externalCarrierId: string, externalServiceId: string}
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'environment' => $this->environment->value,
            'externalCarrierId' => $this->externalCarrierId,
            'externalServiceId' => $this->externalServiceId,
        ];
    }

    /**
     * @param  array{source: string, environment: string, externalCarrierId: string, externalServiceId: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            source: $data['source'],
            environment: SourceEnvironment::from($data['environment']),
            externalCarrierId: $data['externalCarrierId'],
            externalServiceId: $data['externalServiceId'],
        );
    }
}
