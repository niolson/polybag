<?php

use App\DataTransferObjects\ShipmentImport\AmazonListingsCursor;
use App\DataTransferObjects\ShipmentImport\ProductCatalogSyncRun;
use App\Enums\ProductCatalogSyncStatus;
use App\Filament\Resources\DataSources\Pages\EditDataSource;
use App\Http\Integrations\Amazon\Requests\SearchCatalogItems;
use App\Http\Integrations\Amazon\Requests\SearchListingsItems;
use App\Jobs\LookUpAmazonCatalogIdentifiersJob;
use App\Jobs\Middleware\PreventOverlappingImports;
use App\Models\AmazonCatalogLookup;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductCatalog\AmazonListingCatalog;
use App\Services\ProductCatalog\ProductCatalogSync;
use App\Services\ProductCatalog\ProductCatalogSyncRuns;
use App\Services\ProductCatalog\ProductCatalogSyncTiming;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Laravel\Scout\Jobs\MakeSearchable;
use Livewire\Livewire;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;

uses(RefreshDatabase::class);

const AMAZON_CATALOG_SELLER = 'A0SYNTHSELLER1';

const AMAZON_CATALOG_MARKETPLACE = 'ATVPDKIKX0DER';

/** When every test's sync starts, so the first window's halves are known. */
const AMAZON_CATALOG_NOW = '2026-10-28T00:00:00Z';

/**
 * An active OAuth Amazon connection to a synthetic seller, with a cached
 * access token.
 *
 * @param  array<string, mixed>  $settings
 * @param  array<string, mixed>  $attributes
 */
function amazonCatalogConnection(array $settings = [], array $attributes = []): DataSource
{
    Cache::put('amazon_sp_api_access_token_'.md5('synthetic-refresh-token'), 'synthetic-access-token', 3600);

    return DataSource::factory()->amazon()->create([
        'name' => 'Synthetic Seller',
        'settings' => [
            'marketplace_id' => AMAZON_CATALOG_MARKETPLACE,
            'channel_name' => 'Amazon',
            'auth_mode' => 'authorization_code',
            'amazon_selling_partner_id' => AMAZON_CATALOG_SELLER,
            ...$settings,
        ],
        'secret_settings' => ['refresh_token' => 'synthetic-refresh-token'],
        ...$attributes,
    ]);
}

/**
 * A listing as `searchListingsItems` returns it with
 * `includedData=summaries,attributes`.
 *
 * @param  array<string, mixed>|null  $attributes  replaces the default attributes
 * @param  array<string, mixed>  $summary  merged into the summary
 * @return array<string, mixed>
 */
function amazonCatalogListing(string $sku, string $created, ?array $attributes = null, array $summary = []): array
{
    return [
        'sku' => $sku,
        'summaries' => [[
            'marketplaceId' => AMAZON_CATALOG_MARKETPLACE,
            'asin' => amazonCatalogAsin($sku),
            'productType' => 'SYNTHETIC_THING',
            'conditionType' => 'new_new',
            'status' => ['BUYABLE', 'DISCOVERABLE'],
            'itemName' => "Synthetic {$sku}",
            'createdDate' => $created,
            'lastUpdatedDate' => $created,
            ...$summary,
        ]],
        'attributes' => $attributes ?? [
            'item_weight' => [['unit' => 'pounds', 'value' => '0.50', 'marketplace_id' => AMAZON_CATALOG_MARKETPLACE]],
        ],
    ];
}

/** A synthetic ASIN for a SKU. */
function amazonCatalogAsin(string $sku): string
{
    return 'B0'.strtoupper(substr(md5($sku), 0, 8));
}

/**
 * `$count` listings, one a minute from `$from`.
 *
 * @return list<array<string, mixed>>
 */
function amazonCatalogListings(int $count, string $from = '2026-01-01T00:00:00Z', string $prefix = 'SYN-BULK-'): array
{
    $start = CarbonImmutable::parse($from);

    return array_map(
        fn (int $index): array => amazonCatalogListing($prefix.$index, $start->addMinutes($index)->format('Y-m-d\TH:i:s.v\Z')),
        range(1, $count),
    );
}

/**
 * Answer listing searches from `$listings` as Amazon does: both date filters
 * include their endpoint, at full precision; oldest first; 20 to a page; at
 * most 1,000 to a search. `numberOfResults` is the window's true total,
 * unless `$reportsTotal` is off or `$totalFor` gives one for the window.
 * Each request is recorded. A page token not issued is refused.
 *
 * @param  list<array<string, mixed>>  $listings
 * @param  list<array<string, mixed>>  $requests
 * @param  (Closure(string, string): ?int)|null  $totalFor  a total to report for a window, by createdAfter and createdBefore
 * @param  (Closure(array<string, mixed>): void)|null  $beforeAnswer  called with each request's query
 * @param  array<string, array<string, mixed>>  $catalog  catalog items by ASIN
 * @param  list<array<string, mixed>>  $catalogRequests
 */
