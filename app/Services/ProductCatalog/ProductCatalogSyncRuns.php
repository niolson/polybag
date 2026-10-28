<?php

namespace App\Services\ProductCatalog;

use App\DataTransferObjects\ShipmentImport\ProductCatalogSyncRun;
use App\Enums\ProductCatalogSyncStatus;
use App\Models\DataSource;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reads and writes a connection's catalog sync run under a lock on the
 * connection's row (`product-catalog-sync/02`).
 *
 * The run is what keeps two syncs of a connection apart: a sync starts only
 * when no run is running, or the running one is interrupted, and a job
 * writes only to the run it was started for, while it is still running. A
 * lock that is free between one job and the next could not do that.
 */
class ProductCatalogSyncRuns
{
    /**
     * Start a run, unless one is already running. Null when one is.
     */
    public function start(DataSource $dataSource): ?ProductCatalogSyncRun
    {
        return DB::transaction(function () use ($dataSource): ?ProductCatalogSyncRun {
            $current = $this->lockedRun($dataSource);

            if ($current?->isRunning()) {
                return null;
            }

            $run = ProductCatalogSyncRun::start((string) Str::uuid());
            $dataSource->recordProductCatalogSyncRun($run);

            return $run;
        });
    }

    /**
     * Apply `$change` to the run `$runId`, if it is still the connection's
     * running run. Null, and nothing written, when it is not: it finished, or
     * an interrupted run was replaced by a new sync.
     *
     * @param  Closure(ProductCatalogSyncRun): ProductCatalogSyncRun  $change
     */
    public function update(DataSource $dataSource, string $runId, Closure $change): ?ProductCatalogSyncRun
    {
        return DB::transaction(function () use ($dataSource, $runId, $change): ?ProductCatalogSyncRun {
            $current = $this->lockedRun($dataSource);

            if ($current === null || $current->runId !== $runId || $current->status !== ProductCatalogSyncStatus::Running) {
                return null;
            }

            $run = $change($current);
            $dataSource->recordProductCatalogSyncRun($run);

            return $run;
        });
    }

    /**
     * The run as committed, with the connection's row locked until the
     * transaction ends.
     */
    private function lockedRun(DataSource $dataSource): ?ProductCatalogSyncRun
    {
        $stored = DataSource::query()->whereKey($dataSource->id)->lockForUpdate()->value('product_catalog_sync');
        $stored = is_string($stored) ? json_decode($stored, true) : $stored;

        return is_array($stored) ? ProductCatalogSyncRun::fromArray($stored) : null;
    }
}
