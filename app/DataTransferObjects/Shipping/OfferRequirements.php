<?php

namespace App\DataTransferObjects\Shipping;

use App\Models\Shipment;

/**
 * What automation insists on before buying a Label, as set on the Shipment's
 * shipping method (`amazon-buy-shipping/17`).
 *
 * Both requirements apply to every rate quoted for the order, direct carriers
 * included: the due-by date is the method's speed guarantee whoever sells the
 * Label, and only a Buy Shipping offer can be OTDR-protected. Protection is
 * only ever required of Amazon's own orders, since nothing else counts toward
 * the account's OTDR. A Shipment with no method requires {@see none()}, which
 * is automation as it was before: on-time rates first, the cheapest late one
 * when nothing is on time.
 *
 * Only automation reads this. The Ship page lists every rate for a person to
 * choose, marked, whatever the method requires.
 */
readonly class OfferRequirements
{
    /**
     * @param  bool  $onTime  Refuse a rate that arrives after the due-by date or gives no delivery date
     * @param  bool  $otdrProtection  Refuse a rate Amazon does not mark OTDR-protected
     * @param  bool  $deadlineRequired  With no due-by date, refuse every rate rather than none: an Amazon order has a deadline the order simply failed to tell us
     * @param  string|null  $shippingMethodName  The method that set these, for refusal messages
     */
    public function __construct(
        public bool $onTime = false,
        public bool $otdrProtection = false,
        public bool $deadlineRequired = false,
        public ?string $shippingMethodName = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public static function forShipment(?Shipment $shipment, bool $isAmazonOrder): self
    {
        $method = $shipment?->shippingMethod;

        if ($method === null) {
            return self::none();
        }

        $onTime = $method->excludes_late_rates ?? true;

        return new self(
            onTime: $onTime,
            otdrProtection: $isAmazonOrder && $method->requiresOtdrProtectionFor($shipment),
            deadlineRequired: $onTime && $isAmazonOrder,
            shippingMethodName: $method->name,
        );
    }

    public function requiresAnything(): bool
    {
        return $this->onTime || $this->otdrProtection;
    }

    /**
     * Whether this rate fails the on-time requirement. Checked against the
     * shipment's due-by date by the same rule the rate list is sorted by.
     * With no due-by date nothing can be shown late, so only an order that
     * must have one refuses.
     */
    public function refusesAsLate(bool $isOnTime, bool $hasDeadline): bool
    {
        if (! $this->onTime) {
            return false;
        }

        return $hasDeadline ? ! $isOnTime : $this->deadlineRequired;
    }

    public function refusesAsUnprotected(RateResponse $rate): bool
    {
        return $this->otdrProtection && ! (BuyShippingBenefits::fromRateMetadata($rate->metadata)?->isOtdrProtected() ?? false);
    }
}
