<?php

namespace App\Services\ShipmentImport;

class PreparedShipmentRow
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $errors
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        public readonly array $attributes = [],
        public readonly array $errors = [],
        public readonly array $warnings = [],
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
