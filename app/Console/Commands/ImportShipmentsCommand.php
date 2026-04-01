<?php

namespace App\Console\Commands;

use App\Contracts\ImportSourceInterface;
use App\Services\ShipmentImport\ShipmentImportService;
use Illuminate\Console\Command;

class ImportShipmentsCommand extends Command
{
    protected $signature = 'shipments:import
                            {--source= : The import source to use (defaults to config value)}
                            {--dry-run : Preview what would be imported without making changes}
                            {--validate-only : Only validate the source configuration}';

    protected $description = 'Import shipments from an external source';

    public function handle(): int
    {
        $sourceName = $this->option('source') ?? config('shipment-import.default', 'database');

        $this->info("Using import source: {$sourceName}");

        try {
            $source = $this->resolveSource($sourceName);
        } catch (\Exception $e) {
            $this->error("Failed to initialize source: {$e->getMessage()}");

            return Command::FAILURE;
        }

        // Validate-only mode
        if ($this->option('validate-only')) {
            return $this->validateSource($source);
        }

        // Dry-run mode
        if ($this->option('dry-run')) {
            return $this->dryRun($source);
        }

        // Run the import
        $this->info('Starting import...');

        $service = ShipmentImportService::forSource($source);
        $result = $service->import();

        $this->newLine();
        $this->info('Import completed!');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Shipments Created', $result->shipmentsCreated],
                ['Shipments Updated', $result->shipmentsUpdated],
                ['Items Created', $result->itemsCreated],
                ['Items Updated', $result->itemsUpdated],
                ['Products Created', $result->productsCreated],
                ['Products Updated', $result->productsUpdated],
                ['Shipments Exported', $result->shipmentsExported],
                ['Duration', round($result->duration, 2).'s'],
            ]
        );

        if ($result->hasErrors()) {
            $this->newLine();
            $this->warn('Errors encountered:');
            foreach ($result->errors as $error) {
                $this->error("  - {$error}");
            }
        }

        return $result->hasErrors() ? Command::FAILURE : Command::SUCCESS;
    }

    private function resolveSource(string $sourceName): ImportSourceInterface
    {
        $config = config("shipment-import.sources.{$sourceName}");

        if (! $config) {
            throw new \InvalidArgumentException(
                "Import source '{$sourceName}' is not configured."
            );
        }

        if (! ($config['enabled'] ?? true)) {
            throw new \InvalidArgumentException(
                "Import source '{$sourceName}' is disabled."
            );
        }

        $driverClass = $config['driver'] ?? null;

        if (! $driverClass || ! class_exists($driverClass)) {
            throw new \InvalidArgumentException(
                "Driver class for source '{$sourceName}' is not valid."
            );
        }

        $config['config_key'] = $sourceName;

        return new $driverClass($config);
    }

    private function validateSource(ImportSourceInterface $source): int
    {
        $this->info('Validating source configuration...');

        try {
            $source->validateConfiguration();
            $this->info('Source configuration is valid!');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Validation failed: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }

    private function dryRun(ImportSourceInterface $source): int
    {
        $this->info('Running in dry-run mode (no changes will be made)...');

        try {
            $source->validateConfiguration();
            $shipments = $source->fetchShipments();

            $this->info("Found {$shipments->count()} shipments to import");

            if ($shipments->isEmpty()) {
                $this->warn('No shipments found.');

                return Command::SUCCESS;
            }

            // Show sample of first 5 shipments
            $this->newLine();
            $this->info('Sample shipments (first 5):');

            $sample = $shipments->take(5)->map(function ($s) {
                return [
                    $s['shipment_reference'] ?? 'N/A',
                    trim(($s['first_name'] ?? '').' '.($s['last_name'] ?? '')),
                    $s['city'] ?? 'N/A',
                    $s['state_or_province'] ?? 'N/A',
                ];
            })->toArray();

            $this->table(
                ['Reference', 'Name', 'City', 'State'],
                $sample
            );

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Dry run failed: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }
}
