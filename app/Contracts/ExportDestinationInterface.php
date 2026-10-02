<?php

namespace App\Contracts;

interface ExportDestinationInterface
{
    /**
     * Get the destination identifier (e.g., 'database', 'shopify')
     */
    public function getDestinationName(): string;

    /**
     * Export package data to the external destination
     *
     * @param  array<string, mixed>  $data  Mapped field data
     * @return string|null The destination's own ID for the record the export created, when it
     *                     reports one and something here needs it later — Shopify's fulfillment,
     *                     which a void has to cancel. Null for every other destination.
     */
    public function exportPackage(array $data): ?string;

    /**
     * Validate the export configuration
     * Throws exception if invalid
     */
    public function validateExportConfiguration(): void;
}
