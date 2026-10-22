<?php

namespace App\Services\ShipmentImport;

class ExportResult
{
    public function __construct(
        public readonly bool $success,
        public readonly int $destinationsAttempted = 0,
        public readonly int $destinationsSucceeded = 0,
        public readonly int $destinationsSkipped = 0,
        public readonly array $errors = [],
        public readonly array $retryableErrors = [],
        public readonly bool $deferred = false,
    ) {}

    public function hasErrors(): bool
    {
        return count($this->errors) > 0;
    }

    public function shouldRetry(): bool
    {
        return count($this->retryableErrors) > 0;
    }

    public function hasPermanentErrors(): bool
    {
        return count($this->errors) > count($this->retryableErrors);
    }
}
