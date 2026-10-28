<?php

namespace App\Services\ProductCatalog;

use App\DataTransferObjects\ShipmentImport\ProductCatalogPage;
use App\Jobs\LookUpAmazonCatalogIdentifiersJob;
use App\Models\AmazonCatalogLookup;
use App\Models\DataSource;
use App\Models\Product;
use App\Services\ClientContext;
use App\Services\ShipmentImport\AmazonCatalogIdentifiers;
use App\Services\ShipmentImport\ImportReferenceResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The catalog look-up that follows an Amazon listing sync
 * (`product-catalog-sync/04`): barcodes and part numbers from Amazon's
 * catalog for the SKUs the sync listed.
 *
 * As each listings page commits, its SKUs and ASINs are recorded
 * ({@see AmazonCatalogLookup}), so the look-up covers the run's SKUs and
 * nothing else, and survives between its jobs. When the listings are read,
 * {@see LookUpAmazonCatalogIdentifiersJob} works through the rows in short
 * slices, asking only about ASINs that
 * {@see AmazonCatalogIdentifiers::asinsNeedingLookup()} says need it, and
 * deletes each row once its ASIN is answered, fails, or needs nothing. A row
 * is the only record of work left, so a second look-up of the connection
 * finds only what the first has not done, and the job's overlap lock keeps
 * two from asking about the same rows at once.
 *
 * The catalog is Amazon's shared record, not the seller's data, so its
 * barcode and part number only fill blanks
 * ({@see AmazonCatalogIdentifiers::FILL_ONLY_FIELDS}). The look-up stamp
 * `identifiers_checked_at` always moves forward on an answer. A failed
 * request is logged and leaves the stamp alone, so the next sync asks again.
 */
class AmazonCatalogLookups
{
    /** Distinct ASINs taken from the queue at a time. */
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly ImportReferenceResolver $references,
    ) {}

    /**
     * Record a written page's listings for the look-up. Idempotent: a page
     * written again records the same rows.
     */
    public function record(DataSource $dataSource, ProductCatalogPage $page): void
    {
        $rows = $page->items
            ->filter(fn (array $item): bool => is_string($item['_asin'] ?? null) && $item['_asin'] !== '' && is_string($item['sku'] ?? null) && $item['sku'] !== '')
            ->map(fn (array $item): array => ['data_source_id' => $dataSource->id, 'sku' => $item['sku'], 'asin' => $item['_asin']])
            ->values()
            ->all();

        if ($rows !== []) {
            AmazonCatalogLookup::query()->upsert($rows, ['data_source_id', 'sku'], ['asin']);
        }
    }

    /**
     * Look up recorded ASINs until the slice's time is up or none are left.
     *
     * @return bool whether rows are left for another slice
     */
    public function runSlice(DataSource $dataSource, AmazonCatalogIdentifiers $catalog): bool
    {
        $sliceEndsAt = CarbonImmutable::now()->addSeconds(ProductCatalogSyncTiming::SLICE_SECONDS);
        $clientId = $dataSource->client_id ?? app(ClientContext::class)->default()->id;

        while (true) {
            $asins = $this->pending($dataSource)->select('asin')->distinct()->orderBy('asin')->limit(self::BATCH_SIZE)->pluck('asin');

            if ($asins->isEmpty()) {
                return false;
            }

            /** @var array<string, list<string>> $skusByAsin */
            $skusByAsin = [];

            foreach ($this->pending($dataSource)->whereIn('asin', $asins)->get(['sku', 'asin']) as $row) {
                $skusByAsin[$row->asin][] = $row->sku;
            }

            $needing = AmazonCatalogIdentifiers::asinsNeedingLookup($skusByAsin, $clientId);
            $this->forget($dataSource, $asins->diff($needing));

            foreach ($needing->chunk(AmazonCatalogIdentifiers::CHUNK_SIZE) as $chunk) {
                $checkedAt = CarbonImmutable::now()->toDateTimeString();
                $answers = $catalog->fetch($chunk->values());

                $productIds = DB::transaction(function () use ($dataSource, $clientId, $chunk, $answers, $skusByAsin, $checkedAt): array {
                    $productIds = Product::withoutSyncingToSearch(fn (): array => $this->write($dataSource, $clientId, $answers, $skusByAsin, $checkedAt));
                    $this->forget($dataSource, $chunk);

                    return $productIds;
                });

                ProductCatalogSync::queueIndexing($productIds);

                if (CarbonImmutable::now()->greaterThanOrEqualTo($sliceEndsAt)) {
                    return $this->pending($dataSource)->exists();
                }
            }
        }
    }

    /**
     * Write each answer to the existing Products of its ASIN's SKUs: the
     * barcode and part number where a Product has none, and the stamp.
     *
     * @param  array<string, array{barcode: string|null, part_number: string|null}>  $answers
     * @param  array<string, list<string>>  $skusByAsin
     * @return list<int> the Products written
     */
    private function write(DataSource $dataSource, int $clientId, array $answers, array $skusByAsin, string $checkedAt): array
    {
        $skus = collect($answers)->keys()->flatMap(fn (int|string $asin): array => $skusByAsin[(string) $asin] ?? []);

        // A Product deleted since the listings were written stays deleted.
        $existing = Product::query()->where('client_id', $clientId)->whereIn('sku', $skus)->pluck('sku')->flip();
        $productIds = [];

        foreach ($answers as $asin => $identifiers) {
            foreach ($skusByAsin[(string) $asin] ?? [] as $sku) {
                if (! $existing->has($sku)) {
                    continue;
                }

                $result = $this->references->productIdFor([
                    'sku' => $sku,
                    'barcode' => $identifiers['barcode'],
                    'manufacturer_part_number' => $identifiers['part_number'],
                    'identifiers_checked_at' => $checkedAt,
                    // No name is sent, so none is written over the listing's.
                    '_fill_only' => ['name', ...AmazonCatalogIdentifiers::FILL_ONLY_FIELDS],
                ], $dataSource->client);

                if ($result['id'] !== null) {
                    $productIds[] = $result['id'];
                }
            }
        }

        return $productIds;
    }

    /**
     * @param  Collection<int, string>  $asins
     */
    private function forget(DataSource $dataSource, Collection $asins): void
    {
        if ($asins->isNotEmpty()) {
            $this->pending($dataSource)->whereIn('asin', $asins->values())->delete();
        }
    }

    /**
     * @return Builder<AmazonCatalogLookup>
     */
    private function pending(DataSource $dataSource): Builder
    {
        return AmazonCatalogLookup::query()->where('data_source_id', $dataSource->id);
    }
}
