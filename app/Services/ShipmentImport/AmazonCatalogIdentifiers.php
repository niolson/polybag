<?php

namespace App\Services\ShipmentImport;

use App\Http\Integrations\Amazon\AmazonRequestPacer;
use App\Http\Integrations\Amazon\Requests\SearchCatalogItems;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Barcodes and part numbers from Amazon's catalog (`SearchCatalogItems`), by
 * ASIN, for an order import and for a product catalog sync's look-up
 * (`product-catalog-sync/04`).
 *
 * The catalog is Amazon's shared record of a product, not the seller's own
 * data, so both callers write what it answers only where a Product has no
 * barcode or part number. A live probe found about one in five of its part
 * numbers is really the UPC (`product-catalog-sync/03`).
 */
class AmazonCatalogIdentifiers
{
    /** A Product the catalog answered for within this many days is not looked up again. */
    public const RECHECK_DAYS = 30;

    /** ASINs per catalog request, which is also the page size: Amazon's default of 10 would page a full chunk. */
    public const CHUNK_SIZE = 20;

    /** The fields a catalog answer fills only where a Product has none. */
    public const FILL_ONLY_FIELDS = ['barcode', 'manufacturer_part_number'];

    private const IDENTIFIER_PRIORITY = ['UPC', 'EAN', 'GTIN', 'JAN', 'ISBN'];

    private const PART_NUMBER_MAX_LENGTH = 100;

    /** Values Amazon carries in `partNumber` or `modelNumber` that name no part. */
    private const PART_NUMBER_PLACEHOLDERS = ['n/a', 'na', 'none', 'not applicable', 'does not apply', 'unknown', 'generic'];

    /**
     * @param  string  $failureMessage  what a failed lookup logs, saying what carries on without it
     */
    public function __construct(
        private readonly AmazonRequestPacer $requests,
        private readonly string $marketplaceId,
        private readonly ?int $dataSourceId,
        private readonly string $failureMessage,
    ) {}

    /**
     * The ASINs the catalog should be asked about: those where some SKU has
     * no Product yet, or a Product missing its barcode or part number that
     * the catalog has not answered for within {@see RECHECK_DAYS}.
     *
     * Products are filed by seller SKU (`ImportReferenceResolver::productIdFor()`),
     * and several SKUs can list under one ASIN, so an ASIN is looked up when
     * *any* of its SKUs needs it — one complete SKU must not hide the lookup
     * from another.
     *
     * @param  array<string, list<string>>  $skusByAsin
     * @return Collection<int, string>
     */
    public static function asinsNeedingLookup(array $skusByAsin, int $clientId): Collection
    {
        if ($skusByAsin === []) {
            return collect();
        }

        $recheckBefore = now()->subDays(self::RECHECK_DAYS);

        $skusNotNeedingLookup = Product::query()
            ->where('client_id', $clientId)
            ->whereIn('sku', collect($skusByAsin)->flatten()->unique()->values())
            ->where(fn ($query) => $query
                ->where(fn ($complete) => $complete
                    ->whereNotNull('barcode')->where('barcode', '!=', '')
                    ->whereNotNull('manufacturer_part_number')->where('manufacturer_part_number', '!=', ''))
                ->orWhere('identifiers_checked_at', '>=', $recheckBefore))
            ->pluck('sku');

        return collect($skusByAsin)
            ->filter(fn (array $skus): bool => collect($skus)->contains(
                fn (string $sku): bool => ! $skusNotNeedingLookup->contains($sku)
            ))
            ->keys()
            ->map(fn (int|string $asin): string => (string) $asin)
            ->values();
    }

    /**
     * Ask the catalog for each ASIN's barcode and part number. Every ASIN in a
     * chunk that succeeded gets an entry, empty or not; a failed chunk gets
     * none, and is logged.
     *
     * @param  Collection<int, string>  $asins
     * @return array<string, array{barcode: string|null, part_number: string|null}>
     */
    public function fetch(Collection $asins): array
    {
        return $this->fetchReportingFailures($asins)[0];
    }

