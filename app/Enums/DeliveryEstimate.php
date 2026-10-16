<?php

namespace App\Enums;

use App\DataTransferObjects\Shipping\ClassifiedRate;

/**
 * What a quoted rate's delivery date says about the shipment's due-by date,
 * for the Ship page.
 *
 * Unknown is a rate with no delivery date, or one already past, which no
 * carrier can mean: PolyBag cannot tell whether it arrives in time, so it is
 * shown as neither on time nor late. With no due-by date, a dated rate is on
 * time. Display only: automation decides lateness by
 * {@see ClassifiedRate::$isOnTime}.
 */
enum DeliveryEstimate: string
{
    case OnTime = 'on_time';
    case Late = 'late';
    case Unknown = 'unknown';
}
