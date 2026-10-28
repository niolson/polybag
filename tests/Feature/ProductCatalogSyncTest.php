<?php

use App\Contracts\DataSourceInterface;
use App\DataTransferObjects\ShipmentImport\ProductCatalogSyncRun;
use App\Enums\ProductCatalogSyncStatus;
use App\Enums\Role;
use App\Exceptions\CatalogReadMustStartOver;
use App\Filament\Resources\DataSources\Pages\EditDataSource;
use App\Filament\Resources\DataSources\Schemas\DataSourceForm;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Http\Integrations\Shopify\Requests\GraphQL;
use App\Jobs\Middleware\PreventOverlappingImports;
use App\Jobs\RunDataSourceImportJob;
use App\Jobs\SyncProductCatalogJob;
use App\Listeners\FailQueuedJobOnFatalError;
use App\Models\Channel;
use App\Models\ChannelAlias;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\DataSourceLocation;
use App\Models\Location;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\User;
use App\Services\ClientContext;
use App\Services\ProductCatalog\ProductCatalogSync;
use App\Services\ProductCatalog\ProductCatalogSyncRuns;
use App\Services\ProductCatalog\ProductCatalogSyncTiming;
use App\Services\ShipmentImport\ShipmentImportService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Scout\Jobs\MakeSearchable;
use Livewire\Livewire;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;

uses(RefreshDatabase::class);

const CATALOG_SHOP = 'catalog-sync-test.myshopify.com';

/**
 * An active Shopify connection to a synthetic store, with a cached token.
 *
 * @param  array<string, mixed>  $attributes
 */
function catalogShopifyConnection(array $attributes = []): DataSource
{
    Cache::put('shopify_access_token_'.md5(CATALOG_SHOP), 'shpat_synthetic_token', 3600);

    return DataSource::factory()->shopify()->create([
        'name' => 'Synthetic Store',
        'settings' => ['shop_domain' => CATALOG_SHOP, 'channel_name' => 'Shopify'],
        'secret_settings' => ['client_id' => 'synthetic-client-id', 'client_secret' => 'synthetic-client-secret'],
        ...$attributes,
    ]);
}

/**
 * A `productVariants` node.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function catalogVariant(array $overrides = []): array
{
    $variant = array_replace_recursive([
        'id' => 'gid://shopify/ProductVariant/900001',
        'sku' => 'SYN-TEE-RED',
        'title' => 'Red',
        'product' => ['title' => 'Synthetic Tee', 'status' => 'ACTIVE'],
        'barcodes' => ['nodes' => [
            ['type' => 'UPC', 'value' => '036000291452'],
            ['type' => 'NS_PID', 'value' => 'SYN-PN-1'],
        ]],
        'inventoryItem' => [
            'harmonizedSystemCode' => '610910',
            'countryCodeOfOrigin' => 'PT',
            'measurement' => ['weight' => ['unit' => 'OUNCES', 'value' => 8]],
        ],
    ], $overrides);

    // A list replaces the default's rather than merging into it.
    if (array_key_exists('barcodes', $overrides)) {
        $variant['barcodes'] = $overrides['barcodes'];
    }

    return $variant;
}

/**
 * A page of `productVariants`.
 *
 * @param  list<array<string, mixed>>  $variants
 */
function catalogPage(array $variants, ?string $nextCursor = null): MockResponse
{
    return MockResponse::make([
        'data' => ['productVariants' => [
            'pageInfo' => ['hasNextPage' => $nextCursor !== null, 'endCursor' => $nextCursor],
            'nodes' => $variants,
        ]],
        'extensions' => ['cost' => ['requestedQueryCost' => 90, 'actualQueryCost' => 30]],
    ]);
}

/**
 * Answer the scope check with `$scopes` (every required scope by default),
 * and catalog reads with `$pages` in order, recording each catalog request.
 * A page given as a closure is called when it is read, then answered with
 * what it returns.
 *
 * @param  list<MockResponse|Closure(): MockResponse>  $pages
 * @param  list<array<string, mixed>>  $requests
 * @param  list<string>|null  $scopes
 */
function fakeCatalogPages(array $pages, array &$requests = [], ?array $scopes = null): void
{
    Saloon::fake([GraphQL::class => function (PendingRequest $pendingRequest) use (&$pages, &$requests, $scopes): MockResponse {
        $body = $pendingRequest->body()?->all() ?? [];

        if (str_contains((string) ($body['query'] ?? ''), 'currentAppInstallation')) {
            return shopifyAccessScopesResponse($scopes);
        }

        $requests[] = $body;
        $page = array_shift($pages) ?? throw new RuntimeException('No more catalog pages faked.');

        return $page instanceof Closure ? $page() : $page;
    }]);
}

/** A catalog page read as a slice's time runs out. */
function lastPageOfSlice(MockResponse $page): Closure
{
    return function () use ($page): MockResponse {
        Carbon::setTestNow(now()->addSeconds(ProductCatalogSyncTiming::SLICE_SECONDS + 1));

        return $page;
    };
}

/** Start a run and work every slice of it in this process; its ID. */
function syncCatalog(DataSource $dataSource): string
{
    $run = app(ProductCatalogSyncRuns::class)->start($dataSource) ?? throw new RuntimeException('A sync is already running.');

    while (app(ProductCatalogSync::class)->runSlice($dataSource->fresh(), $run->runId)) {
        // The next slice.
    }

    return $run->runId;
}

function catalogRun(DataSource $dataSource): ProductCatalogSyncRun
{
    return $dataSource->fresh()->productCatalogSyncRun();
}

beforeEach(function (): void {
    Queue::fake([MakeSearchable::class]);
});

