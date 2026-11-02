<?php

namespace App\DataTransferObjects\Customs;

use App\DataTransferObjects\Shipping\CustomsItem;
use App\DataTransferObjects\Shipping\ShipRequest;
use Carbon\CarbonImmutable;

/**
 * The customs lines a carrier declares, and the currency their values are in
 * (`order-currency/02`), as {@see ShipRequest::customsDeclaration()} builds
 * them: in the order's own currency when the carrier takes it, else converted
 * to US dollars at the ECB rate pinned on the Shipment, when `convertedFrom`
 * and `rateDate` say so.
 */
readonly class CustomsDeclaration
{
    /**
     * @param  list<CustomsItem>  $items
     * @param  string  $currency  ISO 4217
     * @param  string|null  $convertedFrom  The order's currency, when the values were converted out of it
     * @param  CarbonImmutable|null  $rateDate  The ECB day the conversion used
     */
    public function __construct(
        public array $items,
        public string $currency,
        public ?string $convertedFrom = null,
        public ?CarbonImmutable $rateDate = null,
    ) {}

    /**
     * The sum of the line totals, each rounded to the cent as carriers
     * declare them, so the total agrees with the lines.
     */
    public function total(): float
    {
        return array_sum(array_map(
            fn (CustomsItem $item): float => round($item->unitValue * $item->quantity, 2),
            $this->items,
        ));
    }
}
