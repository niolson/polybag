<?php

namespace App\DataTransferObjects\Shipping;

use App\Models\DataSource;

/**
 * What automation insists on before buying a Label for an Amazon order, as set
 * on the Amazon connection the order came from (`amazon-buy-shipping/16`).
 *
 * Both requirements apply to every rate quoted for the order, direct carriers
 * included: a late delivery counts against the seller's OTDR however the Label
 * was bought, and only a Buy Shipping offer can be OTDR-protected. Every other
 * order gets {@see none()}, which is automation as it was before: on-time rates
 * first, the cheapest late one when nothing is on time.
 *
 * Only automation reads this. The Ship page lists every rate for a person to
 * choose, marked, whatever the connection requires.
 */
readonly class OfferRequirements
{
    public function __construct(
        public bool $onTime = false,
        public bool $otdrProtection = false,
        public ?string $connectionName = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    /**
     * The requirements for an Amazon order. A null connection — the order's
     * connection was deleted — takes the column defaults: on time, protection
     * not required.
     */
    public static function forAmazonOrder(?DataSource $connection): self
    {
        return new self(
            onTime: $connection->requires_on_time_offers ?? true,
            otdrProtection: $connection->requires_otdr_protected_offers ?? false,
            connectionName: $connection?->name,
        );
    }

    public function requiresAnything(): bool
    {
        return $this->onTime || $this->otdrProtection;
    }

    /**
     * Whether this rate fails the on-time requirement. Checked against the
     * shipment's deliver-by date by the same rule the rate list is sorted by.
     */
    public function refusesAsLate(bool $isOnTime): bool
    {
        return $this->onTime && ! $isOnTime;
    }

    public function refusesAsUnprotected(RateResponse $rate): bool
    {
        return $this->otdrProtection && ! (BuyShippingBenefits::fromRateMetadata($rate->metadata)?->isOtdrProtected() ?? false);
    }
}
