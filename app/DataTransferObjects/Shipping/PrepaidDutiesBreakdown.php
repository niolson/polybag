<?php

namespace App\DataTransferObjects\Shipping;

/**
 * The parts of the duties and taxes a carrier prepaid at purchase, kept on the
 * Label record beside the total in `duties_cost`.
 *
 * The parts can differ from the total by a cent or two of the carrier's own
 * rounding, so the total is never derived from them.
 *
 * @param  float  $packageFee  The carrier's or its provider's per-package fee
 * @param  float  $duty  Customs duty, summed over the items
 * @param  float  $tax  VAT or other import tax, summed over the items
 */
readonly class PrepaidDutiesBreakdown
{
    public function __construct(
        public float $packageFee,
        public float $duty,
        public float $tax,
    ) {}
}
