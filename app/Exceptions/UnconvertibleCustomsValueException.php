<?php

namespace App\Exceptions;

/**
 * Thrown when a carrier declares customs values only in US dollars and no
 * stored exchange rate converts the order's currency (`order-currency/02`).
 * Thrown before anything is bought. The message is operator-facing.
 */
class UnconvertibleCustomsValueException extends \Exception
{
    public function __construct(public readonly string $currency)
    {
        parent::__construct("This carrier declares customs values in US dollars, but this order's values are in {$currency} "
            .'and no exchange rate for it is stored. Choose another carrier, correct the shipment\'s currency, '
            .'or wait for the next exchange rate fetch.');
    }
}
