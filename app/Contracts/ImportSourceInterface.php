<?php

namespace App\Contracts;

use Illuminate\Support\Collection;

interface ImportSourceInterface
{
    /**
     * Get the source identifier (e.g., 'database', 'shopify', 'amazon')
     */
    public function getSourceName(): string;

    /**
     * Fetch shipments from the external source
     * Returns a collection of normalized shipment data arrays
     */
    public function fetchShipments(): Collection;

    /**
     * Fetch shipment items for a specific shipment reference
     * Returns a collection of normalized item data arrays
     */
    public function fetchShipmentItems(string $shipmentReference): Collection;

    /**
     * Validate the source configuration
     * Throws exception if invalid
     */
    public function validateConfiguration(): void;

    /**
     * Get the field mapping for this source
     */
    public function getFieldMapping(): array;

    /**
     * Mark a shipment as exported on the external source.
     * Called after each successful shipment import when enabled.
     */
    public function markExported(string $shipmentReference): void;
}
