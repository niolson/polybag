<?php

namespace App\DataTransferObjects\Shipping;

use Saloon\Http\PendingRequest;

readonly class PreparedRateRequest
{
    public function __construct(
        public PendingRequest $pendingRequest,
        public string $carrierName,
    ) {}
}
