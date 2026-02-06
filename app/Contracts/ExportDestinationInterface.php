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
     */
    public function exportPackage(array $data): void;

    /**
     * Validate the export configuration
     * Throws exception if invalid
     */
    public function validateExportConfiguration(): void;
}
