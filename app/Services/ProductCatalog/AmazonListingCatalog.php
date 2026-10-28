<?php

namespace App\Services\ProductCatalog;

use App\DataTransferObjects\ShipmentImport\AmazonListingsCursor;
use App\DataTransferObjects\ShipmentImport\ProductCatalogPage;
use App\Exceptions\CatalogReadMustStartOver;
use App\Http\Integrations\Amazon\AmazonRequestPacer;
use App\Http\Integrations\Amazon\Requests\SearchListingsItems;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Every listing of an Amazon seller account, a page at a time
 * (`product-catalog-sync/04`), for a product catalog sync.
 *
 * `searchListingsItems` returns at most 1,000 listings for one search, pages
 * included, so the account is read in creation-date windows, oldest first,
 * starting from one window over its whole history. A window whose first page
 * reports 1,000 or more results is halved before any more of it is read:
 * `numberOfResults` is the window's true total (`product-catalog-sync/03`).
 * The halves share their middle second, since a listing can be created a
 * fraction of a second after it; {@see AmazonListingsCursor} keeps a listing
 * on the boundary from being synced twice. A one-second window still at the
 * cap cannot be halved: what Amazon returns of it is synced, and the run
 * ends incomplete, naming it.
 *
 * Every listing returned is synced, whatever its status: an inactive listing
 * is still something a packer can scan. Weight comes from the seller's
 * listing only, never Amazon's catalog record.
 */
class AmazonListingCatalog
{
    /** The most listings one search returns, pages included. */
    public const MAX_RESULTS_PER_SEARCH = 1000;

    public const PAGE_SIZE = 20;

    /** The heaviest parcel the direct carriers accept. A heavier listing weight is freight, or wrong. */
    public const MAX_POUNDS = 150.0;

    /** Before any third-party listing on Amazon. */
    private const EARLIEST = '1999-01-01T00:00:00Z';

    private const POUNDS_PER_UNIT = [
        'pounds' => 1.0,
        'ounces' => 1 / 16,
        'grams' => 1 / 453.59237,
        'kilograms' => 1000 / 453.59237,
    ];

    public function __construct(
        private readonly AmazonRequestPacer $requests,
        private readonly string $sellerId,
        private readonly string $marketplaceId,
    ) {}

    /**
     * The listings from the start or from a cursor an earlier page gave. Each
     * page's items carry the listing's ASIN as `_asin`, for the catalog
     * look-up that follows.
     *
     * @return Generator<int, ProductCatalogPage>
     *
     * @throws CatalogReadMustStartOver when Amazon refuses a search: its window or page token came from the cursor, so the next sync starts over
     */
    public function pages(?string $cursor): Generator
    {
        $position = $cursor === null
            ? AmazonListingsCursor::whole(CarbonImmutable::parse(self::EARLIEST)->getTimestamp(), CarbonImmutable::now()->getTimestamp())
            : AmazonListingsCursor::fromString($cursor);

        while (true) {
            $json = $this->search($position);
            $listings = array_values(array_filter($json['items'] ?? [], is_array(...)));
            $total = is_int($json['numberOfResults'] ?? null) ? $json['numberOfResults'] : null;
            $unreadParts = [];

            if ($position->pageToken === null && $total !== null && $total >= self::MAX_RESULTS_PER_SEARCH) {
                if ($position->isSplittable()) {
                    $position = $position->split();

                    continue;
                }

                $unreadParts[] = self::describeWindow($position->from(), $position->to());
            }

            [$items, $tailSecond, $tailSkus] = $this->itemsFrom($listings, $position);
            $fetched = $position->fetched + count($listings);
            $nextToken = $json['pagination']['nextToken'] ?? null;
            // With no total to split by, a window read to the cap carries on
            // from the last second it reached.
            $atUncountedCap = $total === null && $fetched >= self::MAX_RESULTS_PER_SEARCH;

            if (is_string($nextToken) && $nextToken !== '' && ! $atUncountedCap) {
                $position = $position->nextPage($nextToken, $fetched, $tailSecond, $tailSkus);
            } elseif ($atUncountedCap && $tailSecond !== null && $tailSecond > $position->from()) {
                $position = $position->startWindowAt($tailSecond, $tailSkus);
            } elseif ($atUncountedCap && $position->isSplittable()) {
                $unreadParts[] = self::describeWindow($position->from(), $position->from() + 1);
                $position = $position->startWindowAt($position->from() + 1, []);
            } else {
                if ($atUncountedCap) {
                    $unreadParts[] = self::describeWindow($position->from(), $position->to());
                }

                $position = $position->nextWindow($tailSecond, $tailSkus);
            }

            yield new ProductCatalogPage($items, $position?->toString(), $unreadParts);

            if ($position === null) {
                return;
            }
        }
    }

