<?php

namespace App\DataTransferObjects\ShipmentImport;

/**
 * What one run's reconciliation did: how many Shipments it closed, and the
 * lookups that failed and left theirs for the next run.
 */
final readonly class SourceClosureResult
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        public int $closed = 0,
        public array $errors = [],
    ) {}
}
