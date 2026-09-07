<?php

namespace App\Services\ShipmentImport;

use App\Contracts\ExportDestinationInterface;
use App\Enums\PackageExportStatus;
use App\Enums\PackageStatus;
use App\Exceptions\PermanentExportException;
use App\Models\DataSource;
use App\Models\Package;
use App\Models\PackageExport;
use App\Services\Carriers\AmazonBuyShippingAdapter;
use App\Services\SettingsService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PackageExportService
{
    private const int MaxAttempts = 32;

    private const int ScheduledFreshPackageLookbackHours = 24;

    /**
     * Export a shipped package's data to all configured destinations:
     *  1. The client's explicit export override, or the shipment's originating data source.
     *  2. Every active data source with global_export enabled (fan-out; deduped against #1).
     *     Global fan-out is a multi-client feature; in single-client mode the toggle is
     *     hidden in the UI and exports are strictly source-scoped.
     */
    public function exportPackage(Package $package): ExportResult
    {
        if ($package->exported) {
            return new ExportResult(success: true);
        }

        $package->loadMissing('shipment.dataSource', 'packageItems.shipmentItem', 'normalizedCarrier');
        $shipment = $package->shipment;

        $primary = $shipment?->dataSource;

        $multiClient = (bool) app(SettingsService::class)->get('multi_client_enabled', false);

        $globalSources = ! $multiClient ? collect() : DataSource::where('global_export', true)
            ->where('active', true)
            ->whereJsonContains('settings->export_enabled', true)
            ->get()
            ->reject(fn (DataSource $s): bool => $primary && $s->id === $primary->id);

        $destinations = collect();

        if ($primary && ($primary->settings['export_enabled'] ?? false)) {
            $destinations->push($primary);
        }

        $destinations = $destinations->merge($globalSources);

        if ($destinations->isEmpty()) {
            return new ExportResult(success: false, deferred: true);
        }

        $attempted = 0;
        $succeeded = 0;
        $errors = [];
        $retryableErrors = [];

        foreach ($destinations as $source) {
            $export = null;

            try {
                $export = $this->claimDestination($package, $source);

                if (! $export) {
                    continue;
                }

                $attempted++;
                $driver = app(DataSourceFactory::class)->make($source);

                if (! $driver instanceof ExportDestinationInterface) {
                    throw new PermanentExportException('Driver does not support package exports.');
                }

                $fieldMapping = $source->settings['export_field_mapping'] ?? [
                    'tracking_number' => 'tracking_number',
                    'carrier' => 'carrier',
                    'service' => 'service',
                    'weight' => 'weight',
                    'shipment_reference' => 'shipment_reference',
                    'fulfillment_order_id' => 'fulfillment_order_id',
                    'amazon_order_id' => 'amazon_order_id',
                ];

                $data = $this->buildExportData($package, $fieldMapping);

                if ($source->source_type === ShopifySource::class) {
                    $data['_package_reference_id'] = (string) $package->getKey();
                }

                if ($source->source_type === AmazonSource::class) {
                    // Set in both worlds, unlike the rest of the Amazon context:
                    // it is what suppresses a second confirmation, and a sandbox
                    // export that skipped the suppression would double-confirm
                    // exactly where the behaviour is meant to be exercised.
                    $data['_amazon_shipment_id'] = AmazonBuyShippingAdapter::shipmentIdFor($package);

                    // Also set in both worlds. An FBA order has nothing to
                    // confirm in either, and the sandbox body is canned, so a
                    // sandbox export would otherwise sail past the refusal.
                    $data['_amazon_fulfilled_by'] = $package->shipment?->metadata['amazon_fulfilled_by'] ?? null;

                    if (! (bool) app(SettingsService::class)->get('sandbox_mode', false)) {
                        $data = array_merge($data, $this->buildAmazonExportContext($package));
                    }
                }

                $driver->exportPackage($data);
                $export->update([
                    'status' => PackageExportStatus::Succeeded,
                    'last_error' => null,
                    'locked_at' => null,
                    'completed_at' => now(),
                ]);
                $succeeded++;
            } catch (Throwable $e) {
                $isPermanent = $export && $this->isPermanentFailure($e, $export);
                $message = "{$source->name}: {$e->getMessage()}";

                if ($export) {
                    $export->update([
                        'status' => $isPermanent ? PackageExportStatus::PermanentlyFailed : PackageExportStatus::RetryableFailed,
                        'last_error' => $e->getMessage(),
                        'locked_at' => null,
                        'completed_at' => $isPermanent ? now() : null,
                    ]);
                }

                $this->log('error', "Export to {$source->name} failed", [
                    'package_id' => $package->id,
                    'error' => $e->getMessage(),
                    'retryable' => ! $isPermanent,
                ]);
                $errors[] = $message;

                if (! $isPermanent) {
                    $retryableErrors[] = $message;
                }
            }
        }

        $destinationIds = $destinations->pluck('id')->all();
        $this->markPackageExportedWhenAllDestinationsSucceeded($package, $destinationIds);

        if ($errors === []) {
            $permanentFailures = PackageExport::query()
                ->with('dataSource:id,name')
                ->where('package_id', $package->id)
                ->whereIn('data_source_id', $destinationIds)
                ->where('status', PackageExportStatus::PermanentlyFailed)
                ->get();

            foreach ($permanentFailures as $failure) {
                $errors[] = $failure->dataSource->name.': '.($failure->last_error ?? 'Export permanently failed.');
            }
        }

        $exported = (bool) $package->fresh()?->exported;
        $deferred = $errors === [] && ! $exported;
        $success = $errors === [] && $exported;

        return new ExportResult(
            success: $success,
            destinationsAttempted: $attempted,
            destinationsSucceeded: $succeeded,
            errors: $errors,
            retryableErrors: $retryableErrors,
            deferred: $deferred,
        );
    }

    /**
     * Export all shipped but unexported packages.
     *
     * @return array<int, ExportResult> Keyed by package ID
     */
    public function exportUnexported(?int $packageId = null, bool $scheduled = false): array
    {
        $packageIds = $this->candidatePackageIds($packageId, scheduled: $scheduled);

        $packages = Package::query()
            ->whereIn('id', $packageIds)
            ->with('shipment.dataSource', 'packageItems.shipmentItem', 'normalizedCarrier')
            ->orderByDesc('id')
            ->get();

        $results = [];

        foreach ($packages as $package) {
            $results[$package->id] = $this->exportPackage($package);
        }

        return $results;
    }

    /** @return Collection<int, Package> */
    public function previewUnexported(?int $packageId = null, bool $includePermanentFailures = false): Collection
    {
        return Package::query()
            ->whereIn('id', $this->candidatePackageIds($packageId, $includePermanentFailures))
            ->with('shipment.channel')
            ->orderByDesc('id')
            ->get();
    }

    /** @return Collection<int, int> */
    private function candidatePackageIds(
        ?int $packageId = null,
        bool $includePermanentFailures = false,
        bool $scheduled = false,
    ): Collection {
        $baseQuery = $this->eligiblePackageQuery();
        $freshCutoff = $scheduled ? now()->subHours(self::ScheduledFreshPackageLookbackHours) : null;

        if ($packageId !== null) {
            return (clone $baseQuery)
                ->whereKey($packageId)
                ->where(function (Builder $query) use ($freshCutoff, $includePermanentFailures): void {
                    $query->where(function (Builder $query) use ($freshCutoff): void {
                        $query->whereDoesntHave('packageExports')
                            ->when($freshCutoff, fn (Builder $query) => $query->where('shipped_at', '>=', $freshCutoff));
                    })->orWhere(fn (Builder $query) => $this->constrainToRetryCandidates(
                        $query,
                        $includePermanentFailures,
                        $freshCutoff,
                    ));
                })
                ->limit(1)
                ->pluck('id');
        }

        $freshPackageIds = (clone $baseQuery)
            ->whereDoesntHave('packageExports')
            ->when($freshCutoff, fn (Builder $query) => $query->where('shipped_at', '>=', $freshCutoff))
            ->orderByDesc('id')
            ->limit(100)
            ->pluck('id');

        $retryPackageIds = (clone $baseQuery)
            ->where(fn (Builder $query) => $this->constrainToRetryCandidates($query, $includePermanentFailures, $freshCutoff))
            ->orderBy('id')
            ->limit(100)
            ->pluck('id');

        return $freshPackageIds->take(50)
            ->concat($retryPackageIds->take(50))
            ->concat($freshPackageIds->slice(50))
            ->concat($retryPackageIds->slice(50))
            ->unique()
            ->take(100)
            ->values();
    }

    /** @return Builder<Package> */
    private function eligiblePackageQuery(): Builder
    {
        $multiClient = (bool) app(SettingsService::class)->get('multi_client_enabled', false);
        $hasGlobalDestination = $multiClient && DataSource::query()
            ->where('global_export', true)
            ->where('active', true)
            ->whereJsonContains('settings->export_enabled', true)
            ->exists();

        $query = Package::query()
            ->where('status', PackageStatus::Shipped)
            ->where('exported', false);

        if (! $hasGlobalDestination) {
            $query->whereHas('shipment.dataSource', fn ($query) => $query
                ->whereJsonContains('settings->export_enabled', true));
        }

        return $query;
    }

    /** @param Builder<Package> $query */
    private function constrainToRetryCandidates(
        Builder $query,
        bool $includePermanentFailures = false,
        ?CarbonInterface $freshCutoff = null,
    ): void {
        $query->where(function (Builder $query) use ($includePermanentFailures): void {
            $query->whereHas('packageExports', fn (Builder $query) => $this->constrainToReadyRetry($query))
                ->orWhereHas('packageExports', fn (Builder $query) => $this->constrainToStaleProcessing($query))
                ->orWhere(function (Builder $query): void {
                    $query->whereHas('packageExports')
                        ->whereDoesntHave('packageExports', fn (Builder $query) => $query
                            ->where('status', '!=', PackageExportStatus::Succeeded));
                })
                ->orWhere(fn (Builder $query) => $this->constrainToMissingDestinations($query));

            if ($includePermanentFailures) {
                $query->orWhereHas('packageExports', fn (Builder $query) => $query
                    ->where('status', PackageExportStatus::PermanentlyFailed));
            }
        });

        if ($freshCutoff) {
            $query->where(function (Builder $query) use ($freshCutoff): void {
                $query->where('shipped_at', '>=', $freshCutoff)
                    ->orWhereHas('packageExports');
            });
        }
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function constrainToStaleProcessing(Builder $query): void
    {
        $query->where('status', PackageExportStatus::Processing)
            ->where('locked_at', '<=', now()->subMinutes(15));
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function constrainToReadyRetry(Builder $query): void
    {
        $query->where('status', PackageExportStatus::RetryableFailed)
            ->where(function (Builder $query): void {
                $query->where('attempts', 0);

                foreach ([1 => 5, 2 => 10, 3 => 20, 4 => 40, 5 => 80, 6 => 160] as $attempts => $minutes) {
                    $query->orWhere(fn (Builder $query) => $query
                        ->where('attempts', $attempts)
                        ->where('updated_at', '<=', now()->subMinutes($minutes)));
                }

                $query->orWhere(fn (Builder $query) => $query
                    ->where('attempts', '>=', 7)
                    ->where('updated_at', '<=', now()->subHours(6)));
            });
    }

    /** @param Builder<Package> $query */
    private function constrainToMissingDestinations(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            if ((bool) app(SettingsService::class)->get('multi_client_enabled', false)) {
                $query->whereExists(function ($destinations): void {
                    $destinations->selectRaw('1')
                        ->from('data_sources as global_destinations')
                        ->where('global_destinations.global_export', true)
                        ->where('global_destinations.active', true)
                        ->whereJsonContains('global_destinations.settings->export_enabled', true)
                        ->whereNotExists(function ($exports): void {
                            $exports->selectRaw('1')
                                ->from('package_exports as global_exports')
                                ->whereColumn('global_exports.package_id', 'packages.id')
                                ->whereColumn('global_exports.data_source_id', 'global_destinations.id');
                        });
                });
            }

            $query->orWhereExists(function ($destinations): void {
                $destinations->selectRaw('1')
                    ->from('shipments as export_shipments')
                    ->join('data_sources as primary_destinations', 'primary_destinations.id', '=', 'export_shipments.data_source_id')
                    ->whereColumn('export_shipments.id', 'packages.shipment_id')
                    ->whereJsonContains('primary_destinations.settings->export_enabled', true)
                    ->whereNotExists(function ($exports): void {
                        $exports->selectRaw('1')
                            ->from('package_exports as primary_exports')
                            ->whereColumn('primary_exports.package_id', 'packages.id')
                            ->whereColumn('primary_exports.data_source_id', 'primary_destinations.id');
                    });
            });
        });
    }

    public function retryPermanentFailures(?int $packageId = null): int
    {
        return $this->recoverablePermanentFailuresQuery($packageId)
            ->update([
                'status' => PackageExportStatus::RetryableFailed,
                'attempts' => 0,
                'locked_at' => null,
                'completed_at' => null,
            ]);
    }

    public function countRecoverablePermanentFailures(?int $packageId = null): int
    {
        return $this->recoverablePermanentFailuresQuery($packageId)->count();
    }

    /** @return Builder<PackageExport> */
    private function recoverablePermanentFailuresQuery(?int $packageId = null): Builder
    {
        return PackageExport::query()
            ->where('status', PackageExportStatus::PermanentlyFailed)
            ->whereHas('package', fn (Builder $query) => $query
                ->where('status', PackageStatus::Shipped)
                ->where('exported', false))
            ->when($packageId !== null, fn ($query) => $query->where('package_id', $packageId));
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
            'carrier' => $package->carrierOfRecordName(),
            // Only a confirmed service leaves the building. See ADR-0003
            // decision 7 — a guess a channel receives becomes a fact we cannot
            // retract, and every destination here reports to someone.
            'service' => $package->confirmedService(),
            'shipment_reference' => $package->shipment?->shipment_reference,
            'fulfillment_order_id' => $package->shipment?->metadata['shopify_fulfillment_order_id']
                ?? null,
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

    /**
     * @return array{_package_reference_id: string, _shipped_at: ?string, _shipping_method: ?string, _order_items: list<array{orderItemId: string, quantity: int, transparencyCodes?: array<string>}>}
     */
    private function buildAmazonExportContext(Package $package): array
    {
        return [
            '_package_reference_id' => (string) $package->getKey(),
            '_shipped_at' => $package->shipped_at?->toIso8601String(),
            '_shipping_method' => $package->confirmedService(),
            '_order_items' => app(AmazonOrderItems::class)->forPackage($package),
        ];
    }

    private function isPermanentFailure(Throwable $exception, PackageExport $export): bool
    {
        if ($exception instanceof PermanentExportException
            || $export->attempts >= self::MaxAttempts) {
            return true;
        }

        return false;
    }

    private function claimDestination(Package $package, DataSource $source): ?PackageExport
    {
        $now = now();
        $inserted = DB::table('package_exports')->insertOrIgnore([
            'package_id' => $package->id,
            'data_source_id' => $source->id,
            'status' => PackageExportStatus::Processing,
            'attempts' => 1,
            'locked_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted === 1) {
            return PackageExport::query()
                ->where('package_id', $package->id)
                ->where('data_source_id', $source->id)
                ->firstOrFail();
        }

        $export = PackageExport::query()
            ->where('package_id', $package->id)
            ->where('data_source_id', $source->id)
            ->firstOrFail();

        if (in_array($export->status, [PackageExportStatus::Succeeded, PackageExportStatus::PermanentlyFailed], true)) {
            return null;
        }

        $claimed = PackageExport::query()
            ->whereKey($export->id)
            ->where(function ($query): void {
                $query->where(fn (Builder $query) => $this->constrainToReadyRetry($query))
                    ->orWhere(fn (Builder $query) => $this->constrainToStaleProcessing($query));
            })
            ->update([
                'status' => PackageExportStatus::Processing,
                'attempts' => DB::raw('attempts + 1'),
                'last_error' => null,
                'locked_at' => $now,
                'completed_at' => null,
                'updated_at' => $now,
            ]);

        return $claimed === 1 ? $export->refresh() : null;
    }

    /** @param list<int> $destinationIds */
    private function markPackageExportedWhenAllDestinationsSucceeded(Package $package, array $destinationIds): void
    {
        $succeededCount = PackageExport::query()
            ->where('package_id', $package->id)
            ->whereIn('data_source_id', $destinationIds)
            ->where('status', PackageExportStatus::Succeeded)
            ->count();

        if ($succeededCount === count($destinationIds)) {
            $package->update(['exported' => true]);
        }
    }

    private function log(string $level, string $message, array $context = []): void
    {
        $channel = config('shipment-import.logging.channel', 'stack');
        Log::channel($channel)->log($level, $message, $context);
    }
}