    /**
     * A listing weight in pounds: the listing's `item_package_weight`, else
     * its `item_weight`, each the connection's marketplace entry, else the
     * first. Null when neither is usable: an unknown unit, a value that is not
     * positive or rounds to 0.00 lb, or one over {@see MAX_POUNDS}. Null
     * leaves PolyBag's weight alone.
     *
     * `website_shipping_weight` is no fallback: it copies `item_weight`
     * (`product-catalog-sync/03`).
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function poundsFrom(array $attributes, string $marketplaceId): ?float
    {
        foreach (['item_package_weight', 'item_weight'] as $attribute) {
            $entry = self::marketplaceEntry($attributes[$attribute] ?? null, $marketplaceId);
            $unit = is_string($entry['unit'] ?? null) ? strtolower($entry['unit']) : null;
            $value = $entry['value'] ?? null;

            if ($unit === null || ! isset(self::POUNDS_PER_UNIT[$unit]) || ! is_numeric($value)) {
                continue;
            }

            $pounds = (float) $value * self::POUNDS_PER_UNIT[$unit];

            if (round($pounds, 2) > 0 && $pounds <= self::MAX_POUNDS) {
                return $pounds;
            }
        }

        return null;
    }

    /**
     * One search of the window in hand.
     *
     * @return array<string, mixed>
     */
    private function search(AmazonListingsCursor $position): array
    {
        $window = self::describeWindow($position->from(), $position->to());

        $response = $this->requests->send(new SearchListingsItems($this->sellerId, array_filter([
            'marketplaceIds' => $this->marketplaceId,
            'includedData' => 'summaries,attributes',
            'pageSize' => self::PAGE_SIZE,
            'sortBy' => 'createdDate',
            'sortOrder' => 'ASC',
            'createdAfter' => self::timestamp($position->from()),
            'createdBefore' => self::timestamp($position->to()),
            'pageToken' => $position->pageToken,
        ], fn (mixed $value): bool => $value !== null)), SearchListingsItems::DEFAULT_RATE_LIMIT);

        if ($response->successful()) {
            return $response->json();
        }

        $error = $response->json('errors.0.message');
        $error = is_string($error) && $error !== '' ? $error : 'no message';

        Log::warning('Amazon refused a listings search', [
            'window' => $window,
            'with_page_token' => $position->pageToken !== null,
            'status' => $response->status(),
            'errors' => $response->json('errors'),
        ]);

        // The window and page token came from the saved cursor, so a request
        // Amazon calls invalid (an expired page token among them) would be
        // invalid again on a resume.
        if ($response->status() === 400) {
            throw new CatalogReadMustStartOver("Amazon refused the search for {$window} ({$error}). The next sync starts from the beginning.");
        }

        throw new RuntimeException("Amazon answered the search for {$window} with HTTP {$response->status()} ({$error}).");
    }

    /**
     * The page's items, less SKUs an earlier window already synced, and the
     * latest creation second the window has now returned with its SKUs.
     *
     * @param  list<array<string, mixed>>  $listings
     * @return array{0: Collection<int, array<string, mixed>>, 1: ?int, 2: list<string>}
     */
    private function itemsFrom(array $listings, AmazonListingsCursor $position): array
    {
        $tailSecond = $position->tailSecond;
        $tailSkus = $position->tailSkus;
        $skip = array_flip($position->skip);
        /** @var array<string, array<string, mixed>> $items */
        $items = [];

        foreach ($listings as $listing) {
            $sku = is_string($listing['sku'] ?? null) ? trim($listing['sku']) : '';

            if ($sku === '') {
                continue;
            }

            $summary = self::marketplaceEntry($listing['summaries'] ?? null, $this->marketplaceId, 'marketplaceId');
            $createdSecond = self::createdSecond($summary['createdDate'] ?? null);

            if ($createdSecond !== null && ($tailSecond === null || $createdSecond > $tailSecond)) {
                [$tailSecond, $tailSkus] = [$createdSecond, [$sku]];
            } elseif ($createdSecond !== null && $createdSecond === $tailSecond && ! in_array($sku, $tailSkus, true)) {
                $tailSkus[] = $sku;
            }

            if (isset($skip[$sku]) || isset($items[$sku])) {
                continue;
            }

            $name = is_string($summary['itemName'] ?? null) ? trim($summary['itemName']) : '';
            $asin = $summary['asin'] ?? null;
            $attributes = is_array($listing['attributes'] ?? null) ? $listing['attributes'] : [];

            $items[$sku] = [
                'sku' => $sku,
                // The products.name column's 255 characters.
                'name' => $name === '' ? null : rtrim(mb_substr($name, 0, 255)),
                'weight' => self::poundsFrom($attributes, $this->marketplaceId),
                '_asin' => is_string($asin) && $asin !== '' ? $asin : null,
            ];
        }

        return [collect(array_values($items)), $tailSecond, $tailSkus];
    }

    /**
     * The entry for `$marketplaceId` from a list of per-marketplace values,
     * else the first.
     *
     * @return array<string, mixed>
     */
    private static function marketplaceEntry(mixed $entries, string $marketplaceId, string $key = 'marketplace_id'): array
    {
        if (! is_array($entries)) {
            return [];
        }

        $entries = array_values(array_filter($entries, is_array(...)));

        foreach ($entries as $entry) {
            if (($entry[$key] ?? null) === $marketplaceId) {
                return $entry;
            }
        }

        return $entries[0] ?? [];
    }

    private static function createdSecond(mixed $createdDate): ?int
    {
        if (! is_string($createdDate) || $createdDate === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($createdDate)->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }

    private static function timestamp(int $second): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $second);
    }

    /** A window as the run status names it. */
    public static function describeWindow(int $from, int $to): string
    {
        return 'listings created '.gmdate('Y-m-d H:i:s', $from).' to '.gmdate('Y-m-d H:i:s', $to).' UTC';
    }
}
