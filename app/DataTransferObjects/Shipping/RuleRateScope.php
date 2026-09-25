<?php

namespace App\DataTransferObjects\Shipping;

use App\Enums\PostageSourceKind;

/**
 * The quoted rates a *Use* rule chooses among, when it cannot name a single
 * rate before quoting — `carrier-catalog-reset/07`.
 *
 * *Any priced source, USPS Ground Advantage* rate-shops one service across
 * direct and Amazon. *Amazon Buy Shipping, any* selects among whatever Amazon
 * quotes (`amazon-buy-shipping/19`).
 */
readonly class RuleRateScope
{
    /**
     * @param  list<PostageSourceKind>  $kinds  The kinds of source whose rates count, never Shopify
     * @param  int|null  $carrierServiceId  The service, or null for any service
     * @param  bool  $strict  Whether the rule buys from this scope or not at all. A rule naming a channel source is strict; otherwise an empty scope falls through to rate shopping, as a pre-selected direct service does
     */
    public function __construct(
        public array $kinds,
        public ?int $carrierServiceId,
        public bool $strict,
    ) {}

    public function matches(RateResponse $rate): bool
    {
        return in_array($rate->sourceKind(), $this->kinds, true)
            && ($this->carrierServiceId === null || $rate->carrierServiceId === $this->carrierServiceId);
    }

    /**
     * @return array{kinds: list<string>, carrier_service_id: ?int}
     */
    public function toLogContext(): array
    {
        return [
            'kinds' => array_map(fn (PostageSourceKind $kind): string => $kind->value, $this->kinds),
            'carrier_service_id' => $this->carrierServiceId,
        ];
    }
}
