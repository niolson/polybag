<?php

namespace App\DataTransferObjects\PackageShipping;

use App\DataTransferObjects\Shipping\RateResponse;

readonly class PackageShippingRequest
{
    public function __construct(
        public RateResponse $selectedRate,
        public string $labelFormat = 'pdf',
        public ?int $labelDpi = null,
        public bool $overrideCustomsWeights = false,
        // Whether to pause and prompt the user when customs item weights exceed package weight.
        // Set false for batch/auto-ship flows that have no interactive prompt.
        public bool $requireCustomsWeightOverride = true,
        public ?int $userId = null,
    ) {}
}
