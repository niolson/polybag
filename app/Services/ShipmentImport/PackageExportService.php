<?php

namespace App\Services\ShipmentImport;

use App\Contracts\ExportDestinationInterface;
use App\Enums\PackageStatus;
use App\Models\Package;
use Illuminate\Support\Facades\Log;

class PackageExportService
{
    /**
     * Export a package and log any errors without throwing.
     */
    public function tryExportPackage(Package $package): void
    {
        try {
            $result = $this->exportPackage($package);
            if ($result->hasErrors()) {
                logger()->warning('Package export partial failure', [
                    'package_id' => $package->id,
                    'errors' => $result->errors,
                ]);
            }
        } catch (\Exception $e) {
            logger()->error('Package export failed', [
                'package_id' => $package->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Export a shipped package's data to configured external destinations.
     */
    public function exportPackage(Package $package): ExportResult
    {
        $package->loadMissing('shipment.channel');

        $channelName = $package->shipment?->channel?->name;
        $channelMap = config('shipment-import.export_channel_map', []);

        // Resolve which sources to export to
        $sourceNames = $channelMap[$channelName] ?? $channelMap['*'] ?? null;

        if (! $sourceNames) {
            return new ExportResult(success: true);
        }

        $attempted = 0;
        $succeeded = 0;
        $errors = [];

        foreach ($sourceNames as $sourceName) {
            $sourceConfig = config("shipment-import.sources.{$sourceName}");

            if (! $sourceConfig || empty($sourceConfig['export']['enabled'])) {
                continue;
            }

            $attempted++;

            try {
                $destination = $this->resolveDestination($sourceName, $sourceConfig);
                $data = $this->buildExportData($package, $sourceConfig['export']['field_mapping'] ?? []);
                $destination->exportPackage($data);
                $succeeded++;
            } catch (\Exception $e) {
                $errors[] = "{$sourceName}: {$e->getMessage()}";

                $this->log('error', "Export to {$sourceName} failed", [
                    'package_id' => $package->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($attempted > 0 && $succeeded === $attempted) {
            $package->update(['exported' => true]);
        }

        return new ExportResult(
            success: empty($errors),
            destinationsAttempted: $attempted,
            destinationsSucceeded: $succeeded,
            errors: $errors,
        );
    }

    /**
     * Export all shipped but unexported packages.
     *
     * @return array<int, ExportResult> Keyed by package ID
     */
    public function exportUnexported(): array
    {
        $packages = Package::where('status', PackageStatus::Shipped)
            ->where('exported', false)
            ->with('shipment.channel')
            ->get();

        $results = [];

        foreach ($packages as $package) {
            $results[$package->id] = $this->exportPackage($package);
        }

        return $results;
    }

    private function resolveDestination(string $sourceName, array $config): ExportDestinationInterface
    {
        $driverClass = $config['driver'] ?? null;

        if (! $driverClass || ! class_exists($driverClass)) {
            throw new \InvalidArgumentException("Driver class for source '{$sourceName}' is not valid.");
        }

        $driver = new $driverClass($config);

        if (! $driver instanceof ExportDestinationInterface) {
            throw new \InvalidArgumentException("Driver for '{$sourceName}' does not support export.");
        }

        return $driver;
    }

    /**
     * Build the export data array from a package using the configured field mapping.
     *
     * @return array<string, mixed>
     */
    private function buildExportData(Package $package, array $fieldMapping): array
    {
        $available = [
            'tracking_number' => $package->tracking_number,
            'weight' => $package->weight,
            'height' => $package->height,
            'width' => $package->width,
            'length' => $package->length,
            'cost' => $package->cost,
            'carrier' => $package->carrier,
            'service' => $package->service,
            'shipment_reference' => $package->shipment?->shipment_reference,
            'fulfillment_order_id' => $package->shipment?->metadata['shopify_fulfillment_order_ids'][0] ?? null,
            'amazon_order_id' => $package->shipment?->metadata['amazon_order_id'] ?? null,
        ];

        $mapped = [];

        foreach ($fieldMapping as $internalName => $parameterName) {
            if (array_key_exists($internalName, $available)) {
                $mapped[$parameterName] = $available[$internalName];
            }
        }

        return $mapped;
    }

    private function log(string $level, string $message, array $context = []): void
    {
        $channel = config('shipment-import.logging.channel', 'stack');
        Log::channel($channel)->log($level, $message, $context);
    }
}
