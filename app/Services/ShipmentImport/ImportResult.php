<?php

namespace App\Services\ShipmentImport;

class ImportResult
{
    public function __construct(
        public readonly int $shipmentsCreated = 0,
        public readonly int $shipmentsUpdated = 0,
        public readonly int $itemsCreated = 0,
        public readonly int $itemsUpdated = 0,
        public readonly int $productsCreated = 0,
        public readonly int $productsUpdated = 0,
        public readonly int $shipmentsExported = 0,
        public readonly array $errors = [],
        public readonly float $duration = 0.0,
    ) {}

    public function toArray(): array
    {
        return [
            'shipments_created' => $this->shipmentsCreated,
            'shipments_updated' => $this->shipmentsUpdated,
            'items_created' => $this->itemsCreated,
            'items_updated' => $this->itemsUpdated,
            'products_created' => $this->productsCreated,
            'products_updated' => $this->productsUpdated,
            'shipments_exported' => $this->shipmentsExported,
            'errors' => $this->errors,
            'duration' => $this->duration,
        ];
    }

    public function hasErrors(): bool
    {
        return count($this->errors) > 0;
    }

    public function getTotalProcessed(): int
    {
        return $this->shipmentsCreated + $this->shipmentsUpdated;
    }
}
