<?php

namespace App\DataTransferObjects\PostageSources;

use App\Enums\ApprovalEffect;
use App\Models\ServiceApproval;
use InvalidArgumentException;

/**
 * One approval or exception, without the scope it is filed under.
 *
 * The source, environment, channel type and client are fixed by whoever holds
 * the rule — {@see ServiceApprovalRules} was read for one of each — so what is left is
 * what it covers and what it does to it.
 */
readonly class ApprovalRule
{
    /**
     * @throws InvalidArgumentException for one service of every carrier
     */
    public function __construct(
        public string $externalCarrierId,
        public string $externalServiceId,
        public ApprovalEffect $effect,
    ) {
        ServiceApproval::assertCoherentScope($externalCarrierId, $externalServiceId);
    }

    public static function fromApproval(ServiceApproval $approval): self
    {
        return new self(
            externalCarrierId: $approval->external_carrier_id,
            externalServiceId: $approval->external_service_id,
            effect: $approval->effect,
        );
    }

    /**
     * Everything the source offers, including services first seen later.
     */
    public static function everything(ApprovalEffect $effect = ApprovalEffect::Allow): self
    {
        return new self(ServiceApproval::WILDCARD, ServiceApproval::WILDCARD, $effect);
    }

    /**
     * Every service of one carrier, including ones first seen later.
     */
    public static function carrier(string $externalCarrierId, ApprovalEffect $effect = ApprovalEffect::Allow): self
    {
        return new self($externalCarrierId, ServiceApproval::WILDCARD, $effect);
    }

    public static function service(string $externalCarrierId, string $externalServiceId, ApprovalEffect $effect = ApprovalEffect::Allow): self
    {
        return new self($externalCarrierId, $externalServiceId, $effect);
    }

    public function covers(string $externalCarrierId, string $externalServiceId): bool
    {
        if ($this->externalCarrierId === ServiceApproval::WILDCARD) {
            return true;
        }

        return $this->externalCarrierId === $externalCarrierId
            && ($this->externalServiceId === ServiceApproval::WILDCARD || $this->externalServiceId === $externalServiceId);
    }

    public function isEverything(): bool
    {
        return $this->externalCarrierId === ServiceApproval::WILDCARD;
    }

    public function isWholeCarrier(): bool
    {
        return ! $this->isEverything() && $this->externalServiceId === ServiceApproval::WILDCARD;
    }

    /**
     * Identity for diffing a wanted set of rules against the stored one.
     */
    public function key(): string
    {
        return implode('|', [$this->effect->value, $this->externalCarrierId, $this->externalServiceId]);
    }
}
