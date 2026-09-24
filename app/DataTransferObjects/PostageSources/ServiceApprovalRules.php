<?php

namespace App\DataTransferObjects\PostageSources;

use App\Enums\ApprovalEffect;
use App\Services\PostageSources\ServiceApprovalGate;
use App\Services\RateSelector;
use Illuminate\Support\Collection;

/**
 * Everything one client has approved and excepted from one source, in one
 * world — read once, then asked about as many services as a quote returned.
 *
 * {@see RateSelector} is on the Ship page's hot path and an Amazon `getRates`
 * returns several offers, so {@see ServiceApprovalGate::rulesFor()} reads the
 * rows in one query and every rate is matched here, in memory.
 *
 * The rule is the one `amazon-buy-shipping/18` settled on: a service is
 * approved when at least one allow covers it and no deny does. A deny always
 * wins, whatever it is more or less specific than.
 */
readonly class ServiceApprovalRules
{
    /**
     * @param  Collection<int, ApprovalRule>  $rules
     */
    public function __construct(public Collection $rules) {}

    /**
     * Approves nothing — what a caller that cannot name a client gets.
     */
    public static function none(): self
    {
        return new self(collect());
    }

    public function permits(string $externalCarrierId, string $externalServiceId): bool
    {
        $covering = $this->rules->filter(
            fn (ApprovalRule $rule): bool => $rule->covers($externalCarrierId, $externalServiceId)
        );

        return $covering->contains(fn (ApprovalRule $rule): bool => $rule->effect === ApprovalEffect::Allow)
            && ! $covering->contains(fn (ApprovalRule $rule): bool => $rule->effect === ApprovalEffect::Deny);
    }

    /**
     * @return Collection<int, ApprovalRule>
     */
    public function allows(): Collection
    {
        return $this->rules->filter(fn (ApprovalRule $rule): bool => $rule->effect === ApprovalEffect::Allow)->values();
    }

    /**
     * @return Collection<int, ApprovalRule>
     */
    public function denies(): Collection
    {
        return $this->rules->filter(fn (ApprovalRule $rule): bool => $rule->effect === ApprovalEffect::Deny)->values();
    }

    public function has(ApprovalRule $wanted): bool
    {
        return $this->rules->contains(fn (ApprovalRule $rule): bool => $rule->key() === $wanted->key());
    }
}
