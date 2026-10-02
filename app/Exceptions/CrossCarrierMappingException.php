<?php

namespace App\Exceptions;

use LogicException;

/**
 * A mapping names the same service under another name (ADR-0006 decision 2),
 * so it cannot move an observed service onto a different carrier: the carrier
 * of record always names who physically moves the parcel (ADR-0002 decision 1).
 */
class CrossCarrierMappingException extends LogicException
{
    public static function between(string $observedCarrier, string $catalogCarrier): self
    {
        return new self("{$observedCarrier} carries this service, so it cannot be mapped onto a {$catalogCarrier} service. Choose one of {$observedCarrier}'s services, or author one.");
    }
}
