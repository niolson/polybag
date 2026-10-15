<?php

namespace App\Exceptions;

/**
 * Thrown when a package requires the declared_value special service but no
 * usable value can be derived from the shipment or its items. The message is
 * operator-facing — shown on the Ship page instead of rates.
 */
class MissingDeclaredValueException extends \Exception
{
    public function __construct(public readonly int $packageId)
    {
        parent::__construct(
            'This shipment requires a declared value, but no value is set. '
            .'Edit the shipment (or its items) to set a value, then get rates again.'
        );
    }
}
