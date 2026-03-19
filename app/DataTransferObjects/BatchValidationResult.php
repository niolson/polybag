<?php

namespace App\DataTransferObjects;

use App\Models\Shipment;
use Illuminate\Support\Collection;

readonly class BatchValidationResult
{
    public function __construct(
        /** @var Collection<int, Shipment> */
        public Collection $eligible,
        /** @var Collection<int, array{shipment: Shipment, reason: string}> */
        public Collection $ineligible,
    ) {}

    public function hasIneligible(): bool
    {
        return $this->ineligible->isNotEmpty();
    }

    public function allIneligible(): bool
    {
        return $this->eligible->isEmpty();
    }
}
