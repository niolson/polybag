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
        parent::__construct(match ($fedexCode) {
            'PINGENERATION.MAXRETRY.EXCEEDED', 'PINVALIDATION.MAXRETRY.EXCEEDED' => 'FedEx has temporarily blocked more PIN attempts. The retry limit is shared across email, SMS, and phone. Wait before starting over, or contact FedEx Customer Service for technical support.',
            'INVOICEVALIDATION.MAXRETRY.EXCEEDED' => 'FedEx has temporarily blocked more invoice verification attempts. Wait before starting over, or contact FedEx Customer Service for technical support.',
            default => 'FedEx has temporarily blocked more verification attempts. Wait before starting over, or contact FedEx Customer Service for technical support.',
        });
    }
}