function fakeAmazonCatalog(
    array $listings,
    array &$requests = [],
    bool $reportsTotal = true,
    ?Closure $totalFor = null,
    ?Closure $beforeAnswer = null,
    array $catalog = [],
    array &$catalogRequests = [],
): void {
    usort($listings, fn (array $a, array $b): int => strcmp($a['summaries'][0]['createdDate'], $b['summaries'][0]['createdDate']));

    Saloon::fake([
        SearchListingsItems::class => function (PendingRequest $pendingRequest) use ($listings, &$requests, $reportsTotal, $totalFor, $beforeAnswer): MockResponse {
            $query = $pendingRequest->query()->all();
            $requests[] = $query;

            if ($beforeAnswer !== null) {
                $beforeAnswer($query);
            }

            $after = CarbonImmutable::parse($query['createdAfter'])->getPreciseTimestamp(3);
            $before = CarbonImmutable::parse($query['createdBefore'])->getPreciseTimestamp(3);
            $window = array_values(array_filter($listings, function (array $listing) use ($after, $before): bool {
                $created = CarbonImmutable::parse($listing['summaries'][0]['createdDate'])->getPreciseTimestamp(3);

                return $created >= $after && $created <= $before;
            }));

            $offset = 0;

            if (isset($query['pageToken'])) {
                if (preg_match('/^synthetic-token-(\d+)$/', $query['pageToken'], $matches) !== 1) {
                    return MockResponse::make(['errors' => [['code' => 'InvalidInput', 'message' => 'Could not match input arguments', 'details' => '']]], 400);
                }

                $offset = (int) $matches[1];
            }

            $reachable = min(count($window), AmazonListingCatalog::MAX_RESULTS_PER_SEARCH);
            $body = ['items' => array_slice($window, $offset, min(20, max(0, $reachable - $offset)))];
            $total = $totalFor !== null ? $totalFor($query['createdAfter'], $query['createdBefore']) : null;

            if ($reportsTotal) {
                $body['numberOfResults'] = $total ?? count($window);
            }

            if ($offset + 20 < $reachable) {
                $body['pagination'] = ['nextToken' => 'synthetic-token-'.($offset + 20)];
            }

            return MockResponse::make($body, 200, ['x-amzn-RateLimit-Limit' => '5.0']);
        },
        SearchCatalogItems::class => function (PendingRequest $pendingRequest) use ($catalog, &$catalogRequests): MockResponse {
            $query = $pendingRequest->query()->all();
            $catalogRequests[] = $query;
            $asins = explode(',', $query['identifiers']);

            return MockResponse::make([
                'numberOfResults' => count($asins),
                'items' => array_values(array_intersect_key($catalog, array_flip($asins))),
            ], 200, ['x-amzn-RateLimit-Limit' => '2.0']);
        },
    ]);
}

/**
 * A catalog item with a UPC and part number.
 *
 * @return array<string, mixed>
 */
function amazonCatalogItemFor(string $sku, ?string $upc = '036000291452', ?string $partNumber = 'SYN-PN-1'): array
{
    return [
        'asin' => amazonCatalogAsin($sku),
        'identifiers' => $upc === null ? [] : [[
            'marketplaceId' => AMAZON_CATALOG_MARKETPLACE,
            'identifiers' => [['identifierType' => 'UPC', 'identifier' => $upc]],
        ]],
        'summaries' => [[
            'marketplaceId' => AMAZON_CATALOG_MARKETPLACE,
            'brand' => 'Synthetic Brand',
            'itemName' => 'Catalog name',
            ...($partNumber === null ? [] : ['partNumber' => $partNumber]),
        ]],
    ];
}

/** Start a run and work every slice of it in this process; its ID. */
function amazonSyncCatalog(DataSource $dataSource): string
{
    $run = app(ProductCatalogSyncRuns::class)->start($dataSource) ?? throw new RuntimeException('A sync is already running.');

    while (app(ProductCatalogSync::class)->runSlice($dataSource->fresh(), $run->runId)) {
        // The next slice.
    }

    return $run->runId;
}

function amazonCatalogRun(DataSource $dataSource): ProductCatalogSyncRun
{
    return $dataSource->fresh()->productCatalogSyncRun();
}

/** Work the catalog look-up's slices in this process. */
function amazonLookUpCatalog(DataSource $dataSource): void
{
    do {
        $queue = Queue::fake([MakeSearchable::class, LookUpAmazonCatalogIdentifiersJob::class]);
        app()->call([new LookUpAmazonCatalogIdentifiersJob($dataSource->id), 'handle']);
    } while ($queue->pushed(LookUpAmazonCatalogIdentifiersJob::class)->isNotEmpty());
}

/** The first window's midpoint: where it splits first. */
function amazonCatalogFirstSplit(): int
{
    return intdiv(CarbonImmutable::parse('1999-01-01T00:00:00Z')->getTimestamp() + CarbonImmutable::parse(AMAZON_CATALOG_NOW)->getTimestamp(), 2);
}

