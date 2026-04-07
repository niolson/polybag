<?php

namespace App\Exceptions;

use RuntimeException;

class FedexRegistrationMaxRetriesException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('We are unable to process this request. Please try again later or call FedEx Customer Service and ask for technical support.');
    }
}
