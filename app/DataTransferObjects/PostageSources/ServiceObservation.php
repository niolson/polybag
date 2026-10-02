<?php

namespace App\DataTransferObjects\PostageSources;

/**
 * One service identity as a postage source reported it.
 *
 * Identity only — no price, no promise, nothing about the parcel that provoked
 * it. Eligibility is per-parcel and per-order, so a captured response documents
 * a catalog but never a stable offer set; what survives across quotes is which
 * services exist, which is exactly this.
 *
 * {@see $eligible} is the one judgment carried across, and it is coarse on
 * purpose. Amazon returns `ineligibleRates` entries whose reason `code` is
 * `UNKNOWN` on every single one — the real content sits in prose messages
 * ("Expression 'L * W * H' = 11880 exceeds maximum 2949.67") that nothing
 * should branch on. So we record that the service was offered or was not, and
 * decline to model why.
 */
readonly class ServiceObservation
{
    public function __construct(
        public string $source,
        public string $externalCarrierId,
        public string $externalServiceId,
        public ?string $externalCarrierName = null,
        public ?string $externalServiceName = null,
        public ?string $marketplace = null,
        public bool $eligible = false,
    ) {}
}
