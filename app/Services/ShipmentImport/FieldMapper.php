<?php

namespace App\Services\ShipmentImport;

class FieldMapper
{
    public function __construct(
        private readonly array $mapping
    ) {}

    /**
     * Map external field names to internal field names for shipments
     */
    public function mapShipment(object|array $externalData): array
    {
        return $this->map($externalData, $this->mapping['shipment'] ?? []);
    }

    /**
     * Map external field names to internal field names for shipment items
     */
    public function mapShipmentItem(object|array $externalData): array
    {
        return $this->map($externalData, $this->mapping['shipment_item'] ?? []);
    }

    /**
     * Apply field mapping to transform external data to internal format
     */
    private function map(object|array $data, array $fieldMapping): array
    {
        $data = (array) $data;
        $mapped = [];

        foreach ($fieldMapping as $externalField => $internalField) {
            if (array_key_exists($externalField, $data)) {
                $mapped[$internalField] = $data[$externalField];
            }
        }

        return $mapped;
    }
}
