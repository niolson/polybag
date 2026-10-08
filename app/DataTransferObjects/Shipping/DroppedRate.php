<?php

namespace App\DataTransferObjects\Shipping;

/**
 * Why rates a carrier quoted were dropped before they were offered, as the
 * Ship page shows it (ADR-0008 decision 4).
 *
 * One per carrier and reason, not one per rate: every service a carrier
 * quoted is dropped for the same reason, and listing each would bury it.
 *
 * @param  string|null  $carrier  The carrier whose rates were dropped; null when the reason drops every rate PolyBag sets terms for, as an unresolved EU duties term does
 * @param  string|null  $fixUrl  Where the fix is made, when there is one place to make it
 * @param  string|null  $fixLabel  The link's text
 */
readonly class DroppedRate
{
    public function __construct(
        public ?string $carrier,
        public string $reason,
        public ?string $fixUrl = null,
        public ?string $fixLabel = null,
    ) {}

    /**
     * @return array{carrier: string|null, reason: string, fixUrl: string|null, fixLabel: string|null}
     */
    public function toArray(): array
    {
        return [
            'carrier' => $this->carrier,
            'reason' => $this->reason,
            'fixUrl' => $this->fixUrl,
            'fixLabel' => $this->fixLabel,
        ];
    }
}
