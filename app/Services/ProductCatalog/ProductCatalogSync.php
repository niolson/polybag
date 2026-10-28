<?php

namespace App\Services\ProductCatalog;

use App\Contracts\SyncsProductCatalog;
use App\DataTransferObjects\ShipmentImport\ProductCatalogPage;
use App\DataTransferObjects\ShipmentImport\ProductCatalogSyncRun;
use App\Enums\ProductCatalogSyncStatus;
use App\Exceptions\CatalogReadMustStartOver;
use App\Jobs\SyncProductCatalogJob;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Product;
use App\Services\ShipmentImport\DataSourceFactory;
use App\Services\ShipmentImport\ImportReferenceResolver;
use App\Services\ShipmentImport\ImportRunRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Scout\Jobs\MakeSearchable;
use RuntimeException;
use Throwable;

/**
 * Pull a connection's whole product catalog into its client's Products
 * (`product-catalog-sync/02`), so the first scan of any listed item finds
 * one.
 *
 * Every item goes through {@see ImportReferenceResolver::productIdFor()}.
 * The store's non-blank values overwrite PolyBag's, except the name
 * ({@see FILL_ONLY_FIELDS}). A listed Product is marked active, as an order
 * import marks it. Nothing is deleted, and nothing is written back to the
 * source.
 *
 * A sync is a chain of short jobs ({@see ProductCatalogSyncTiming}), each
 * running one slice from where the run's cursor stands.
 */
class ProductCatalogSync
{
    /**
     * The Product fields a sync writes only where the Product has none.
     *
     * Every other field takes the store's value when the store has one: PolyBag
     * is not the source of truth for product data, and a correction belongs in
     * Shopify or the ERP. A blank at the store (and a weight of 0, which
     * Shopify reports for a variant with no weight entered) is no value, so it
     * leaves PolyBag's alone. The name is the exception: an order import names
     * a Product by the line's title, and if the sync overwrote it with
     * "Product - Variant", the name would flip with every import and every
     * sync, each counting the flip as an update.
     */
    public const FILL_ONLY_FIELDS = ['name'];

    public function __construct(
        private readonly DataSourceFactory $sources,
        private readonly ImportReferenceResolver $references,
        private readonly ProductCatalogSyncRuns $runs,
    ) {}

    /**
     * Start a sync and queue its first slice, resuming a run that failed or
     * was interrupted partway. Null, and nothing queued, when the connection
     * cannot sync or a sync of it is already running.
     */
    public function start(DataSource $dataSource, ?int $userId = null): ?ProductCatalogSyncRun
    {
        if (! self::writesProducts() || ! $dataSource->syncsProductCatalog()) {
            return null;
        }

        $run = $this->runs->start($dataSource);

        if ($run === null) {
            return null;
        }

        try {
            Bus::dispatch((new SyncProductCatalogJob($dataSource->id, $run->runId, $userId))->afterCommit());
        } catch (Throwable $e) {
            // Otherwise the run would show running with no job behind it.
            $this->runs->update($dataSource, $run->runId, fn (ProductCatalogSyncRun $current): ProductCatalogSyncRun => $current->failAtNextPage('The sync could not be queued: '.ImportRunRecorder::reasonFor($e)));

            throw $e;
        }

        return $run;
    }

    /**
     * Whether imports may create and update Products at all. When they may
     * not, Products are kept somewhere else, and a sync would write nothing.
     */
    public static function writesProducts(): bool
    {
        return (bool) config('shipment-import.behavior.auto_update_products', true);
    }

