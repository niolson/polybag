<?php

namespace App\Services\ShipmentImport;

class ExportResult
{
    public function __construct(
        public readonly bool $success,
        public readonly int $destinationsAttempted = 0,
        public readonly int $destinationsSucceeded = 0,
        public readonly array $errors = [],
    ) {}

    public function hasErrors(): bool
    {
        return count($this->errors) > 0;
    }
}
