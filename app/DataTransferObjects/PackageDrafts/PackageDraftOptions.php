<?php

namespace App\DataTransferObjects\PackageDrafts;

/**
 * Options governing draft validation. Defaults are intentionally strict —
 * callers must explicitly opt out (e.g. ManualShip passes false for requireCompletePackedItems).
 */
final readonly class PackageDraftOptions
{
    public function __construct(
        public bool $requireCompletePackedItems = true,
    ) {}
}
