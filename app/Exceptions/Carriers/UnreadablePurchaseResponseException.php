<?php

namespace App\Exceptions\Carriers;

use Throwable;

/**
 * A label purchase the carrier accepted with a 2xx whose reply could not be read.
 *
 * A 2xx from a label endpoint means the label exists and is paid for, so this
 * is an unknown outcome, never a refusal: the offer must stay unresolved, like
 * a timeout, so the next attempt asks the carrier (USPS reprint by idempotency
 * key, UPS Label Recovery by reference) rather than buying a second label.
 * Only thrown after the carrier answered 2xx — `project-review/11`.
 */
class UnreadablePurchaseResponseException extends CarrierException
{
    public function __construct(
        string $carrier,
        string $message,
        public readonly ?string $trackingNumber = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($carrier, $message, $previous);
    }
}
