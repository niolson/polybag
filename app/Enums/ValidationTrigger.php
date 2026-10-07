<?php

namespace App\Enums;

/**
 * What started a validation run: the scheduled `shipments:validate`, or
 * someone validating by hand from View Shipment or Manual Ship.
 */
enum ValidationTrigger: string
{
    case Scheduled = 'scheduled';
    case Manual = 'manual';
}