beforeEach(function (): void {
    Carbon::setTestNow(AMAZON_CATALOG_NOW);
    Sleep::fake();
    Queue::fake([MakeSearchable::class, LookUpAmazonCatalogIdentifiersJob::class]);
});

describe('listings', function (): void {
    it('creates a Product per listing across pages, with name and weight, in the connection\'s client', function (): void {
        $client = Client::factory()->create();
        $dataSource = amazonCatalogConnection(attributes: ['client_id' => $client->id]);
        $requests = [];

        fakeAmazonCatalog([
            ...amazonCatalogListings(25),
            amazonCatalogListing('SYN-HEAVY', '2026-02-01T00:00:00.000Z', [
                'item_package_weight' => [['unit' => 'ounces', 'value' => '24', 'marketplace_id' => AMAZON_CATALOG_MARKETPLACE]],
                'item_weight' => [['unit' => 'pounds', 'value' => '1.2', 'marketplace_id' => AMAZON_CATALOG_MARKETPLACE]],
            ]),
        ], $requests);

        amazonSyncCatalog($dataSource);

        $first = Product::where('sku', 'SYN-BULK-1')->sole();
        $heavy = Product::where('sku', 'SYN-HEAVY')->sole();
        $run = amazonCatalogRun($dataSource);

        expect(Product::count())->toBe(26)
            ->and($first->client_id)->toBe($client->id)
            ->and($first->name)->toBe('Synthetic SYN-BULK-1')
            ->and((float) $first->weight)->toBe(0.5)
            ->and($first->active)->toBeTrue()
            ->and((float) $heavy->weight)->toBe(1.5)
            ->and($run->status)->toBe(ProductCatalogSyncStatus::Complete)
            ->and($run->created)->toBe(26)
            ->and($requests)->toHaveCount(2)
            ->and($requests[0])->toMatchArray([
                'marketplaceIds' => AMAZON_CATALOG_MARKETPLACE,
                'includedData' => 'summaries,attributes',
                'pageSize' => 20,
                'sortBy' => 'createdDate',
                'sortOrder' => 'ASC',
                'createdAfter' => '1999-01-01T00:00:00Z',
                'createdBefore' => '2026-10-28T00:00:00Z',
            ])
            ->and($requests[1]['pageToken'])->toBe('synthetic-token-20');

        Saloon::assertSent(fn (SearchListingsItems $request): bool => $request->resolveEndpoint() === '/listings/2021-08-01/items/'.AMAZON_CATALOG_SELLER);
        // Paced at the listings rate Amazon reported, 5 a second.
        Sleep::assertSlept(fn ($duration): bool => $duration->totalMilliseconds === 200.0, times: 1);
    });

    it('syncs a listing whatever its status', function (): void {
        $dataSource = amazonCatalogConnection();

        fakeAmazonCatalog([
            amazonCatalogListing('SYN-INACTIVE', '2026-01-01T00:00:00.000Z', summary: ['status' => ['DISCOVERABLE']]),
            amazonCatalogListing('SYN-NO-STATUS', '2026-01-02T00:00:00.000Z', summary: ['status' => []]),
        ]);

        amazonSyncCatalog($dataSource);

        expect(Product::pluck('sku')->sort()->values()->all())->toBe(['SYN-INACTIVE', 'SYN-NO-STATUS']);
    });

    it('overwrites a Product\'s weight with the listing\'s, and keeps it when the listing has none, and keeps the name', function (): void {
        $dataSource = amazonCatalogConnection();
        $client = $dataSource->client;
        Product::factory()->create(['client_id' => $client->id, 'sku' => 'SYN-WEIGHED', 'name' => 'Order name', 'weight' => 3.0]);
        Product::factory()->create(['client_id' => $client->id, 'sku' => 'SYN-UNWEIGHED', 'name' => 'Kept name', 'weight' => 2.25]);

        fakeAmazonCatalog([
            amazonCatalogListing('SYN-WEIGHED', '2026-01-01T00:00:00.000Z'),
            amazonCatalogListing('SYN-UNWEIGHED', '2026-01-02T00:00:00.000Z', attributes: []),
        ]);

        amazonSyncCatalog($dataSource);

        $weighed = Product::where('sku', 'SYN-WEIGHED')->sole();
        $unweighed = Product::where('sku', 'SYN-UNWEIGHED')->sole();

        expect((float) $weighed->weight)->toBe(0.5)
            ->and($weighed->name)->toBe('Order name')
            ->and((float) $unweighed->weight)->toBe(2.25)
            ->and($unweighed->name)->toBe('Kept name')
            ->and([amazonCatalogRun($dataSource)->updated, amazonCatalogRun($dataSource)->unchanged])->toBe([1, 1]);
    });

    it('reads a weight from the package weight, else the item weight, in pounds', function (array $attributes, ?float $pounds): void {
        $weight = AmazonListingCatalog::poundsFrom($attributes, AMAZON_CATALOG_MARKETPLACE);

        expect($weight === null ? null : round($weight, 4))->toBe($pounds);
    })->with([
        'package weight first' => [[
            'item_package_weight' => [['unit' => 'pounds', 'value' => '2', 'marketplace_id' => AMAZON_CATALOG_MARKETPLACE]],
            'item_weight' => [['unit' => 'pounds', 'value' => '1', 'marketplace_id' => AMAZON_CATALOG_MARKETPLACE]],
        ], 2.0],
        'item weight when no package weight' => [['item_weight' => [['unit' => 'pounds', 'value' => '1.25']]], 1.25],
        'ounces' => [['item_weight' => [['unit' => 'ounces', 'value' => '8']]], 0.5],
        'grams' => [['item_weight' => [['unit' => 'grams', 'value' => '453.59237']]], 1.0],
        'kilograms' => [['item_weight' => [['unit' => 'kilograms', 'value' => '2']]], 4.4092],
        'the connection\'s marketplace entry' => [['item_weight' => [
            ['unit' => 'pounds', 'value' => '9', 'marketplace_id' => 'A2EUQ1WTGCTBG2'],
            ['unit' => 'pounds', 'value' => '3', 'marketplace_id' => AMAZON_CATALOG_MARKETPLACE],
        ]], 3.0],
        'the first entry, else' => [['item_weight' => [['unit' => 'pounds', 'value' => '9', 'marketplace_id' => 'A2EUQ1WTGCTBG2']]], 9.0],
        'an unknown unit' => [['item_weight' => [['unit' => 'milligrams', 'value' => '500']]], null],
        'an unknown package unit falls back' => [[
            'item_package_weight' => [['unit' => 'hundredths_pounds', 'value' => '50']],
            'item_weight' => [['unit' => 'pounds', 'value' => '0.5']],
        ], 0.5],
        'zero' => [['item_weight' => [['unit' => 'pounds', 'value' => '0']]], null],
        'negative' => [['item_weight' => [['unit' => 'pounds', 'value' => '-1']]], null],
        'rounds to 0.00 lb' => [['item_weight' => [['unit' => 'grams', 'value' => '1']]], null],
        '150 lb is kept' => [['item_weight' => [['unit' => 'pounds', 'value' => '150']]], 150.0],
        '151 lb is skipped' => [['item_weight' => [['unit' => 'pounds', 'value' => '151']]], null],
        'not a number' => [['item_weight' => [['unit' => 'pounds', 'value' => 'heavy']]], null],
        'no weight' => [[], null],
        'website shipping weight is not read' => [['website_shipping_weight' => [['unit' => 'pounds', 'value' => '1']]], null],
    ]);

    it('skips a listing weight of 151 lb and keeps one of 150 lb', function (): void {
        $dataSource = amazonCatalogConnection();

        fakeAmazonCatalog([
            amazonCatalogListing('SYN-150', '2026-01-01T00:00:00.000Z', ['item_weight' => [['unit' => 'pounds', 'value' => '150']]]),
            amazonCatalogListing('SYN-151', '2026-01-02T00:00:00.000Z', ['item_weight' => [['unit' => 'pounds', 'value' => '151']]]),
        ]);

        amazonSyncCatalog($dataSource);

        expect((float) Product::where('sku', 'SYN-150')->value('weight'))->toBe(150.0)
            ->and(Product::where('sku', 'SYN-151')->value('weight'))->toBeNull();
    });

    it('fails the run, to start over, when Amazon refuses a page token', function (): void {
        $dataSource = amazonCatalogConnection();
        $requests = [];
        fakeAmazonCatalog(amazonCatalogListings(25), $requests);

        $runs = app(ProductCatalogSyncRuns::class);
        $run = $runs->start($dataSource);
        $cursor = new AmazonListingsCursor(
            [CarbonImmutable::parse('1999-01-01T00:00:00Z')->getTimestamp(), CarbonImmutable::parse(AMAZON_CATALOG_NOW)->getTimestamp()],
            pageToken: 'expired-token',
            fetched: 20,
        );
        $runs->update($dataSource, $run->runId, fn (ProductCatalogSyncRun $current): ProductCatalogSyncRun => $current->withPage(20, 0, 0, $cursor->toString()));

        expect(fn () => app(ProductCatalogSync::class)->runSlice($dataSource->fresh(), $run->runId))
            ->toThrow(RuntimeException::class, 'Amazon refused the search for listings created 1999-01-01 00:00:00 to 2026-10-28 00:00:00 UTC (Could not match input arguments). The next sync starts from the beginning.');

        $failed = amazonCatalogRun($dataSource);

        expect($failed->status)->toBe(ProductCatalogSyncStatus::Failed)
            ->and($failed->startOver)->toBeTrue()
            ->and($failed->canResume())->toBeFalse()
            ->and(collect($requests)->pluck('pageToken')->all())->toBe(['expired-token']);
    });
});

