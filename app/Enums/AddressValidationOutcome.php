<?php

namespace App\Enums;

/**
 * What one address validator did with a Shipment.
 */
enum AddressValidationOutcome: string
{
    /** The validator confirmed or rejected the address; the fallback chain stops. */
    case Settled = 'settled';

    /** The validator answered but couldn't settle the address; the next validator may try. */
    case Inconclusive = 'inconclusive';

    /** The validator never judged the address: outage, rate limit, rejected or missing credentials, not configured. */
    case Unavailable = 'unavailable';

    /**
     * Whether the validator judged the address at all. An attempt is recorded
     * only for an answer, so an unavailable run is retried on the schedule.
     */
    public function answered(): bool
    {
        return $this !== self::Unavailable;
    }
}
