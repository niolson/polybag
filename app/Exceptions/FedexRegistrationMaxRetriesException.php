<?php

namespace App\Exceptions;

use RuntimeException;

class FedexRegistrationMaxRetriesException extends RuntimeException
{
    /**
     * @param  string[]  $lockedMethods
     */
    public function __construct(
        public readonly ?string $fedexCode = null,
        public readonly array $lockedMethods = [],
    ) {
        parent::__construct('We are unable to process this request. Please try again later or call FedEx Customer Service and ask for technical support.');
    }
}