    /**
     * Write pages of the run `$runId` from where it stands until the slice's
     * time is up or the catalog ends. Each page commits with the run's
     * counts and cursor. A failure is recorded, with the page it stopped at,
     * and rethrown; what was saved before it stays, and is queued for
     * indexing.
     *
     * @return bool whether the run has more to read
     */
    public function runSlice(DataSource $dataSource, string $runId): bool
    {
        $run = $dataSource->productCatalogSyncRun();

        if ($run === null || $run->runId !== $runId || $run->status !== ProductCatalogSyncStatus::Running) {
            return false;
        }

        $sliceEndsAt = CarbonImmutable::now()->addSeconds(ProductCatalogSyncTiming::SLICE_SECONDS);

        try {
            if (! self::writesProducts()) {
                throw new RuntimeException('Products are kept outside PolyBag (SHIPMENT_IMPORT_AUTO_UPDATE_PRODUCTS is off), so a catalog sync would write nothing.');
            }

            $source = $this->sources->make($dataSource);

            if (! $source instanceof SyncsProductCatalog) {
                throw new RuntimeException("{$dataSource->name} cannot list a product catalog.");
            }

            foreach ($source->fetchProductCatalogPages($run->cursor) as $page) {
                $productIds = $this->writePage($dataSource, $runId, $page);
                $this->queueIndexing($productIds);

                if ($page->nextCursor === null) {
                    break;
                }

                if (CarbonImmutable::now()->greaterThanOrEqualTo($sliceEndsAt)) {
                    return true;
                }
            }
        } catch (ProductCatalogSyncSuperseded) {
            return false;
        } catch (Throwable $e) {
            $this->runs->update($dataSource, $runId, fn (ProductCatalogSyncRun $current): ProductCatalogSyncRun => $current->failAtNextPage(
                ImportRunRecorder::reasonFor($e),
                startOver: $e instanceof CatalogReadMustStartOver,
            ));

            throw $e;
        }

        $this->runs->update($dataSource, $runId, fn (ProductCatalogSyncRun $current): ProductCatalogSyncRun => $current->finish(ProductCatalogSyncStatus::Complete));

        return false;
    }

    /**
     * Write one page and advance the run past it, in one transaction, without
     * indexing each save. A run that is no longer this one's rolls the page
     * back.
     *
     * @return list<int> the Products written
     */
    private function writePage(DataSource $dataSource, string $runId, ProductCatalogPage $page): array
    {
        // Product rows are locked before the connection's row, the reverse of
        // an order import; with more than one import worker the two can
        // deadlock. The page is idempotent, so a deadlock retries it.
        return DB::transaction(function () use ($dataSource, $runId, $page): array {
            [$productIds, $created, $updated, $unchanged] = Product::withoutSyncingToSearch(
                fn (): array => $this->writeItems($page, $dataSource->client),
            );

            $run = $this->runs->update(
                $dataSource,
                $runId,
                fn (ProductCatalogSyncRun $current): ProductCatalogSyncRun => $current->withPage($created, $updated, $unchanged, $page->nextCursor),
            ) ?? throw new ProductCatalogSyncSuperseded;

            Log::info('Saved a page of a product catalog', [
                'data_source_id' => $dataSource->id,
                'run_id' => $runId,
                'page' => $run->pages,
                'created' => $created,
                'updated' => $updated,
                'unchanged' => $unchanged,
            ]);

            return $productIds;
        }, attempts: 3);
    }

    /**
     * @return array{0: list<int>, 1: int, 2: int, 3: int} the Products written, then how many were created, updated and unchanged
     */
    private function writeItems(ProductCatalogPage $page, ?Client $client): array
    {
        $productIds = [];
        $created = $updated = $unchanged = 0;

        foreach ($page->items as $item) {
            $result = $this->references->productIdFor([...$item, '_fill_only' => self::FILL_ONLY_FIELDS], $client);

            if ($result['id'] === null) {
                continue;
            }

            $productIds[] = $result['id'];

            match (true) {
                $result['created'] => $created++,
                $result['updated'] => $updated++,
                default => $unchanged++,
            };
        }

        return [$productIds, $created, $updated, $unchanged];
    }

    /**
     * Index a committed page's Products on the `low` queue. Search may lag the
     * sync by minutes; a run that fails later leaves this page searchable.
     *
     * @param  list<int>  $productIds
     */
    private function queueIndexing(array $productIds): void
    {
        if ($productIds === []) {
            return;
        }

        dispatch(new MakeSearchable(Product::query()->whereKey($productIds)->get()))->onQueue('low');
    }
}
