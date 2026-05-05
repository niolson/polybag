<?php

namespace App\Console\Commands;

use App\Enums\PackageStatus;
use App\Models\Package;
use App\Models\Shipment;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ArchiveShipments extends Command
{
    protected $signature = 'shipments:archive
        {--days= : Override retention days (default: from settings or 365)}
        {--dry-run : Preview what would be archived without deleting}';

    protected $description = 'Archive old shipped shipments to CSV and remove from active tables';

    private const SHIPMENT_EXPORT_COLUMNS = [
        'id', 'shipment_reference', 'first_name', 'last_name', 'company',
        'address1', 'address2', 'city', 'state_or_province', 'postal_code', 'country',
        'phone', 'phone_e164', 'phone_extension', 'value', 'channel_id', 'shipping_method_id', 'status', 'created_at',
    ];

    private const PACKAGE_EXPORT_COLUMNS = [
        'id', 'shipment_id', 'tracking_number', 'carrier', 'service',
        'weight', 'height', 'width', 'length', 'cost',
        'status', 'shipped_at', 'ship_date', 'shipped_by_user_id', 'location_id',
    ];

    public function handle(SettingsService $settings): int
    {
        $manualOverride = $this->option('days') !== null;

        if (! $manualOverride && ! $settings->get('archiving_enabled', false)) {
            $this->info('Archiving is disabled. Enable it in App Settings or use --days to override.');

            return self::SUCCESS;
        }

        $days = (int) ($this->option('days') ?? $settings->get('archive_retention_days', 365));
        $cutoff = now()->subDays($days);
        $dryRun = $this->option('dry-run');

        $this->info(($dryRun ? '[DRY RUN] ' : '')."Finding shipments fully shipped before {$cutoff->toDateString()}...");

        $shipmentCount = $this->eligibleQuery($cutoff)->count();

        if ($shipmentCount === 0) {
            $this->info('No shipments eligible for archiving.');

            return self::SUCCESS;
        }

        $packageCount = Package::whereIn('shipment_id', $this->eligibleQuery($cutoff)->select('id'))->count();

        $this->info("Found {$shipmentCount} shipments with {$packageCount} packages eligible for archiving.");

        if ($dryRun) {
            $this->info('[DRY RUN] No changes made.');

            return self::SUCCESS;
        }

        // Export to CSV
        $date = now()->format('Y-m-d');
        $this->exportToCsv($cutoff, $date);

        // Delete in chunked batches
        $this->info('Deleting archived records...');
        $totalDeleted = 0;

        do {
            // Grab a small batch of IDs per iteration
            $chunkIds = $this->eligibleQuery($cutoff)->limit(500)->pluck('id');

            if ($chunkIds->isEmpty()) {
                break;
            }

            DB::transaction(function () use ($chunkIds): void {
                $packageIds = Package::whereIn('shipment_id', $chunkIds)->pluck('id');

                if ($packageIds->isNotEmpty()) {
                    DB::table('package_items')->whereIn('package_id', $packageIds)->delete();
                    DB::table('rate_quotes')->whereIn('package_id', $packageIds)->delete();
                    DB::table('label_batch_items')->whereIn('package_id', $packageIds)->update(['package_id' => null]);
                    Package::whereIn('id', $packageIds)->delete();
                }

                DB::table('shipment_items')->whereIn('shipment_id', $chunkIds)->delete();
                Shipment::whereIn('id', $chunkIds)->delete();
            });

            $totalDeleted += $chunkIds->count();
        } while (true);

        $this->info("Archived {$totalDeleted} shipments and {$packageCount} packages to storage/app/archives/.");

        return self::SUCCESS;
    }

    /**
     * Build the base query for eligible shipments: all packages shipped, most recent shipped_at before cutoff.
     */
    private function eligibleQuery(Carbon $cutoff): Builder
    {
        return Shipment::query()
            ->whereHas('packages')
            ->whereDoesntHave('packages', function ($query) use ($cutoff): void {
                $query->where('status', '!=', PackageStatus::Shipped)
                    ->orWhere('shipped_at', '>=', $cutoff);
            });
    }

    private function exportToCsv(Carbon $cutoff, string $date): void
    {
        Storage::makeDirectory('archives');

        // Export shipments
        $shipmentPath = "archives/shipments-{$date}.csv";
        $handle = fopen(Storage::path($shipmentPath), 'w');
        fputcsv($handle, self::SHIPMENT_EXPORT_COLUMNS);

        $this->eligibleQuery($cutoff)
            ->select(self::SHIPMENT_EXPORT_COLUMNS)
            ->orderBy('id')
            ->chunk(1000, function ($shipments) use ($handle): void {
                foreach ($shipments as $shipment) {
                    fputcsv($handle, array_map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v, $shipment->only(self::SHIPMENT_EXPORT_COLUMNS)));
                }
            });

        fclose($handle);
        $this->info("Exported shipments to {$shipmentPath}");

        // Export packages
        $packagePath = "archives/packages-{$date}.csv";
        $handle = fopen(Storage::path($packagePath), 'w');
        fputcsv($handle, self::PACKAGE_EXPORT_COLUMNS);

        Package::whereIn('shipment_id', $this->eligibleQuery($cutoff)->select('id'))
            ->select(self::PACKAGE_EXPORT_COLUMNS)
            ->orderBy('id')
            ->chunk(1000, function ($packages) use ($handle): void {
                foreach ($packages as $package) {
                    fputcsv($handle, array_map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v, $package->only(self::PACKAGE_EXPORT_COLUMNS)));
                }
            });

        fclose($handle);
        $this->info("Exported packages to {$packagePath}");
    }
}
