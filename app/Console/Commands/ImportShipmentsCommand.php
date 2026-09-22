<?php

namespace App\Console\Commands;

use App\Models\DataSource;
use App\Services\ShipmentImport\DataSourceFactory;
use App\Services\ShipmentImport\ShipmentImportService;
use Illuminate\Console\Command;

class ImportShipmentsCommand extends Command
{
    protected $signature = 'shipments:import
                            {--source-id= : Run a single import source by ID}
                            {--all : Run every active DataSource record with import turned on}
                            {--dry-run : Preview what would be imported without making changes}
                            {--validate-only : Only validate the source configuration}';

    protected $description = 'Import shipments from an external source';

    public function handle(): int
    {
        if ($this->option('source-id')) {
            return $this->runById((int) $this->option('source-id'));
        }

        if ($this->option('all')) {
            return $this->runAll();
        }

        $this->error('Specify --source-id=N or --all.');

        return Command::FAILURE;
    }

    private function runById(int $id): int
    {
        $record = DataSource::find($id);

        if (! $record) {
            $this->error("Import source #{$id} not found.");

            return Command::FAILURE;
        }

        if (! $record->active) {
            $this->warn("Import source '{$record->name}' is inactive.");

            return Command::SUCCESS;
        }

        if (! $record->import_enabled) {
            $this->warn("Import is turned off for connection '{$record->name}'.");

            return Command::SUCCESS;
        }

        return $this->runRecord($record);
    }

    private function runAll(): int
    {
        $sources = DataSource::importing()->get();

        if ($sources->isEmpty()) {
            $this->warn('No active import sources found.');

            return Command::SUCCESS;
        }

        $this->info("Running {$sources->count()} active import source(s)...");

        $overallFailure = false;

        foreach ($sources as $record) {
            $this->newLine();
            $this->info("▶ Source: {$record->name}".($record->client ? " (client: {$record->client->name})" : ''));

            $exitCode = $this->runRecord($record);
            if ($exitCode !== Command::SUCCESS) {
                $overallFailure = true;
            }
        }

        return $overallFailure ? Command::FAILURE : Command::SUCCESS;
    }

    private function runRecord(DataSource $record): int
    {
        if ($this->option('validate-only')) {
            return $this->validateRecord($record);
        }

        if ($this->option('dry-run')) {
            return $this->dryRunRecord($record);
        }

        $service = ShipmentImportService::forRecord($record);
        $result = $service->import();

        $this->table(
            ['Metric', 'Count'],
            [
                ['Shipments Created', $result->shipmentsCreated],
                ['Shipments Updated', $result->shipmentsUpdated],
                ['Shipments Skipped', $result->shipmentsSkipped],
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

    private function validateRecord(DataSource $record): int
    {
        $this->info("Validating source '{$record->name}'...");

        try {
            $source = app(DataSourceFactory::class)->make($record);
            $source->validateConfiguration();
            $this->info('Configuration is valid.');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Validation failed: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }

    private function dryRunRecord(DataSource $record): int
    {
        $this->info("Dry-run for source '{$record->name}'...");

        try {
            $source = app(DataSourceFactory::class)->make($record);
            $source->validateConfiguration();
            $shipments = $source->fetchShipments();

            $this->info("Found {$shipments->count()} shipments to import.");

            if ($shipments->isNotEmpty()) {
                $sample = $shipments->take(5)->map(fn ($s): array => [
                    $s['shipment_reference'] ?? 'N/A',
                    trim(($s['first_name'] ?? '').' '.($s['last_name'] ?? '')),
                    $s['city'] ?? 'N/A',
                    $s['state_or_province'] ?? 'N/A',
                ])->toArray();

                $this->table(['Reference', 'Name', 'City', 'State'], $sample);
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Dry run failed: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }
}
