<?php

namespace App\DataTransferObjects\PackSlips;

/**
 * What the server drew: for each Shipment, the items version read before its items
 * were loaded. Redeeming it is the only way a pack slip becomes printed, so the
 * browser can only acknowledge Shipments the server actually rendered.
 */
final readonly class PackSlipReceipt
{
    /**
     * @param  array<int, int>  $itemsVersions  keyed by Shipment ID
     * @param  string  $issuedAt  `Y-m-d H:i:s.u`, UTC
     */
    public function __construct(
        public int $userId,
        public string $issuedAt,
        public int $expiresAt,
        public array $itemsVersions,
    ) {}

    public function count(): int
    {
        return count($this->itemsVersions);
    }
}
