<?php

namespace App\Console\Commands;

use App\Contracts\ExportDestinationInterface;
use App\Enums\PackageStatus;
use App\Models\Package;
use App\Services\ShipmentImport\PackageExportService;
use Illuminate\Console\Command;

class ExportPackagesCommand extends Command
{
    protected $signature = 'packages:export
                            {--dry-run : Preview what would be exported without making changes}
                            {--validate-only : Only validate the export destination configurations}';

    protected $description = 'Export shipped packages to configured external destinations';

    public function handle(PackageExportService $service): int
    {
        if ($this->option('validate-only')) {
            return $this->validateDestinations();
        }

        if ($this->option('dry-run')) {
            return $this->dryRun();
        }

        $this->info('Exporting shipped packages...');

        $results = $service->exportUnexported();

        if (empty($results)) {
            $this->info('No unexported packages found.');

            return Command::SUCCESS;
        }

        $succeeded = collect($results)->filter(fn ($r) => $r->success)->count();
        $failed = collect($results)->filter(fn ($r) => $r->hasErrors())->count();

        $this->newLine();
        $this->info('Export completed!');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Packages Processed', count($results)],
                ['Succeeded', $succeeded],
                ['Failed', $failed],
            ]
        );

        if ($failed > 0) {
            $this->newLine();
            $this->warn('Errors encountered:');
            foreach ($results as $packageId => $result) {
                foreach ($result->errors as $error) {
                    $this->error("  Package #{$packageId}: {$error}");
                }
            }
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function validateDestinations(): int
    {
        $this->info('Validating export destination configurations...');

        $channelMap = config('shipment-import.export_channel_map', []);

        if (empty($channelMap)) {
            $this->warn('No export channel mappings configured.');

            return Command::SUCCESS;
        }

        $sourceNames = collect($channelMap)->flatten()->unique();
        $hasErrors = false;

        foreach ($sourceNames as $sourceName) {
            $config = config("shipment-import.sources.{$sourceName}");

            if (! $config) {
                $this->error("Source '{$sourceName}' is not configured.");
                $hasErrors = true;

                continue;
            }

            if (empty($config['export']['enabled'])) {
                $this->warn("Source '{$sourceName}' export is disabled.");

                continue;
            }

            $driverClass = $config['driver'] ?? null;

            if (! $driverClass || ! class_exists($driverClass)) {
                $this->error("Driver class for '{$sourceName}' is not valid.");
                $hasErrors = true;

                continue;
            }

            $driver = new $driverClass($config);

            if (! $driver instanceof ExportDestinationInterface) {
                $this->error("Driver for '{$sourceName}' does not support export.");
                $hasErrors = true;

                continue;
            }

            try {
                $driver->validateExportConfiguration();
                $this->info("  {$sourceName}: OK");
            } catch (\Exception $e) {
                $this->error("  {$sourceName}: {$e->getMessage()}");
                $hasErrors = true;
            }
        }

        return $hasErrors ? Command::FAILURE : Command::SUCCESS;
    }

    private function dryRun(): int
    {
        $this->info('Running in dry-run mode (no changes will be made)...');

        $packages = Package::where('status', PackageStatus::Shipped)
            ->where('exported', false)
            ->with('shipment.channel')
            ->get();

        if ($packages->isEmpty()) {
            $this->info('No unexported packages found.');

            return Command::SUCCESS;
        }

        $this->info("Found {$packages->count()} packages to export:");
        $this->newLine();

        $rows = $packages->map(fn (Package $p) => [
            $p->id,
            $p->tracking_number ?? 'N/A',
            $p->carrier ?? 'N/A',
            $p->shipment?->shipment_reference ?? 'N/A',
            $p->shipment?->channel?->name ?? 'No Channel',
        ])->toArray();

        $this->table(
            ['Package ID', 'Tracking', 'Carrier', 'Shipment Ref', 'Channel'],
            $rows
        );

        return Command::SUCCESS;
    }
}
