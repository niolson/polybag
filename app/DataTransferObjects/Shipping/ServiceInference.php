<?php

namespace App\DataTransferObjects\Shipping;

/**
 * What a run of the inference ladder concluded about a package's service.
 *
 * A conclusive result names the service and the rung that produced it. An
 * inconclusive one names only why it stopped, which is what the coverage
 * measurement in ADR-0003 is counted from.
 */
readonly class ServiceInference
{
    private function __construct(
        public ?string $service,
        public ?string $method,
        public string $reason,
        public bool $contradicted = false,
    ) {}

    public static function resolved(string $service, string $method): self
    {
        return new self($service, $method, 'resolved');
    }

    /**
     * Nothing conclusive. The reason is diagnostic, never written to the package.
     */
    public static function inconclusive(string $reason): self
    {
        return new self(null, null, $reason);
    }

    /**
     * Two rungs resolved and named different services.
     *
     * Inconclusive, but a stronger kind: the ladder found evidence *against* a
     * value rather than merely none for one. That is the one inconclusive result
     * that can withdraw an inference already on a package -- the others cannot,
     * because a rung that no longer runs (a purged label) says nothing about
     * whether what it once read was right.
     */
    public static function contradicted(string $reason): self
    {
        return new self(null, null, $reason, contradicted: true);
    }

    public function isResolved(): bool
    {
        return $this->service !== null;
    }

    public function isContradicted(): bool
    {
        return $this->contradicted;
    }
}
