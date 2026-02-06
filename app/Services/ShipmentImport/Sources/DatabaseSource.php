<?php

namespace App\Services\ShipmentImport\Sources;

use App\Contracts\ExportDestinationInterface;
use App\Contracts\ImportSourceInterface;
use App\Services\ShipmentImport\FieldMapper;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DatabaseSource implements ExportDestinationInterface, ImportSourceInterface
{
    private array $config;

    private FieldMapper $fieldMapper;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->fieldMapper = new FieldMapper($config['field_mapping'] ?? []);
    }

    /**
     * Static factory method
     */
    public static function make(string $sourceName = 'database'): self
    {
        $config = config("shipment-import.sources.{$sourceName}");

        if (! $config) {
            throw new InvalidArgumentException("Import source '{$sourceName}' is not configured.");
        }

        return new self($config);
    }

    public function getSourceName(): string
    {
        return 'database';
    }

    public function validateConfiguration(): void
    {
        $connection = $this->config['connection'] ?? null;

        if (! $connection) {
            throw new InvalidArgumentException('Database connection is not configured.');
        }

        // Test connection
        try {
            DB::connection($connection)->getPdo();
        } catch (\Exception $e) {
            throw new InvalidArgumentException(
                "Cannot connect to database '{$connection}': ".$e->getMessage()
            );
        }
    }

    public function fetchShipments(): Collection
    {
        $connection = $this->config['connection'];

        // Use custom query if provided
        if (! empty($this->config['shipments_query'])) {
            $results = DB::connection($connection)
                ->select($this->config['shipments_query']);
        } else {
            $query = DB::connection($connection)
                ->table($this->config['shipments_table']);

            // Apply filters
            foreach ($this->config['filters'] ?? [] as $field => $values) {
                if (is_array($values)) {
                    $query->whereIn($field, $values);
                } else {
                    $query->where($field, $values);
                }
            }

            $results = $query->get();
        }

        // Map external fields to internal fields
        return collect($results)->map(function ($row) {
            return $this->fieldMapper->mapShipment($row);
        });
    }

    public function fetchShipmentItems(string $shipmentReference): Collection
    {
        $connection = $this->config['connection'];

        // Use custom query if provided
        if (! empty($this->config['shipment_items_query'])) {
            $results = DB::connection($connection)
                ->select($this->config['shipment_items_query'], [
                    'shipment_reference' => $shipmentReference,
                ]);
        } else {
            // Default: lookup by shipment_id field matching the reference
            $results = DB::connection($connection)
                ->table($this->config['shipment_items_table'])
                ->where('shipment_id', $shipmentReference)
                ->get();
        }

        return collect($results)->map(function ($row) {
            return $this->fieldMapper->mapShipmentItem($row);
        });
    }

    public function getFieldMapping(): array
    {
        return $this->config['field_mapping'] ?? [];
    }

    public function markExported(string $shipmentReference): void
    {
        $markExported = $this->config['mark_exported'] ?? [];

        if (empty($markExported['enabled']) || empty($markExported['query'])) {
            return;
        }

        DB::connection($this->config['connection'])
            ->statement($markExported['query'], [
                'shipment_reference' => $shipmentReference,
            ]);
    }

    public function getDestinationName(): string
    {
        return 'database';
    }

    public function exportPackage(array $data): void
    {
        $exportConfig = $this->config['export'] ?? [];

        if (empty($exportConfig['query'])) {
            throw new InvalidArgumentException('Export query is not configured for database source.');
        }

        $query = $exportConfig['query'];

        // Only pass parameters that the query actually references,
        // so the field_mapping can be a superset of what the query needs.
        preg_match_all('/:(\w+)/', $query, $matches);
        $queryParams = array_flip($matches[1]);
        $filteredData = array_intersect_key($data, $queryParams);

        DB::connection($this->config['connection'])
            ->statement($query, $filteredData);
    }

    public function validateExportConfiguration(): void
    {
        $exportConfig = $this->config['export'] ?? [];

        if (empty($exportConfig['enabled'])) {
            throw new InvalidArgumentException('Export is not enabled for database source.');
        }

        if (empty($exportConfig['query'])) {
            throw new InvalidArgumentException('Export query is not configured for database source.');
        }

        // Test connection
        $connection = $this->config['connection'] ?? null;

        if (! $connection) {
            throw new InvalidArgumentException('Database connection is not configured.');
        }

        try {
            DB::connection($connection)->getPdo();
        } catch (\Exception $e) {
            throw new InvalidArgumentException(
                "Cannot connect to database '{$connection}': ".$e->getMessage()
            );
        }
    }
}
