<?php

namespace App\Services\ProductCatalog;

use App\Contracts\SyncsProductCatalog;
use App\DataTransferObjects\ShipmentImport\ProductCatalogSyncRun;
use App\Enums\ProductCatalogSyncStatus;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Product;
use App\Services\ShipmentImport\DataSourceFactory;
use App\Services\ShipmentImport\ImportReferenceResolver;
use App\Services\ShipmentImport\ImportRunRecorder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Scout\Jobs\MakeSearchable;
use RuntimeException;
use Throwable;

/**
 * Pull a connection's whole product catalog into its client's Products
 * (`product-catalog-sync/02`), so the first scan of any listed item finds
 * one.
 *
 * Every item goes through {@see ImportReferenceResolver::productIdFor()},
 * and fills blanks only: a catalog touches every Product a client has,
 * including ones corrected by hand. Nothing is deleted or deactivated, and
 * nothing is written back to the source.
 */
class ProductCatalogSync
{
    /** Every Product data field a sync writes only where the Product has no value. */
    public const FILL_ONLY_FIELDS = [
        'name',
        'description',
        'barcode',
        'weight',
        'hs_tariff_number',
        'country_of_origin',
        'manufacturer_part_number',
        'gtin',
    ];

    public function __construct(
        private readonly DataSourceFactory $sources,
        private readonly ImportReferenceResolver $references,
    ) {}

    /**
     * Run a sync, recording it on the connection as it goes: the counts after
     * each page commits, and how it ended. A failure is recorded with the
     * page it stopped at and rethrown; what was saved before it stays, and
     * is queued for indexing.
     */
    public function run(DataSource $dataSource, string $runId): ProductCatalogSyncRun
    {
        $run = ProductCatalogSyncRun::start($runId);
        $dataSource->recordProductCatalogSyncRun($run);
        $pagesSaved = 0;

        try {
            if (! config('shipment-import.behavior.auto_update_products', true)) {
                throw new RuntimeException('Products are kept outside PolyBag (SHIPMENT_IMPORT_AUTO_UPDATE_PRODUCTS is off), so a catalog sync would write nothing.');
            }

            $source = $this->sources->make($dataSource);

            if (! $source instanceof SyncsProductCatalog) {
                throw new RuntimeException("{$dataSource->name} cannot list a product catalog.");
            }

            foreach ($source->fetchProductCatalogPages() as $items) {
                [$productIds, $created, $updated, $unchanged] = $this->writePage($items, $dataSource->client);

                $run = $run->withPage($created, $updated, $unchanged);
                $dataSource->recordProductCatalogSyncRun($run);
                $pagesSaved++;

                $this->queueIndexing($productIds);
            }
        } catch (Throwable $e) {
            $dataSource->recordProductCatalogSyncRun($run->finish(
                ProductCatalogSyncStatus::Failed,
                stoppedAt: 'page '.($pagesSaved + 1),
                error: ImportRunRecorder::reasonFor($e),
            ));

            throw $e;
        }

        $run = $run->finish(ProductCatalogSyncStatus::Complete);
        $dataSource->recordProductCatalogSyncRun($run);

        return $run;
    }

    /**
     * Write one page in one transaction, without indexing each save.
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array{0: list<int>, 1: int, 2: int, 3: int} the Products written, then how many were created, updated and unchanged
     */
    private function writePage(Collection $items, ?Client $client): array
    {
        return DB::transaction(fn (): array => Product::withoutSyncingToSearch(function () use ($items, $client): array {
            $productIds = [];
            $created = $updated = $unchanged = 0;

            foreach ($items as $item) {
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
        }));
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
