<?php

namespace App\DataTransferObjects\PostageSources;

use App\Enums\AmazonChannelType;
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
 * The channel type is not part of the service's identity — the same service
 * is sold for Amazon orders and for orders from other channels, under one name
 * — but it is part of what an approval covers, so it travels here too.
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
        public AmazonChannelType $channelType,
        public string $externalCarrierId,
        public string $externalServiceId,
    ) {}

    public static function fromObservation(ObservedService $observation, AmazonChannelType $channelType): self
    {
        return new self(
            source: $observation->source,
            environment: $observation->environment,
            channelType: $channelType,
            externalCarrierId: $observation->external_carrier_id,
            externalServiceId: $observation->external_service_id,
        );
    }

    /**
     * The full scope an approval covers, as a single comparable string.
     *
     * `ObservedService::serviceKey()` plus the environment and channel type,
     * because an approval is scoped to both — see
     * {@see ServiceApproval::scopeInWorld()}. Comparing on the service key
     * alone would treat a sandbox offer and a production one as the same
     * service, which is the one collapse ADR-0003 decision 3 exists to prevent.
     */
    public function approvalKey(): string
    {
        return implode('|', [
            $this->environment->value,
            $this->channelType->value,
            ObservedService::serviceKey($this->source, $this->externalCarrierId, $this->externalServiceId),
        ]);
    }

    /**
     * @return array{source: string, environment: string, channelType: string, externalCarrierId: string, externalServiceId: string}
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'environment' => $this->environment->value,
            'channelType' => $this->channelType->value,
            'externalCarrierId' => $this->externalCarrierId,
            'externalServiceId' => $this->externalServiceId,
        ];
    }

    /**
     * A rate serialized before the channel type existed has none, and was an
     * Amazon order's: nothing sold off Amazon had been quoted in production.
     * Only the attended Ship page reads rates back this way, and it does not
     * ask about approval.
     *
     * @param  array{source: string, environment: string, channelType?: string, externalCarrierId: string, externalServiceId: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            source: $data['source'],
            environment: SourceEnvironment::from($data['environment']),
            channelType: AmazonChannelType::from($data['channelType'] ?? AmazonChannelType::Amazon->value),
            externalCarrierId: $data['externalCarrierId'],
            externalServiceId: $data['externalServiceId'],
        );
    }
}