describe('the sync', function (): void {
    it('creates a Product per variant across pages, in the connection\'s client', function (): void {
        $client = Client::factory()->create();
        $dataSource = catalogShopifyConnection(['client_id' => $client->id]);
        $requests = [];

        fakeCatalogPages([
            catalogPage([catalogVariant()], nextCursor: 'cursor-page-2'),
            catalogPage([catalogVariant([
                'id' => 'gid://shopify/ProductVariant/900002',
                'sku' => 'SYN-MUG',
                'title' => 'Default Title',
                'product' => ['title' => 'Synthetic Mug'],
                'barcodes' => ['nodes' => [['type' => 'EAN', 'value' => '4006381333931']]],
                'inventoryItem' => [
                    'harmonizedSystemCode' => '691200',
                    'countryCodeOfOrigin' => 'CN',
                    'measurement' => ['weight' => ['unit' => 'GRAMS', 'value' => 453.59237]],
                ],
            ])]),
        ], $requests);

        syncCatalog($dataSource);

        $tee = Product::where('sku', 'SYN-TEE-RED')->sole();
        $mug = Product::where('sku', 'SYN-MUG')->sole();

        expect($tee->client_id)->toBe($client->id)
            ->and($tee->name)->toBe('Synthetic Tee - Red')
            ->and((float) $tee->weight)->toBe(0.5)
            ->and($tee->barcode)->toBe('036000291452')
            ->and($tee->gtin)->toBe('036000291452')
            ->and($tee->manufacturer_part_number)->toBe('SYN-PN-1')
            ->and($tee->hs_tariff_number)->toBe('610910')
            ->and($tee->country_of_origin)->toBe('PT')
            ->and($mug->client_id)->toBe($client->id)
            ->and($mug->name)->toBe('Synthetic Mug')
            ->and((float) $mug->weight)->toBe(1.0)
            ->and($mug->gtin)->toBe('4006381333931')
            ->and($mug->hs_tariff_number)->toBe('691200')
            ->and($requests)->toHaveCount(2)
            ->and($requests[0]['query'])->toContain('productVariants(first: 250, after: $cursor, query: "product_status:active")')
            ->and($requests[0]['query'])->toContain('measurement { weight { unit value } }')
            ->and($requests[0]['variables'] ?? [])->not->toHaveKey('cursor')
            ->and($requests[1]['variables']['cursor'])->toBe('cursor-page-2');

        $run = catalogRun($dataSource);

        expect($run->status)->toBe(ProductCatalogSyncStatus::Complete)
            ->and([$run->created, $run->updated, $run->unchanged, $run->pages])->toBe([2, 0, 0, 2])
            ->and($run->finishedAt)->not->toBeNull();

        Queue::assertPushedOn('low', MakeSearchable::class);
        Queue::assertPushed(MakeSearchable::class, 2);
    });

    it('lands a connection with no client in the default client', function (): void {
        $dataSource = catalogShopifyConnection(['client_id' => null]);
        fakeCatalogPages([catalogPage([catalogVariant()])]);

        syncCatalog($dataSource);

        expect(Product::where('sku', 'SYN-TEE-RED')->sole()->client_id)->toBe(app(ClientContext::class)->default()->id);
    });

    it('reads the part-number metafield the connection names', function (): void {
        $dataSource = catalogShopifyConnection([
            'settings' => [
                'shop_domain' => CATALOG_SHOP,
                'part_number_metafield' => ['owner' => 'product', 'namespace' => 'custom', 'key' => 'mpn'],
            ],
        ]);
        $requests = [];
        fakeCatalogPages([catalogPage([catalogVariant([
            'barcodes' => ['nodes' => []],
            'product' => ['partNumber' => ['jsonValue' => 'SYN-PRODUCT-MPN']],
        ])])], $requests);

        syncCatalog($dataSource);

        expect(Product::where('sku', 'SYN-TEE-RED')->sole()->manufacturer_part_number)->toBe('SYN-PRODUCT-MPN')
            ->and($requests[0]['query'])->toContain('product { partNumber: metafield(namespace: $pnNamespace, key: $pnKey) { jsonValue } }')
            ->and($requests[0]['variables'])->toMatchArray(['pnNamespace' => 'custom', 'pnKey' => 'mpn']);
    });

    it('skips variants of archived and draft products', function (): void {
        $dataSource = catalogShopifyConnection();
        fakeCatalogPages([catalogPage([
            catalogVariant(),
            catalogVariant(['id' => 'gid://shopify/ProductVariant/900003', 'sku' => 'SYN-ARCHIVED', 'product' => ['status' => 'ARCHIVED']]),
            catalogVariant(['id' => 'gid://shopify/ProductVariant/900004', 'sku' => 'SYN-DRAFT', 'product' => ['status' => 'DRAFT']]),
        ])]);

        syncCatalog($dataSource);

        expect(Product::pluck('sku')->all())->toBe(['SYN-TEE-RED']);
    });

    it('overwrites every field with the store\'s value, but keeps the Product\'s name', function (): void {
        $dataSource = catalogShopifyConnection();
        $product = Product::factory()->create([
            'client_id' => app(ClientContext::class)->default()->id,
            'sku' => 'SYN-TEE-RED',
            'name' => 'Tee, as the order named it',
            'description' => 'Kept: the store sends no description',
            'weight' => 0.75,
            'barcode' => '0000000000017',
            'gtin' => '0000000000017',
            'manufacturer_part_number' => 'OLD-PN',
            'hs_tariff_number' => '610990',
            'country_of_origin' => 'US',
        ]);
        fakeCatalogPages([catalogPage([catalogVariant()])]);

        syncCatalog($dataSource);

        $product->refresh();

        expect($product->name)->toBe('Tee, as the order named it')
            ->and($product->description)->toBe('Kept: the store sends no description')
            ->and((float) $product->weight)->toBe(0.5)
            ->and($product->barcode)->toBe('036000291452')
            ->and($product->gtin)->toBe('036000291452')
            ->and($product->manufacturer_part_number)->toBe('SYN-PN-1')
            ->and($product->hs_tariff_number)->toBe('610910')
            ->and($product->country_of_origin)->toBe('PT')
            ->and(catalogRun($dataSource)->updated)->toBe(1);
    });

    it('names a Product that has no name yet', function (): void {
        $dataSource = catalogShopifyConnection();
        $product = Product::factory()->create(['client_id' => app(ClientContext::class)->default()->id, 'sku' => 'SYN-TEE-RED', 'name' => '']);
        fakeCatalogPages([catalogPage([catalogVariant()])]);

        syncCatalog($dataSource);

        expect($product->refresh()->name)->toBe('Synthetic Tee - Red');
    });

    it('leaves PolyBag\'s values where the store\'s are blank, and its weight where the store\'s is 0', function (): void {
        $dataSource = catalogShopifyConnection();
        $product = Product::factory()->create([
            'client_id' => app(ClientContext::class)->default()->id,
            'sku' => 'SYN-TEE-RED',
            'weight' => 0.75,
            'barcode' => '0000000000017',
            'gtin' => '0000000000017',
            'manufacturer_part_number' => 'KEPT-PN',
            'hs_tariff_number' => '610990',
            'country_of_origin' => 'US',
        ]);
        fakeCatalogPages([catalogPage([catalogVariant([
            'barcodes' => ['nodes' => []],
            'inventoryItem' => [
                'harmonizedSystemCode' => '',
                'countryCodeOfOrigin' => null,
                'measurement' => ['weight' => ['unit' => 'POUNDS', 'value' => 0]],
            ],
        ])])]);

        syncCatalog($dataSource);

        $product->refresh();

        expect((float) $product->weight)->toBe(0.75)
            ->and($product->barcode)->toBe('0000000000017')
            ->and($product->gtin)->toBe('0000000000017')
            ->and($product->manufacturer_part_number)->toBe('KEPT-PN')
            ->and($product->hs_tariff_number)->toBe('610990')
            ->and($product->country_of_origin)->toBe('US');
    });

    it('leaves PolyBag\'s weight alone for a store weight that rounds to 0.00 lb', function (): void {
        $dataSource = catalogShopifyConnection();
        $product = Product::factory()->create(['client_id' => app(ClientContext::class)->default()->id, 'sku' => 'SYN-TEE-RED', 'weight' => 0.75]);
        fakeCatalogPages([catalogPage([catalogVariant(['inventoryItem' => ['measurement' => ['weight' => ['unit' => 'GRAMS', 'value' => 2]]]])])]);

        syncCatalog($dataSource);

        expect((float) $product->refresh()->weight)->toBe(0.75);
    });

    it('replaces a weight of 0 with the store\'s', function (): void {
        $dataSource = catalogShopifyConnection();
        $product = Product::factory()->create(['client_id' => app(ClientContext::class)->default()->id, 'sku' => 'SYN-TEE-RED', 'weight' => 0]);
        fakeCatalogPages([catalogPage([catalogVariant()])]);

        syncCatalog($dataSource);

        expect((float) $product->refresh()->weight)->toBe(0.5);
    });

    it('counts a Product the store has nothing new for as unchanged', function (): void {
        $dataSource = catalogShopifyConnection();
        fakeCatalogPages([catalogPage([catalogVariant()]), catalogPage([catalogVariant()])]);

        syncCatalog($dataSource);
        $second = syncCatalog($dataSource);

        $run = catalogRun($dataSource);

        expect($run->runId)->toBe($second)
            ->and([$run->created, $run->updated, $run->unchanged])->toBe([0, 0, 1]);
    });

    it('cuts a name past the column\'s 255 characters', function (): void {
        $dataSource = catalogShopifyConnection();
        fakeCatalogPages([catalogPage([catalogVariant([
            'title' => 'Ünïcode Large / Midnight Blue / Organic Cotton',
            'product' => ['title' => str_repeat('é', 255)],
        ])])]);

        syncCatalog($dataSource);

        $name = Product::where('sku', 'SYN-TEE-RED')->sole()->name;

        expect(mb_strlen($name))->toBe(255)
            ->and($name)->toBe(str_repeat('é', 255));
    });

    it('gives a SKU-less variant the SKU an order import gives it, so both write one Product', function (): void {
        $dataSource = catalogShopifyConnection();
        tap(Channel::factory()->create(['name' => 'Shopify']), fn (Channel $channel) => ChannelAlias::firstOrCreate(['reference' => 'Shopify'], ['channel_id' => $channel->id]));
        DataSourceLocation::factory()->create([
            'data_source_id' => $dataSource,
            'external_id' => 'gid://shopify/Location/700',
            'location_id' => Location::factory()->create(),
        ]);

        fakeCatalogPages([catalogPage([catalogVariant(['sku' => null, 'id' => 'gid://shopify/ProductVariant/900005', 'title' => 'Default Title'])])]);
        syncCatalog($dataSource);

        expect(Product::sole()->sku)->toBe('SHOPIFY-V-900005');

        $fulfillmentOrder = [
            'id' => 'gid://shopify/FulfillmentOrder/800001',
            'status' => 'OPEN',
            'order' => ['id' => 'gid://shopify/Order/800001', 'name' => '#SYN1', 'email' => 'buyer@example.test'],
            'destination' => ['firstName' => 'Sam', 'lastName' => 'Synthetic', 'address1' => '1 Test Way', 'city' => 'Seattle', 'province' => 'Washington', 'zip' => '98101', 'countryCode' => 'US'],
            'assignedLocation' => ['name' => 'Synthetic Warehouse', 'location' => ['id' => 'gid://shopify/Location/700', 'name' => 'Synthetic Warehouse', 'isActive' => true, 'address' => ['countryCode' => 'US']]],
            'lineItems' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'nodes' => [[
                    'id' => 'gid://shopify/FulfillmentOrderLineItem/800001',
                    'sku' => null, 'productTitle' => 'Synthetic Tee', 'remainingQuantity' => 1, 'requiresShipping' => true,
                    'weight' => ['unit' => 'POUNDS', 'value' => 0.6],
                    'variant' => ['id' => 'gid://shopify/ProductVariant/900005', 'barcodes' => ['nodes' => []]],
                    'lineItem' => ['originalUnitPriceSet' => ['shopMoney' => ['amount' => '12.00']]],
                ]],
            ],
        ];

        Saloon::fake([GraphQL::class => function (PendingRequest $pendingRequest) use ($fulfillmentOrder): MockResponse {
            if (str_contains((string) ($pendingRequest->body()?->all()['query'] ?? ''), 'currentAppInstallation')) {
                return shopifyAccessScopesResponse();
            }

            return MockResponse::make(['data' => ['fulfillmentOrders' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'nodes' => [$fulfillmentOrder],
            ]]]);
        }]);

        $result = ShipmentImportService::forRecord($dataSource->fresh())->import();

        expect($result->errors)->toBe([])
            ->and(Product::count())->toBe(1)
            ->and(Product::sole()->name)->toBe('Synthetic Tee')
            ->and((float) Product::sole()->weight)->toBe(0.6);
    });

    it('fails a run whose token lacks read_products, with what to do about it', function (): void {
        $dataSource = catalogShopifyConnection();
        $requests = [];
        fakeCatalogPages([catalogPage([catalogVariant()])], $requests, scopes: ['read_orders']);

        expect(fn (): string => syncCatalog($dataSource))->toThrow(CatalogReadMustStartOver::class);

        $run = catalogRun($dataSource);

        expect($run->status)->toBe(ProductCatalogSyncStatus::Failed)
            ->and($run->startOver)->toBeTrue()
            ->and($run->error)->toBe('Reconnect Shopify with the read_products scope to sync its product catalog.')
            ->and($run->stoppedAt)->toBe('page 1')
            ->and($requests)->toBe([])
            ->and(Product::count())->toBe(0);
    });

    it('keeps page one saved and queued for indexing when page two fails, and records the run failed', function (): void {
        $dataSource = catalogShopifyConnection();
        fakeCatalogPages([
            catalogPage([catalogVariant()], nextCursor: 'cursor-page-2'),
            MockResponse::make(['errors' => [['message' => 'Internal error', 'extensions' => ['code' => 'BAD_REQUEST']]]]),
        ]);

        expect(fn (): string => syncCatalog($dataSource))->toThrow(RuntimeException::class);

        expect(Product::pluck('sku')->all())->toBe(['SYN-TEE-RED']);
        Queue::assertPushed(MakeSearchable::class, fn (MakeSearchable $job): bool => $job->queue === 'low'
            && $job->models->pluck('sku')->all() === ['SYN-TEE-RED']);

        $run = catalogRun($dataSource);

        expect($run->status)->toBe(ProductCatalogSyncStatus::Failed)
            ->and($run->created)->toBe(1)
            ->and($run->stoppedAt)->toBe('page 2')
            ->and($run->error)->toContain('Internal error')
            ->and($run->finishedAt)->not->toBeNull();
    });

    it('starts nothing, and fails a run in progress, while imports may not write Products', function (): void {
        $dataSource = catalogShopifyConnection();
        $run = app(ProductCatalogSyncRuns::class)->start($dataSource);
        config(['shipment-import.behavior.auto_update_products' => false]);
        fakeCatalogPages([catalogPage([catalogVariant()])]);

        expect(app(ProductCatalogSync::class)->start($dataSource))->toBeNull()
            ->and(fn () => app(ProductCatalogSync::class)->runSlice($dataSource, $run->runId))->toThrow(RuntimeException::class)
            ->and(Product::count())->toBe(0)
            ->and(catalogRun($dataSource)->status)->toBe(ProductCatalogSyncStatus::Failed);
    });
});

