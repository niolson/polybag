<?php

namespace App\Exceptions;

/**
 * Thrown when a package requires the declared_value special service but no
 * usable value can be derived from the shipment or its items, or converted
 * from the shipment's currency to US dollars. The message is
 * operator-facing — shown on the Ship page instead of rates.
 */
class MissingDeclaredValueException extends \Exception
{
    /**
     * @param  string|null  $unconvertibleCurrency  The shipment's currency, when it has a value that no stored exchange rate converts to US dollars
     */
    public function __construct(public readonly int $packageId, public readonly ?string $unconvertibleCurrency = null)
    {
        parent::__construct($unconvertibleCurrency === null
            ? 'This shipment requires a declared value, but no value is set. '
                .'Edit the shipment (or its items) to set a value, then get rates again.'
            : "This shipment requires a declared value in US dollars, but its value is in {$unconvertibleCurrency} "
                .'and no exchange rate for it is stored. Correct the shipment\'s currency, or wait for the next exchange rate fetch, then get rates again.'
        );
    }
}