describe('date windows', function (): void {
    it('splits a window from its first page and syncs more than 1,000 listings, each once', function (): void {
        $dataSource = amazonCatalogConnection();
        $requests = [];
        fakeAmazonCatalog(amazonCatalogListings(1050), $requests);

        amazonSyncCatalog($dataSource);

        $run = amazonCatalogRun($dataSource);
        $whole = collect($requests)->where('createdAfter', '1999-01-01T00:00:00Z')->where('createdBefore', '2026-10-28T00:00:00Z');

        expect(Product::count())->toBe(1050)
            ->and($run->status)->toBe(ProductCatalogSyncStatus::Complete)
            ->and($run->created)->toBe(1050)
            ->and($run->updated + $run->unchanged)->toBe(0)
            // Split from its first page: its second was never asked for.
            ->and($whole)->toHaveCount(1)
            ->and($whole->first())->not->toHaveKey('pageToken');
    });

    it('splits the window into halves that share their middle second, synced and counted once', function (): void {
        $dataSource = amazonCatalogConnection();
        $middle = amazonCatalogFirstSplit();
        $requests = [];

        fakeAmazonCatalog([
            amazonCatalogListing('SYN-EARLY', '2010-01-01T00:00:00.000Z'),
            amazonCatalogListing('SYN-ON-BOUNDARY', gmdate('Y-m-d\TH:i:s', $middle).'.000Z'),
            amazonCatalogListing('SYN-HALF-SECOND-AFTER', gmdate('Y-m-d\TH:i:s', $middle).'.500Z'),
            amazonCatalogListing('SYN-LATE', '2026-01-01T00:00:00.000Z'),
        ], $requests, totalFor: fn (string $after, string $before): ?int => $after === '1999-01-01T00:00:00Z' && $before === '2026-10-28T00:00:00Z' ? 1000 : null);

        amazonSyncCatalog($dataSource);

        $run = amazonCatalogRun($dataSource);

        expect(collect($requests)->map(fn (array $query): array => [$query['createdAfter'], $query['createdBefore']])->all())->toBe([
            ['1999-01-01T00:00:00Z', '2026-10-28T00:00:00Z'],
            ['1999-01-01T00:00:00Z', gmdate('Y-m-d\TH:i:s\Z', $middle)],
            [gmdate('Y-m-d\TH:i:s\Z', $middle), '2026-10-28T00:00:00Z'],
        ])
            ->and(Product::pluck('sku')->sort()->values()->all())->toBe(['SYN-EARLY', 'SYN-HALF-SECOND-AFTER', 'SYN-LATE', 'SYN-ON-BOUNDARY'])
            ->and($run->created)->toBe(4)
            ->and($run->updated + $run->unchanged)->toBe(0);
    });

    it('carries the boundary\'s SKUs into the next slice, so a listing on it is counted once', function (): void {
        $dataSource = amazonCatalogConnection();
        $middle = amazonCatalogFirstSplit();
        $firstHalfEnd = gmdate('Y-m-d\TH:i:s\Z', $middle);

        fakeAmazonCatalog([
            amazonCatalogListing('SYN-ON-BOUNDARY', gmdate('Y-m-d\TH:i:s', $middle).'.000Z'),
            amazonCatalogListing('SYN-LATE', '2026-01-01T00:00:00.000Z'),
        ], totalFor: fn (string $after, string $before): ?int => $after === '1999-01-01T00:00:00Z' && $before === '2026-10-28T00:00:00Z' ? 1000 : null,
            // The first half's page is the last of its slice.
            beforeAnswer: function (array $query) use ($firstHalfEnd): void {
                if ($query['createdBefore'] === $firstHalfEnd) {
                    Carbon::setTestNow(now()->addSeconds(ProductCatalogSyncTiming::SLICE_SECONDS + 1));
                }
            });

        $run = app(ProductCatalogSyncRuns::class)->start($dataSource);

        expect(app(ProductCatalogSync::class)->runSlice($dataSource->fresh(), $run->runId))->toBeTrue();

        $between = amazonCatalogRun($dataSource);

        expect(AmazonListingsCursor::fromString($between->cursor)->skip)->toBe(['SYN-ON-BOUNDARY'])
            ->and(strlen($between->cursor))->toBeLessThan(200);

        expect(app(ProductCatalogSync::class)->runSlice($dataSource->fresh(), $run->runId))->toBeFalse();

        $finished = amazonCatalogRun($dataSource);

        expect($finished->status)->toBe(ProductCatalogSyncStatus::Complete)
            ->and($finished->created)->toBe(2)
            ->and($finished->updated + $finished->unchanged)->toBe(0);
    });

    it('reads what it can of a one-second window at the cap, carries on, and ends incomplete naming it', function (): void {
        $dataSource = amazonCatalogConnection();
        $burst = array_map(
            // Within the second, never on it, so no earlier window returns one.
            fn (int $index): array => amazonCatalogListing("SYN-BURST-{$index}", sprintf('2026-03-01T12:00:00.%03dZ', 1 + $index % 999)),
            range(1, 1001),
        );

        fakeAmazonCatalog([...$burst, amazonCatalogListing('SYN-AFTER', '2026-03-02T00:00:00.000Z')]);

        amazonSyncCatalog($dataSource);

        $run = amazonCatalogRun($dataSource);

        expect($run->status)->toBe(ProductCatalogSyncStatus::Incomplete)
            ->and($run->stoppedAt)->toBe('listings created 2026-03-01 12:00:00 to 2026-03-01 12:00:01 UTC')
            // Amazon returns 1,000 of the second's 1,001; then the listing after it.
            ->and(Product::where('sku', 'like', 'SYN-BURST-%')->count())->toBe(1000)
            ->and($run->created)->toBe(1001)
            ->and(Product::where('sku', 'SYN-AFTER')->exists())->toBeTrue();

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(EditDataSource::class, ['record' => $dataSource->id])
            ->assertSee('Incomplete')
            ->assertSee('Not read: listings created 2026-03-01 12:00:00 to 2026-03-01 12:00:01 UTC.');
    });

    it('pages a window when Amazon gives no total, and carries on from the last second once 1,000 are fetched', function (): void {
        $dataSource = amazonCatalogConnection();
        $requests = [];
        $listings = amazonCatalogListings(1030);
        // The 1,000th listing shares its second with the 1,001st.
        $listings[1000]['summaries'][0]['createdDate'] = substr($listings[999]['summaries'][0]['createdDate'], 0, 19).'.500Z';
        fakeAmazonCatalog($listings, $requests, reportsTotal: false);

        amazonSyncCatalog($dataSource);

        $run = amazonCatalogRun($dataSource);
        $lastSecond = substr($listings[999]['summaries'][0]['createdDate'], 0, 19).'Z';
        $windows = collect($requests)->map(fn (array $query): array => [$query['createdAfter'], $query['createdBefore']])->unique()->values();

        expect(Product::count())->toBe(1030)
            ->and($run->status)->toBe(ProductCatalogSyncStatus::Complete)
            ->and($run->created)->toBe(1030)
            ->and($run->updated + $run->unchanged)->toBe(0)
            ->and(collect($requests)->where('createdAfter', '1999-01-01T00:00:00Z'))->toHaveCount(50)
            ->and($windows->all())->toBe([
                ['1999-01-01T00:00:00Z', '2026-10-28T00:00:00Z'],
                [$lastSecond, '2026-10-28T00:00:00Z'],
            ]);
    });
});