    /**
     * {@see fetch()}, and the ASINs of each chunk that failed, with the HTTP
     * status Amazon answered, or null when no answer came (a connection or
     * token failure). Failures are logged as {@see fetch()} logs them.
     *
     * @param  Collection<int, string>  $asins
     * @return array{0: array<string, array{barcode: string|null, part_number: string|null}>, 1: array<string, int|null>}
     */
    public function fetchReportingFailures(Collection $asins): array
    {
        $identifiers = [];
        $failures = [];

        foreach ($asins->chunk(self::CHUNK_SIZE) as $asinChunk) {
            try {
                $response = $this->requests->send(new SearchCatalogItems([
                    'marketplaceIds' => $this->marketplaceId,
                    'identifiersType' => 'ASIN',
                    'identifiers' => $asinChunk->implode(','),
                    'includedData' => 'identifiers,summaries',
                    'pageSize' => self::CHUNK_SIZE,
                ]), SearchCatalogItems::DEFAULT_RATE_LIMIT);

                if ($response->failed()) {
                    $this->logFailure($asinChunk->count(), 'Amazon returned HTTP '.$response->status().'.');
                    $failures = array_replace($failures, $asinChunk->mapWithKeys(fn (string $asin): array => [$asin => $response->status()])->all());

                    continue;
                }

                // An ASIN absent from a complete answer is one the catalog has
                // nothing for, and is recorded as checked. If Amazon paged the
                // answer anyway, an absent ASIN may be on a page not read, so
                // only the ASINs returned count.
                $chunkIdentifiers = $response->json('pagination.nextToken') === null
                    ? $asinChunk->mapWithKeys(fn (string $asin): array => [$asin => ['barcode' => null, 'part_number' => null]])->all()
                    : [];

                foreach ($response->json('items', []) as $catalogItem) {
                    if (! is_array($catalogItem)) {
                        continue;
                    }

                    $asin = $catalogItem['asin'] ?? null;

                    if (is_string($asin) && $asinChunk->contains($asin)) {
                        $chunkIdentifiers[$asin] = [
                            'barcode' => $this->preferredBarcode($catalogItem),
                            'part_number' => $this->preferredPartNumber($catalogItem, $asin),
                        ];
                    }
                }

                // Not spread: an all-digit ASIN (a book's ISBN-10) is an integer
                // key, and unpacking would renumber it.
                $identifiers = array_replace($identifiers, $chunkIdentifiers);
            } catch (Throwable $exception) {
                $this->logFailure($asinChunk->count(), $exception->getMessage());
                $failures = array_replace($failures, $asinChunk->mapWithKeys(fn (string $asin): array => [$asin => null])->all());
            }
        }

        return [$identifiers, $failures];
    }

    /**
     * @param  array<string, mixed>  $catalogItem
     */
    private function preferredBarcode(array $catalogItem): ?string
    {
        $identifierGroups = collect($catalogItem['identifiers'] ?? []);
        $marketplaceGroups = $identifierGroups->where('marketplaceId', $this->marketplaceId);

        if ($marketplaceGroups->isEmpty()) {
            $marketplaceGroups = $identifierGroups;
        }

        $identifiers = $marketplaceGroups
            ->flatMap(fn (mixed $group): array => is_array($group) ? ($group['identifiers'] ?? []) : [])
            ->filter(fn (mixed $identifier): bool => is_array($identifier));

        foreach (self::IDENTIFIER_PRIORITY as $identifierType) {
            $identifier = $identifiers->first(
                fn (array $identifier): bool => strtoupper((string) ($identifier['identifierType'] ?? '')) === $identifierType
                    && is_string($identifier['identifier'] ?? null)
                    && $identifier['identifier'] !== ''
            );

            if (is_array($identifier)) {
                return $identifier['identifier'];
            }
        }

        return null;
    }

    /**
     * The summary's `partNumber`, else its `modelNumber`, from the
     * marketplace or failing that any other. A value that only repeats the
     * ASIN or the brand, or is a placeholder, is no part number. One equal to
     * the item's own GTIN is kept: a manufacturer may use the S-PID as the
     * NS-PID.
     *
     * @param  array<string, mixed>  $catalogItem
     */
    private function preferredPartNumber(array $catalogItem, string $asin): ?string
    {
        $summaries = collect($catalogItem['summaries'] ?? [])->filter(fn (mixed $summary): bool => is_array($summary));
        $marketplaceSummaries = $summaries->where('marketplaceId', $this->marketplaceId);

        foreach ([$marketplaceSummaries, $summaries->diffKeys($marketplaceSummaries)] as $group) {
            foreach ($group as $summary) {
                $brand = is_string($summary['brand'] ?? null) ? $summary['brand'] : null;

                foreach (['partNumber', 'modelNumber'] as $field) {
                    $partNumber = $this->usablePartNumber($summary[$field] ?? null, $asin, $brand);

                    if ($partNumber !== null) {
                        return $partNumber;
                    }
                }
            }
        }

        return null;
    }

    private function usablePartNumber(mixed $value, string $asin, ?string $brand): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(mb_substr(trim($value), 0, self::PART_NUMBER_MAX_LENGTH));
        $normalized = mb_strtolower($value);

        if ($value === ''
            || $normalized === mb_strtolower($asin)
            || ($brand !== null && $normalized === mb_strtolower(trim($brand)))
            || in_array($normalized, self::PART_NUMBER_PLACEHOLDERS, true)
            || preg_match('/^[-.0]+$/', $value) === 1) {
            return null;
        }

        return $value;
    }

    private function logFailure(int $asinCount, string $error): void
    {
        Log::channel((string) config('shipment-import.logging.channel', 'shipment-import'))
            ->warning($this->failureMessage, [
                'data_source_id' => $this->dataSourceId,
                'asin_count' => $asinCount,
                'error' => $error,
            ]);
    }
}
