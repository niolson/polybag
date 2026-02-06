<?php

namespace App\Exceptions;

use Exception;

class NoActiveCarrierServicesException extends Exception
{
    public function __construct(string $shippingMethodName)
    {
        parent::__construct(
            "No active carrier services available for shipping method '{$shippingMethodName}'. Please check that at least one carrier and carrier service is enabled."
        );
    }
}
