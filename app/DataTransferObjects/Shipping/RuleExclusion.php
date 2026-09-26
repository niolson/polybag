<?php

namespace App\DataTransferObjects\Shipping;

use App\Enums\PostageSourceKind;

/**
 * What one *Exclude* rule matches — `carrier-catalog-reset/07`.
 *
 * Every field it names must match; a null field matches anything. The carrier
 * is the one expected to carry the parcel, so a rule naming OnTrac matches an
 * Amazon offer for an OnTrac service nobody has mapped.
 */
readonly class RuleExclusion
{
    /**
     * @param  PostageSourceKind|null  $kind  The kind of source that sells it, or null for any source
     * @param  int|null  $carrierId  The carrier expected to carry the parcel
     * @param  int|null  $carrierServiceId  The catalog service
     */
    public function __construct(
        public ?PostageSourceKind $kind = null,
        public ?int $carrierId = null,
        public ?int $carrierServiceId = null,
    ) {}

    public function matchesRate(RateResponse $rate): bool
    {
        return ($this->kind === null || $rate->sourceKind() === $this->kind)
            && ($this->carrierId === null || $rate->carrierId === $this->carrierId)
            && ($this->carrierServiceId === null || $rate->carrierServiceId === $this->carrierServiceId);
    }

    /**
     * A blind offer requesting a service matches as a rate for it would.
     * Shopify's own choice names no carrier or service before it is bought, so
     * only a rule naming neither matches it.
     */
    public function matchesBlindOffer(BlindPurchaseOffer $offer): bool
    {
        return ($this->kind === null || $this->kind === PostageSourceKind::Shopify)
            && ($this->carrierId === null || $offer->carrierId === $this->carrierId)
            && ($this->carrierServiceId === null || $offer->carrierServiceId === $this->carrierServiceId);
    }
}
