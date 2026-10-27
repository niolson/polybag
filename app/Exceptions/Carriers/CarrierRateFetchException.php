<?php

namespace App\Exceptions\Carriers;

use Throwable;

/**
 * A rate request that failed. Its message is what the packer is shown beside
 * the rates: the carrier's own reason when the adapter could read one, else a
 * generic "Failed to fetch rates from …".
 */
class CarrierRateFetchException extends CarrierException
{
    public function __construct(string $carrier, ?Throwable $previous = null, ?string $reason = null)
    {
        parent::__construct($carrier, $reason ?? "Failed to fetch rates from {$carrier}", $previous);
    }
}