describe('the catalog look-up', function (): void {
    it('is queued when the listings are read, and leaves the run as the listings left it', function (): void {
        $dataSource = amazonCatalogConnection();
        fakeAmazonCatalog([amazonCatalogListing('SYN-NEW', '2026-01-01T00:00:00.000Z')]);

        amazonSyncCatalog($dataSource);

        Queue::assertPushed(LookUpAmazonCatalogIdentifiersJob::class, fn (LookUpAmazonCatalogIdentifiersJob $job): bool => $job->dataSourceId === $dataSource->id
            && $job->connection === 'imports'
            && $job->queue === 'imports');

        expect(AmazonCatalogLookup::where('data_source_id', $dataSource->id)->pluck('asin', 'sku')->all())
            ->toBe(['SYN-NEW' => amazonCatalogAsin('SYN-NEW')]);
    });

    it('fills a blank barcode and part number, never replaces one, and stamps the look-up', function (): void {
        $dataSource = amazonCatalogConnection();
        $client = $dataSource->client;
        Product::factory()->create(['client_id' => $client->id, 'sku' => 'SYN-HAS-BOTH-BLANK', 'barcode' => null, 'manufacturer_part_number' => null]);
        Product::factory()->create(['client_id' => $client->id, 'sku' => 'SYN-HAS-BARCODE', 'barcode' => '012345678905', 'manufacturer_part_number' => null]);
        $catalogRequests = [];

        fakeAmazonCatalog([
            amazonCatalogListing('SYN-HAS-BOTH-BLANK', '2026-01-01T00:00:00.000Z'),
            amazonCatalogListing('SYN-HAS-BARCODE', '2026-01-02T00:00:00.000Z'),
        ], catalog: [
            amazonCatalogAsin('SYN-HAS-BOTH-BLANK') => amazonCatalogItemFor('SYN-HAS-BOTH-BLANK'),
            amazonCatalogAsin('SYN-HAS-BARCODE') => amazonCatalogItemFor('SYN-HAS-BARCODE', upc: '036000291452', partNumber: 'SYN-PN-2'),
        ], catalogRequests: $catalogRequests);

        amazonSyncCatalog($dataSource);
        amazonLookUpCatalog($dataSource);

        $blank = Product::where('sku', 'SYN-HAS-BOTH-BLANK')->sole();
        $hasBarcode = Product::where('sku', 'SYN-HAS-BARCODE')->sole();

        expect([$blank->barcode, $blank->manufacturer_part_number])->toBe(['036000291452', 'SYN-PN-1'])
            ->and($blank->identifiers_checked_at?->toDateTimeString())->toBe(now()->toDateTimeString())
            ->and([$hasBarcode->barcode, $hasBarcode->manufacturer_part_number])->toBe(['012345678905', 'SYN-PN-2'])
            ->and($catalogRequests)->toHaveCount(1)
            ->and($catalogRequests[0])->toMatchArray([
                'marketplaceIds' => AMAZON_CATALOG_MARKETPLACE,
                'identifiersType' => 'ASIN',
                'includedData' => 'identifiers,summaries',
            ])
            ->and(AmazonCatalogLookup::count())->toBe(0);
    });

    it('looks up a SKU missing only its part number, and one missing only its barcode, but not one checked recently', function (): void {
        $dataSource = amazonCatalogConnection();
        $client = $dataSource->client;
        Product::factory()->create(['client_id' => $client->id, 'sku' => 'SYN-NO-PN', 'barcode' => '012345678905', 'manufacturer_part_number' => null]);
        Product::factory()->create(['client_id' => $client->id, 'sku' => 'SYN-NO-BARCODE', 'barcode' => null, 'manufacturer_part_number' => 'SYN-PN-9']);
        Product::factory()->create(['client_id' => $client->id, 'sku' => 'SYN-RECENT', 'barcode' => null, 'manufacturer_part_number' => null, 'identifiers_checked_at' => now()->subDays(29)]);
        Product::factory()->create(['client_id' => $client->id, 'sku' => 'SYN-COMPLETE', 'barcode' => '036000291452', 'manufacturer_part_number' => 'SYN-PN-8']);
        $catalogRequests = [];

        fakeAmazonCatalog([
            amazonCatalogListing('SYN-NO-PN', '2026-01-01T00:00:00.000Z'),
            amazonCatalogListing('SYN-NO-BARCODE', '2026-01-02T00:00:00.000Z'),
            amazonCatalogListing('SYN-RECENT', '2026-01-03T00:00:00.000Z'),
            amazonCatalogListing('SYN-COMPLETE', '2026-01-04T00:00:00.000Z'),
        ], catalogRequests: $catalogRequests);

        amazonSyncCatalog($dataSource);
        amazonLookUpCatalog($dataSource);

        expect($catalogRequests)->toHaveCount(1)
            ->and(collect(explode(',', $catalogRequests[0]['identifiers']))->sort()->values()->all())
            ->toBe(collect([amazonCatalogAsin('SYN-NO-PN'), amazonCatalogAsin('SYN-NO-BARCODE')])->sort()->values()->all())
            ->and(AmazonCatalogLookup::count())->toBe(0);
    });

    it('looks up a Product whose look-up has expired, moving the stamp forward and keeping its part number when the catalog has none', function (): void {
        $dataSource = amazonCatalogConnection();
        Product::factory()->create([
            'client_id' => $dataSource->client->id,
            'sku' => 'SYN-EXPIRED',
            'barcode' => null,
            'manufacturer_part_number' => 'SYN-PN-KEPT',
            'identifiers_checked_at' => now()->subDays(31),
        ]);

        fakeAmazonCatalog([amazonCatalogListing('SYN-EXPIRED', '2026-01-01T00:00:00.000Z')], catalog: [
            amazonCatalogAsin('SYN-EXPIRED') => amazonCatalogItemFor('SYN-EXPIRED', partNumber: null),
        ]);

        amazonSyncCatalog($dataSource);
        amazonLookUpCatalog($dataSource);

        $product = Product::where('sku', 'SYN-EXPIRED')->sole();

        expect($product->identifiers_checked_at->toDateTimeString())->toBe(now()->toDateTimeString())
            ->and($product->barcode)->toBe('036000291452')
            ->and($product->manufacturer_part_number)->toBe('SYN-PN-KEPT');
    });

    it('logs a failed look-up and leaves the stamp, and the run, as they were', function (): void {
        $dataSource = amazonCatalogConnection();
        $checkedAt = now()->subDays(40)->startOfSecond();
        Product::factory()->create(['client_id' => $dataSource->client->id, 'sku' => 'SYN-FAILS', 'barcode' => null, 'identifiers_checked_at' => $checkedAt]);
        fakeAmazonCatalog([amazonCatalogListing('SYN-FAILS', '2026-01-01T00:00:00.000Z')]);

        amazonSyncCatalog($dataSource);
        $run = amazonCatalogRun($dataSource);

        Saloon::fake([SearchCatalogItems::class => MockResponse::make(['errors' => [['code' => 'Unauthorized']]], 403)]);
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')->once()->withArgs(fn (string $message, array $context): bool => str_contains($message, 'catalog lookup failed during a product catalog sync')
            && $context['data_source_id'] === $dataSource->id
            && $context['asin_count'] === 1);
        Log::shouldReceive('info');

        amazonLookUpCatalog($dataSource);

        expect(Product::where('sku', 'SYN-FAILS')->sole()->identifiers_checked_at->toDateTimeString())->toBe($checkedAt->toDateTimeString())
            ->and(amazonCatalogRun($dataSource)->toArray())->toBe($run->toArray());
    });

    it('does not repeat work a second look-up of the connection finds done', function (): void {
        $dataSource = amazonCatalogConnection();
        $catalogRequests = [];
        fakeAmazonCatalog([amazonCatalogListing('SYN-ONCE', '2026-01-01T00:00:00.000Z')], catalog: [
            amazonCatalogAsin('SYN-ONCE') => amazonCatalogItemFor('SYN-ONCE'),
        ], catalogRequests: $catalogRequests);

        amazonSyncCatalog($dataSource);
        amazonLookUpCatalog($dataSource);
        amazonLookUpCatalog($dataSource);

        expect($catalogRequests)->toHaveCount(1);
    });

    it('runs in slices, handing on what is left', function (): void {
        $dataSource = amazonCatalogConnection();
        $catalogRequests = [];
        $listings = amazonCatalogListings(30);
        fakeAmazonCatalog($listings, catalogRequests: $catalogRequests);
        amazonSyncCatalog($dataSource);

        // The first request uses up the slice.
        Saloon::fake([SearchCatalogItems::class => function (PendingRequest $pendingRequest) use (&$catalogRequests): MockResponse {
            $catalogRequests[] = $pendingRequest->query()->all();
            Carbon::setTestNow(now()->addSeconds(ProductCatalogSyncTiming::SLICE_SECONDS + 1));

            return MockResponse::make(['numberOfResults' => 0, 'items' => []]);
        }]);

        Queue::fake([MakeSearchable::class, LookUpAmazonCatalogIdentifiersJob::class]);
        app()->call([new LookUpAmazonCatalogIdentifiersJob($dataSource->id), 'handle']);

        Queue::assertPushed(LookUpAmazonCatalogIdentifiersJob::class, 1);
        expect($catalogRequests)->toHaveCount(1)
            ->and(AmazonCatalogLookup::count())->toBe(10);

        amazonLookUpCatalog($dataSource);

        expect($catalogRequests)->toHaveCount(2)
            ->and(AmazonCatalogLookup::count())->toBe(0);
    });

    it('takes a lock of its own, and fits a slice', function (): void {
        $this->freezeTime();
        $job = new LookUpAmazonCatalogIdentifiersJob(42);
        $middleware = $job->middleware();

        expect($middleware[0])->toBeInstanceOf(PreventOverlappingImports::class)
            ->and($middleware[0]->key)->toBe('amazon-catalog-lookup:42')
            ->and($job->timeout)->toBe(ProductCatalogSyncTiming::JOB_TIMEOUT)
            ->and($job->maxExceptions)->toBe(1)
            ->and($job->retryUntil()->getTimestamp())->toBe(now()->addSeconds(ProductCatalogSyncTiming::STALE_AFTER_SECONDS)->getTimestamp());
    });
});

