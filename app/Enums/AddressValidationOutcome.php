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
     * A whole chain's outcome, never one validator's: some validators
     * answered for the first time without settling the address, and another
     * could not be reached. The chain is not exhausted, so no attempt is
     * recorded and the schedule resumes it with the validators still to answer.
     */
    case Incomplete = 'incomplete';

    /**
     * Whether the validator judged the address at all. An attempt is recorded
     * only for an answer, so an unavailable run is retried on the schedule.
     */
    public function answered(): bool
    {
        return $this !== self::Unavailable;
    }
}
