<?php

namespace App\Enums;

/**
 * Why a validator's answer was inconclusive or `no`, in a fixed vocabulary
 * so answers can be counted across validators. A settled `yes`, `verified`
 * or `partial` answer has no reason.
 */
enum ValidationReason: string
{
    /** Inconclusive: the validator found no address matching the one sent. */
    case NoMatch = 'no_match';

    /** Inconclusive: several addresses matched and none was chosen. */
    case MultipleCandidates = 'multiple_candidates';

    /** Inconclusive: the address matched, but not to a delivery point. */
    case NotDeliveryPoint = 'not_delivery_point';

    /** Inconclusive: the street matched, but not the house on it. */
    case StreetOnly = 'street_only';

    /** Inconclusive: the match carries a different house number from the one sent. */
    case HouseNumberChanged = 'house_number_changed';

    /** Inconclusive: the validator couldn't confirm the address is complete. */
    case Incomplete = 'incomplete';

    /** Inconclusive: the API returned an error about the address request. */
    case RequestRejected = 'request_rejected';

    /** Inconclusive: the response was in a shape the validator doesn't read. */
    case UnexpectedResponse = 'unexpected_response';

    /** No: the delivery point was not confirmed (DPV `N`, or no DPV data). */
    case DpvNotConfirmed = 'dpv_not_confirmed';

    /** No: the address is on a phantom route USPS doesn't deliver to. */
    case PhantomRoute = 'phantom_route';

    /** No: a part of the address was flagged as suspicious. */
    case SuspiciousComponent = 'suspicious_component';
}
