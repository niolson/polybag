<?php

namespace App\DataTransferObjects\Shipping;

use App\Enums\DeliveryEstimate;

readonly class ClassifiedRate
{
    /**
     * @param  bool  $isOnTime  The rule automation buys by: a rate with no delivery date is not on time when there is a due-by date
     * @param  DeliveryEstimate  $delivery  What the Ship page shows, which tells an unknown delivery date apart from a late one
     */
    public function __construct(
        public RateResponse $rate,
        public bool $isOnTime,
        public DeliveryEstimate $delivery,
    ) {}
}