describe('slices', function (): void {
    it('runs a sync as a chain of short jobs, each resuming from the saved cursor and keeping the counts', function (): void {
        $queue = Queue::fake();
        $dataSource = catalogShopifyConnection();
        $requests = [];
        fakeCatalogPages([
            catalogPage([catalogVariant()], nextCursor: 'cursor-page-2'),
            lastPageOfSlice(catalogPage([catalogVariant(['id' => 'gid://shopify/ProductVariant/900010', 'sku' => 'SYN-2'])], nextCursor: 'cursor-page-3')),
            lastPageOfSlice(catalogPage([catalogVariant(['id' => 'gid://shopify/ProductVariant/900011', 'sku' => 'SYN-3'])], nextCursor: 'cursor-page-4')),
            catalogPage([catalogVariant(['id' => 'gid://shopify/ProductVariant/900012', 'sku' => 'SYN-4'])]),
        ], $requests);

        $run = app(ProductCatalogSync::class)->start($dataSource, userId: 7);
        $first = $queue->pushed(SyncProductCatalogJob::class)->sole();

        expect($first->runId)->toBe($run->runId)
            ->and($first->connection)->toBe('imports')
            ->and($first->queue)->toBe('imports');

        app()->call([$first, 'handle']);

        $afterFirst = catalogRun($dataSource);
        $second = $queue->pushed(SyncProductCatalogJob::class)->last();

        expect($afterFirst->status)->toBe(ProductCatalogSyncStatus::Running)
            ->and([$afterFirst->pages, $afterFirst->created, $afterFirst->cursor])->toBe([2, 2, 'cursor-page-3'])
            ->and($second)->not->toBe($first)
            ->and([$second->runId, $second->userId])->toBe([$run->runId, 7]);

        app()->call([$second, 'handle']);

        $third = $queue->pushed(SyncProductCatalogJob::class)->last();

        expect(catalogRun($dataSource)->cursor)->toBe('cursor-page-4')
            ->and($requests[2]['variables']['cursor'])->toBe('cursor-page-3');

        app()->call([$third, 'handle']);

        $done = catalogRun($dataSource);

        expect($queue->pushed(SyncProductCatalogJob::class))->toHaveCount(3)
            ->and($done->status)->toBe(ProductCatalogSyncStatus::Complete)
            ->and([$done->pages, $done->created, $done->updated, $done->unchanged])->toBe([4, 4, 0, 0])
            ->and(Product::count())->toBe(4)
            ->and($requests[3]['variables']['cursor'])->toBe('cursor-page-4');
    });

    it('lets an order import queued mid-run go before the sync\'s next slice', function (): void {
        Notification::fake();
        // A real queue, for a real worker: drop the fake this file starts with.
        app()->forgetInstance('queue');
        Queue::clearResolvedInstance('queue');
        config(['queue.connections.imports' => [
            'driver' => 'database',
            'connection' => null,
            'table' => 'jobs',
            'queue' => 'imports',
            'retry_after' => 1860,
        ]]);

        $driver = new class([]) implements DataSourceInterface
        {
            public function __construct(array $config = []) {} // @phpstan-ignore constructor.unusedParameter

            public function fetchShipments(): Collection
            {
                return collect([[
                    'shipment_reference' => 'SYN-ORDER-1', 'first_name' => 'Sam', 'last_name' => 'Synthetic',
                    'address1' => '1 Test Way', 'city' => 'Seattle', 'state_or_province' => 'WA', 'postal_code' => '98101', 'country' => 'US',
                ]]);
            }

            public function fetchShipmentItems(string $sourceRecordId): Collection
            {
                return collect();
            }

            public function validateConfiguration(): void {}

            public function getFieldMapping(): array
            {
                return [];
            }

            public function markExported(string $sourceRecordId): bool
            {
                return false;
            }
        };
        $orders = DataSource::factory()->create(['source_type' => get_class($driver)]);
        $catalog = catalogShopifyConnection();

        fakeCatalogPages([
            function () use ($orders): MockResponse {
                // A sale starts while the sync is on its first slice.
                RunDataSourceImportJob::dispatch($orders->id, null);
                Carbon::setTestNow(now()->addSeconds(ProductCatalogSyncTiming::SLICE_SECONDS + 1));

                return catalogPage([catalogVariant()], nextCursor: 'cursor-page-2');
            },
            catalogPage([catalogVariant(['id' => 'gid://shopify/ProductVariant/900010', 'sku' => 'SYN-2'])]),
        ]);

        app(ProductCatalogSync::class)->start($catalog);
        $work = fn () => $this->artisan('queue:work', ['connection' => 'imports', '--queue' => 'imports', '--once' => true])->assertSuccessful();

        $work();

        expect(catalogRun($catalog)->pages)->toBe(1)
            ->and(Shipment::count())->toBe(0);

        $work();

        expect(Shipment::where('shipment_reference', 'SYN-ORDER-1')->exists())->toBeTrue()
            ->and(catalogRun($catalog)->status)->toBe(ProductCatalogSyncStatus::Running);

        $work();

        expect(catalogRun($catalog)->status)->toBe(ProductCatalogSyncStatus::Complete)
            ->and(Product::count())->toBe(2);
    });

    it('records a failure in a later slice at that slice\'s page, with the counts so far', function (): void {
        $queue = Queue::fake();
        $dataSource = catalogShopifyConnection();
        fakeCatalogPages([
            catalogPage([catalogVariant()], nextCursor: 'cursor-page-2'),
            lastPageOfSlice(catalogPage([catalogVariant(['id' => 'gid://shopify/ProductVariant/900010', 'sku' => 'SYN-2'])], nextCursor: 'cursor-page-3')),
            MockResponse::make(['errors' => [['message' => 'Internal error', 'extensions' => ['code' => 'INTERNAL']]]]),
        ]);

        app(ProductCatalogSync::class)->start($dataSource);
        app()->call([$queue->pushed(SyncProductCatalogJob::class)->sole(), 'handle']);

        expect(fn () => app()->call([$queue->pushed(SyncProductCatalogJob::class)->last(), 'handle']))->toThrow(RuntimeException::class);

        $run = catalogRun($dataSource);

        expect($run->status)->toBe(ProductCatalogSyncStatus::Failed)
            ->and($run->stoppedAt)->toBe('page 3')
            ->and([$run->created, $run->pages])->toBe([2, 2])
            ->and(Product::count())->toBe(2);
    });

    it('fails, and starts the next sync from page one, when Shopify no longer accepts the saved cursor', function (string $message): void {
        $dataSource = catalogShopifyConnection();
        $run = app(ProductCatalogSyncRuns::class)->start($dataSource);
        app(ProductCatalogSyncRuns::class)->update($dataSource, $run->runId, fn (ProductCatalogSyncRun $current): ProductCatalogSyncRun => $current->withPage(250, 0, 0, 'cursor-gone'));
        $requests = [];
        fakeCatalogPages([
            MockResponse::make(['errors' => [['message' => $message, 'extensions' => ['code' => 'BAD_REQUEST']]], 'data' => null]),
        ], $requests);

        expect(fn () => app(ProductCatalogSync::class)->runSlice($dataSource->fresh(), $run->runId))->toThrow(CatalogReadMustStartOver::class);

        $failed = catalogRun($dataSource);

        expect($failed->status)->toBe(ProductCatalogSyncStatus::Failed)
            ->and($failed->error)->toBe('Shopify no longer accepts the place this sync had reached in the catalog. Products saved so far are kept; the next sync starts from the first page.')
            ->and($failed->stoppedAt)->toBe('page 2')
            ->and($failed->created)->toBe(250)
            ->and($failed->canResume())->toBeFalse()
            ->and($requests)->toHaveCount(1)
            ->and($requests[0]['variables']['cursor'])->toBe('cursor-gone');

        $next = app(ProductCatalogSyncRuns::class)->start($dataSource);

        expect([$next->cursor, $next->pages, $next->created, $next->resumedFromPage])->toBe([null, 0, 0, null]);
    })->with(['Malformed cursor', 'Invalid cursor for current pagination sort.']);

    it('does not take another error that mentions a cursor for a refused cursor', function (): void {
        $dataSource = catalogShopifyConnection();
        $run = app(ProductCatalogSyncRuns::class)->start($dataSource);
        app(ProductCatalogSyncRuns::class)->update($dataSource, $run->runId, fn (ProductCatalogSyncRun $current): ProductCatalogSyncRun => $current->withPage(250, 0, 0, 'cursor-page-2'));
        fakeCatalogPages([
            MockResponse::make(['errors' => [['message' => 'Internal error while reading the cursor page', 'extensions' => ['code' => 'INTERNAL_SERVER_ERROR']]], 'data' => null]),
        ]);

        $thrown = null;

        try {
            app(ProductCatalogSync::class)->runSlice($dataSource->fresh(), $run->runId);
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        expect($thrown)->toBeInstanceOf(RuntimeException::class)
            ->and($thrown)->not->toBeInstanceOf(CatalogReadMustStartOver::class)
            ->and(catalogRun($dataSource)->canResume())->toBeTrue();
    });

    it('carries a new sync on from where a failed run stopped, with its counts', function (): void {
        $dataSource = catalogShopifyConnection();
        $runs = app(ProductCatalogSyncRuns::class);
        $failed = $runs->start($dataSource);
        $runs->update($dataSource, $failed->runId, fn (ProductCatalogSyncRun $current): ProductCatalogSyncRun => $current
            ->withPage(250, 3, 1, 'cursor-page-2')
            ->withPage(240, 0, 10, 'cursor-page-3')
            ->failAtNextPage('Shopify GraphQL error'));
        $requests = [];
        fakeCatalogPages([catalogPage([catalogVariant()])], $requests);

        $resumed = $runs->start($dataSource);

        expect($resumed->runId)->not->toBe($failed->runId)
            ->and([$resumed->pages, $resumed->created, $resumed->cursor, $resumed->resumedFromPage])->toBe([2, 490, 'cursor-page-3', 3])
            ->and(DataSourceForm::describeProductCatalogSync($dataSource->fresh()))->toStartWith('Running, resumed at page 3');

        expect(app(ProductCatalogSync::class)->runSlice($dataSource->fresh(), $resumed->runId))->toBeFalse();

        $done = catalogRun($dataSource);

        expect($requests)->toHaveCount(1)
            ->and($requests[0]['variables']['cursor'])->toBe('cursor-page-3')
            ->and($done->status)->toBe(ProductCatalogSyncStatus::Complete)
            ->and([$done->pages, $done->created, $done->updated, $done->unchanged])->toBe([3, 491, 3, 11]);
    });

    it('carries a new sync on from where an interrupted run stopped', function (): void {
        $dataSource = catalogShopifyConnection();
        $runs = app(ProductCatalogSyncRuns::class);
        $lost = $runs->start($dataSource);
        $runs->update($dataSource, $lost->runId, fn (ProductCatalogSyncRun $current): ProductCatalogSyncRun => $current->withPage(250, 0, 0, 'cursor-page-2'));
        $this->travel(ProductCatalogSyncTiming::STALE_AFTER_SECONDS + 1)->seconds();

        $resumed = $runs->start($dataSource);

        expect([$resumed->cursor, $resumed->created, $resumed->resumedFromPage])->toBe(['cursor-page-2', 250, 2]);
    });

    it('starts from page one after a run that completed, or failed before saving a page', function (): void {
        $dataSource = catalogShopifyConnection();
        $runs = app(ProductCatalogSyncRuns::class);
        $complete = $runs->start($dataSource);
        $runs->update($dataSource, $complete->runId, fn (ProductCatalogSyncRun $current): ProductCatalogSyncRun => $current->withPage(1, 0, 0, null)->finish(ProductCatalogSyncStatus::Complete));

        $afterComplete = $runs->start($dataSource);
        $runs->update($dataSource, $afterComplete->runId, fn (ProductCatalogSyncRun $current): ProductCatalogSyncRun => $current->failAtNextPage('Shopify GraphQL error'));
        $afterEarlyFailure = $runs->start($dataSource);

        expect([$afterComplete->cursor, $afterComplete->pages, $afterComplete->resumedFromPage])->toBe([null, 0, null])
            ->and([$afterEarlyFailure->cursor, $afterEarlyFailure->pages])->toBe([null, 0]);
    });

    it('runs a slice that waited in a busy queue far longer than one order import', function (): void {
        $queue = Queue::fake();
        $dataSource = catalogShopifyConnection();
        fakeCatalogPages([catalogPage([catalogVariant()])]);

        app(ProductCatalogSync::class)->start($dataSource);
        $job = $queue->pushed(SyncProductCatalogJob::class)->sole();

        // Behind several connections' half-hour imports.
        $this->travel(3)->hours();
        Cache::put('shopify_access_token_'.md5(CATALOG_SHOP), 'shpat_synthetic_token', 3600);

        expect($job->retryUntil()->isFuture())->toBeTrue()
            ->and(catalogRun($dataSource)->isRunning())->toBeTrue();

        app()->call([$job, 'handle']);

        expect(catalogRun($dataSource)->status)->toBe(ProductCatalogSyncStatus::Complete);
    });

    it('fails the run, and rethrows, when its first slice cannot be queued', function (): void {
        $dataSource = catalogShopifyConnection();
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Connection refused'));

        expect(fn () => app(ProductCatalogSync::class)->start($dataSource))->toThrow(RuntimeException::class, 'Connection refused');

        $run = catalogRun($dataSource);

        expect($run->status)->toBe(ProductCatalogSyncStatus::Failed)
            ->and($run->error)->toBe('The sync could not be queued: Connection refused')
            ->and($run->isRunning())->toBeFalse();
    });

    it('queues the first slice only once the start commits', function (): void {
        $queue = Queue::fake();
        $dataSource = catalogShopifyConnection();

        app(ProductCatalogSync::class)->start($dataSource);

        expect($queue->pushed(SyncProductCatalogJob::class)->sole()->afterCommit)->toBeTrue();
    });

    it('does not start a second sync while one is running, but does once it is stale, and the stale one then stops', function (): void {
        $queue = Queue::fake();
        $dataSource = catalogShopifyConnection();
        $sync = app(ProductCatalogSync::class);

        $first = $sync->start($dataSource);

        expect($sync->start($dataSource))->toBeNull();

        $this->travel(ProductCatalogSyncTiming::STALE_AFTER_SECONDS - 60)->seconds();

        expect($sync->start($dataSource))->toBeNull();

        $this->travel(61)->seconds();

        expect(catalogRun($dataSource)->isInterrupted())->toBeTrue();

        $second = $sync->start($dataSource);

        expect($second)->not->toBeNull()
            ->and($second->runId)->not->toBe($first->runId)
            ->and($queue->pushed(SyncProductCatalogJob::class))->toHaveCount(2);

        // The first run's job, reached late, writes nothing to the new run.
        Saloon::fake([GraphQL::class => fn () => throw new RuntimeException('should not read')]);
        app()->call([$queue->pushed(SyncProductCatalogJob::class)->first(), 'handle']);

        expect(catalogRun($dataSource)->runId)->toBe($second->runId)
            ->and(catalogRun($dataSource)->status)->toBe(ProductCatalogSyncStatus::Running);
    });

    it('rolls back a page whose run was replaced while it was being written', function (): void {
        $dataSource = catalogShopifyConnection();
        $runs = app(ProductCatalogSyncRuns::class);
        $old = $runs->start($dataSource);

        fakeCatalogPages([function () use ($dataSource): MockResponse {
            // The run goes stale mid-page and a new sync replaces it.
            $this->travel(ProductCatalogSyncTiming::STALE_AFTER_SECONDS + 1)->seconds();
            app(ProductCatalogSyncRuns::class)->start($dataSource);

            return catalogPage([catalogVariant()]);
        }]);

        expect(app(ProductCatalogSync::class)->runSlice($dataSource->fresh(), $old->runId))->toBeFalse()
            ->and(Product::count())->toBe(0)
            ->and(catalogRun($dataSource)->runId)->not->toBe($old->runId);
    });
});

describe('the job', function (): void {
    beforeEach(function (): void {
        // Rolling open transactions back for real would undo the one each test runs in.
        $this->partialMock(FailQueuedJobOnFatalError::class, fn ($mock) => $mock->shouldReceive('rollBackOpenTransactions'));
    });

    it('fits one slice and waits no longer than its run takes to go stale', function (): void {
        $this->freezeTime();
        $job = new SyncProductCatalogJob(42, 'run-1', 1);
        $middleware = $job->middleware();

        expect($middleware)->toHaveCount(1)
            ->and($middleware[0])->toBeInstanceOf(PreventOverlappingImports::class)
            ->and($middleware[0]->key)->toBe('product-catalog-sync:42')
            ->and($middleware[0]->releaseAfter)->toBe(60)
            ->and($middleware[0]->expiresAfter)->toBe(480)
            ->and($job->timeout)->toBe(420)
            ->and($job->timeout)->toBeLessThan(config('queue.connections.imports.retry_after'))
            ->and($job->maxExceptions)->toBe(1)
            ->and($job->failOnTimeout)->toBeTrue()
            ->and($job->connection)->toBe('imports')
            ->and($job->queue)->toBe('imports')
            ->and($job->retryUntil()->getTimestamp())->toBe(now()->addSeconds(ProductCatalogSyncTiming::STALE_AFTER_SECONDS)->getTimestamp());
    });

    it('waits behind another slice of the connection\'s sync', function (): void {
        Cache::lock('product-catalog-sync:42', 360)->get();
        $job = (new SyncProductCatalogJob(42, 'run-1'))->withFakeQueueInteractions();

        $job->middleware()[0]->handle($job, fn () => throw new RuntimeException('should not run'));

        $job->assertReleased(delay: 60);
        $job->assertNotFailed();
    });

    it('takes a different lock from an order import of the connection', function (): void {
        Cache::lock('data-source-import:42', 1860)->get();
        $job = (new SyncProductCatalogJob(42, 'run-1'))->withFakeQueueInteractions();
        $ran = false;

        $job->middleware()[0]->handle($job, function () use (&$ran): void {
            $ran = true;
        });

        expect($ran)->toBeTrue();
        $job->assertNotReleased();
    });

    it('records a slice that timed out as failed at its page, keeping the counts', function (): void {
        $dataSource = catalogShopifyConnection();
        $runs = app(ProductCatalogSyncRuns::class);
        $run = $runs->start($dataSource);
        $runs->update($dataSource, $run->runId, fn (ProductCatalogSyncRun $current): ProductCatalogSyncRun => $current->withPage(3, 1, 0, 'cursor-page-2'));

        (new SyncProductCatalogJob($dataSource->id, $run->runId))->failed(new TimeoutExceededException('App\\Jobs\\SyncProductCatalogJob has timed out.'));

        $failed = catalogRun($dataSource);

        expect($failed->status)->toBe(ProductCatalogSyncStatus::Failed)
            ->and([$failed->created, $failed->updated])->toBe([3, 1])
            ->and($failed->stoppedAt)->toBe('page 2')
            ->and($failed->error)->toBe('Part of the sync took longer than 7 minutes and was stopped. Products it had already saved are kept.');
    });

    it('rolls back open transactions before it records the failure', function (): void {
        // Rolling them back for real would undo the transaction each test runs in.
        $dataSource = catalogShopifyConnection();
        $run = app(ProductCatalogSyncRuns::class)->start($dataSource);
        $rolledBack = false;
        $this->mock(FailQueuedJobOnFatalError::class)
            ->shouldReceive('rollBackOpenTransactions')
            ->once()
            ->andReturnUsing(function () use (&$rolledBack, $dataSource): void {
                $rolledBack = catalogRun($dataSource)->status === ProductCatalogSyncStatus::Running;
            });

        (new SyncProductCatalogJob($dataSource->id, $run->runId))->failed(new TimeoutExceededException('App\\Jobs\\SyncProductCatalogJob has timed out.'));

        expect($rolledBack)->toBeTrue()
            ->and(catalogRun($dataSource)->status)->toBe(ProductCatalogSyncStatus::Failed);
    });

    it('leaves another run alone when it fails', function (): void {
        $dataSource = catalogShopifyConnection();
        app(ProductCatalogSyncRuns::class)->start($dataSource);

        (new SyncProductCatalogJob($dataSource->id, 'some-other-run'))->failed(new MaxAttemptsExceededException('has been attempted too many times.'));

        expect(catalogRun($dataSource)->status)->toBe(ProductCatalogSyncStatus::Running);
    });

    it('leaves a finished run alone when it fails', function (): void {
        $dataSource = catalogShopifyConnection();
        $runs = app(ProductCatalogSyncRuns::class);
        $run = $runs->start($dataSource);
        $runs->update($dataSource, $run->runId, fn (ProductCatalogSyncRun $current): ProductCatalogSyncRun => $current->finish(ProductCatalogSyncStatus::Complete));

        (new SyncProductCatalogJob($dataSource->id, $run->runId))->failed(new RuntimeException('late'));

        expect(catalogRun($dataSource)->status)->toBe(ProductCatalogSyncStatus::Complete);
    });

    it('stops a run whose connection was deactivated', function (): void {
        $dataSource = catalogShopifyConnection();
        $run = app(ProductCatalogSyncRuns::class)->start($dataSource);
        $dataSource->update(['active' => false]);

        app()->call([new SyncProductCatalogJob($dataSource->id, $run->runId), 'handle']);

        expect(catalogRun($dataSource)->status)->toBe(ProductCatalogSyncStatus::Failed);
    });

    it('runs on a connection that imports no orders', function (): void {
        $queue = Queue::fake();
        $dataSource = catalogShopifyConnection(['import_enabled' => false]);
        fakeCatalogPages([catalogPage([catalogVariant()])]);

        app(ProductCatalogSync::class)->start($dataSource);
        app()->call([$queue->pushed(SyncProductCatalogJob::class)->sole(), 'handle']);

        expect(Product::where('sku', 'SYN-TEE-RED')->exists())->toBeTrue();
    });

    it('starts no sync of a connection that cannot list a catalog', function (array $attributes): void {
        $queue = Queue::fake();
        $dataSource = DataSource::factory()->create($attributes);

        expect(app(ProductCatalogSync::class)->start($dataSource))->toBeNull()
            ->and($dataSource->fresh()->productCatalogSyncRun())->toBeNull();
        Queue::assertNothingPushed();
    })->with([
        'inactive Shopify' => [['source_type' => ShopifySource::class, 'active' => false]],
        'Amazon' => [['source_type' => AmazonSource::class]],
        'Database' => [[]],
    ]);
});

describe('the run status', function (): void {
    it('shows a running sync as interrupted once it has saved no page for too long', function (): void {
        $dataSource = catalogShopifyConnection();
        $runs = app(ProductCatalogSyncRuns::class);
        $run = $runs->start($dataSource);

        // A long run that keeps saving pages is not interrupted, however long ago it started.
        $this->travel(ProductCatalogSyncTiming::STALE_AFTER_SECONDS)->seconds();
        $runs->update($dataSource, $run->runId, fn (ProductCatalogSyncRun $current): ProductCatalogSyncRun => $current->withPage(4, 1, 0, 'cursor-page-2'));
        $this->travel(ProductCatalogSyncTiming::STALE_AFTER_SECONDS - 1)->seconds();

        expect(catalogRun($dataSource)->isRunning())->toBeTrue()
            ->and(DataSourceForm::describeProductCatalogSync($dataSource->fresh()))->toStartWith('Running');

        $this->travel(2)->seconds();

        expect(catalogRun($dataSource)->isInterrupted())->toBeTrue()
            ->and(catalogRun($dataSource)->isRunning())->toBeFalse()
            ->and(DataSourceForm::describeProductCatalogSync($dataSource->fresh()))
            ->toStartWith('Interrupted')
            ->toContain('1 page: 4 created, 1 updated, 0 unchanged')
            ->toContain('a new sync carries on from where it stopped');
    });

    it('shows where a failed run stopped and why', function (): void {
        $dataSource = catalogShopifyConnection();
        $dataSource->recordProductCatalogSyncRun(ProductCatalogSyncRun::start('run-1')
            ->withPage(250, 0, 0, 'cursor-page-2')
            ->failAtNextPage('Shopify GraphQL error'));

        expect(DataSourceForm::describeProductCatalogSync($dataSource))
            ->toStartWith('Failed')
            ->toContain('250 created')
            ->toContain('Stopped at page 2.')
            ->toContain('Shopify GraphQL error');
    });
});

describe('the action', function (): void {
    beforeEach(function (): void {
        $this->actingAs(User::factory()->admin()->create());
    });

    it('starts a sync of an active Shopify connection, whether or not it imports orders, and shows it at once', function (bool $importEnabled): void {
        $queue = Queue::fake();
        $dataSource = catalogShopifyConnection(['import_enabled' => $importEnabled]);

        Livewire::test(EditDataSource::class, ['record' => $dataSource->id])
            ->assertActionVisible('sync_product_catalog')
            ->callAction('sync_product_catalog')
            ->assertNotified('Catalog sync queued')
            ->assertActionDisabled('sync_product_catalog')
            ->assertSee('Running, started');

        Queue::assertPushed(SyncProductCatalogJob::class, fn (SyncProductCatalogJob $job): bool => $job->dataSourceId === $dataSource->id
            && $job->runId === catalogRun($dataSource)->runId
            && $job->connection === 'imports');
    })->with(['importing' => true, 'not importing' => false]);

    it('queues one sync when started from two tabs', function (): void {
        $queue = Queue::fake();
        $dataSource = catalogShopifyConnection();
        $firstTab = Livewire::test(EditDataSource::class, ['record' => $dataSource->id]);
        $secondTab = Livewire::test(EditDataSource::class, ['record' => $dataSource->id]);

        $staleRecord = $dataSource->fresh();

        $firstTab->callAction('sync_product_catalog')->assertNotified('Catalog sync queued');
        $runId = catalogRun($dataSource)->runId;

        // The second tab's button, drawn before the first started, calls the
        // action, which finds the run; so does a start from a copy of the
        // connection read before the first.
        $secondTab->callAction('sync_product_catalog');

        expect($staleRecord->productCatalogSyncRun())->toBeNull()
            ->and(app(ProductCatalogSync::class)->start($staleRecord))->toBeNull();

        Queue::assertPushed(SyncProductCatalogJob::class, 1);
        expect(catalogRun($dataSource)->runId)->toBe($runId);
    });

    it('is not shown on an inactive or non-Shopify connection', function (array $attributes): void {
        $dataSource = DataSource::factory()->create($attributes);

        Livewire::test(EditDataSource::class, ['record' => $dataSource->id])
            ->assertActionHidden('sync_product_catalog');
    })->with([
        'inactive Shopify' => [['source_type' => ShopifySource::class, 'active' => false, 'settings' => ['shop_domain' => CATALOG_SHOP]]],
        'Database' => [[]],
    ]);

    it('is hidden, and says why, while imports may not write Products', function (): void {
        config(['shipment-import.behavior.auto_update_products' => false]);
        $dataSource = catalogShopifyConnection();

        Livewire::test(EditDataSource::class, ['record' => $dataSource->id])
            ->assertActionHidden('sync_product_catalog')
            ->assertSee('Catalog sync is off');
    });

    it('is disabled while a sync is running, and enabled once it is interrupted', function (): void {
        $dataSource = catalogShopifyConnection();
        app(ProductCatalogSyncRuns::class)->start($dataSource);

        Livewire::test(EditDataSource::class, ['record' => $dataSource->id])
            ->assertActionDisabled('sync_product_catalog');

        $this->travel(ProductCatalogSyncTiming::STALE_AFTER_SECONDS + 1)->seconds();

        Livewire::test(EditDataSource::class, ['record' => $dataSource->id])
            ->assertActionEnabled('sync_product_catalog');
    });

    it('is not shown to a Manager', function (): void {
        $dataSource = catalogShopifyConnection();
        $this->actingAs(User::factory()->create(['role' => Role::Manager]));

        $this->get(EditDataSource::getUrl(['record' => $dataSource->id]))->assertForbidden();
    });

    it('is offered once after a new connection is connected, never started', function (): void {
        $queue = Queue::fake();
        $dataSource = catalogShopifyConnection();
        session()->flash('offer_product_catalog_sync', $dataSource->id);

        Livewire::test(EditDataSource::class, ['record' => $dataSource->id])
            ->assertSet('defaultAction', 'sync_product_catalog');

        Queue::assertNotPushed(SyncProductCatalogJob::class);
    });

    it('is not offered again to a connection that has synced', function (): void {
        $dataSource = catalogShopifyConnection();
        $dataSource->recordProductCatalogSyncRun(ProductCatalogSyncRun::start('run-1')->finish(ProductCatalogSyncStatus::Complete));
        session()->flash('offer_product_catalog_sync', $dataSource->id);

        Livewire::test(EditDataSource::class, ['record' => $dataSource->id])
            ->assertSet('defaultAction', null);
    });
});

describe('Incomplete Customs Data', function (): void {
    it('lists a synced Product on no Package that lacks an HS code or a weight, which Missing Customs Data does not', function (): void {
        $this->actingAs(User::factory()->admin()->create());
        $dataSource = catalogShopifyConnection();
        fakeCatalogPages([catalogPage([
            catalogVariant(['inventoryItem' => ['harmonizedSystemCode' => null]]),
            catalogVariant(['id' => 'gid://shopify/ProductVariant/900006', 'sku' => 'SYN-COMPLETE']),
            catalogVariant(['id' => 'gid://shopify/ProductVariant/900007', 'sku' => 'SYN-NO-WEIGHT', 'inventoryItem' => ['measurement' => ['weight' => ['value' => 0]]]]),
        ])]);

        syncCatalog($dataSource);

        $noHsCode = Product::where('sku', 'SYN-TEE-RED')->sole();
        $noWeight = Product::where('sku', 'SYN-NO-WEIGHT')->sole();
        $complete = Product::where('sku', 'SYN-COMPLETE')->sole();
        $zeroWeight = Product::factory()->create(['weight' => 0]);

        expect($noHsCode->hs_tariff_number)->toBeNull()
            ->and($noWeight->weight)->toBeNull();

        Livewire::test(ListProducts::class)
            ->filterTable('incomplete_customs_data')
            ->assertCanSeeTableRecords([$noHsCode, $noWeight, $zeroWeight])
            ->assertCanNotSeeTableRecords([$complete]);

        Livewire::test(ListProducts::class)
            ->filterTable('missing_customs_data')
            ->assertCanNotSeeTableRecords([$noHsCode, $noWeight, $complete]);
    });
});