describe('the action', function (): void {
    beforeEach(function (): void {
        $this->actingAs(User::factory()->admin()->create());
    });

    it('starts a sync of an active OAuth Amazon connection', function (): void {
        Queue::fake();
        $dataSource = amazonCatalogConnection();

        Livewire::test(EditDataSource::class, ['record' => $dataSource->id])
            ->assertActionVisible('sync_product_catalog')
            ->callAction('sync_product_catalog')
            ->assertNotified('Catalog sync queued');

        expect(amazonCatalogRun($dataSource)->status)->toBe(ProductCatalogSyncStatus::Running);
    });

    it('is not shown on a manual Amazon connection, which says to connect through OAuth', function (): void {
        $dataSource = amazonCatalogConnection(['auth_mode' => 'manual', 'amazon_selling_partner_id' => null]);

        expect($dataSource->syncsProductCatalog())->toBeFalse()
            ->and(app(ProductCatalogSync::class)->start($dataSource))->toBeNull();

        Livewire::test(EditDataSource::class, ['record' => $dataSource->id])
            ->assertActionHidden('sync_product_catalog')
            ->assertSee('Connect this Amazon account through OAuth');
    });

    it('is not shown before a marketplace is chosen', function (): void {
        $dataSource = amazonCatalogConnection(['marketplace_id' => null]);

        Livewire::test(EditDataSource::class, ['record' => $dataSource->id])
            ->assertActionHidden('sync_product_catalog')
            ->assertSee('Choose an Amazon marketplace to sync');
    });
});
