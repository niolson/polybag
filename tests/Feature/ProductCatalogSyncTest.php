<?php

use App\DataTransferObjects\ShipmentImport\ProductCatalogSyncRun;
use App\Enums\ProductCatalogSyncStatus;
use App\Enums\Role;
use App\Filament\Resources\DataSources\Pages\EditDataSource;
use App\Filament\Resources\DataSources\Schemas\DataSourceForm;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Http\Integrations\Shopify\Requests\GraphQL;
use App\Jobs\Middleware\PreventOverlappingImports;
use App\Jobs\SyncProductCatalogJob;
use App\Models\Channel;
use App\Models\ChannelAlias;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\DataSourceLocation;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use App\Services\ClientContext;
use App\Services\ProductCatalog\ProductCatalogSync;
use App\Services\ShipmentImport\ShipmentImportService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Cache;
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
 * Answer catalog reads with `$pages` in order, recording each request body.
 *
 * @param  list<MockResponse>  $pages
 * @param  list<array<string, mixed>>  $requests
 */
function fakeCatalogPages(array $pages, array &$requests = []): void
{
    Saloon::fake([GraphQL::class => function (PendingRequest $pendingRequest) use (&$pages, &$requests): MockResponse {
        $requests[] = $pendingRequest->body()?->all() ?? [];

        return array_shift($pages) ?? throw new RuntimeException('No more catalog pages faked.');
    }]);
}

function syncCatalog(DataSource $dataSource, string $runId = 'run-1'): void
{
    app(ProductCatalogSync::class)->run($dataSource->fresh(), $runId);
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

        $run = $dataSource->fresh()->productCatalogSyncRun();

        expect($run->status)->toBe(ProductCatalogSyncStatus::Complete)
            ->and($run->created)->toBe(2)
            ->and($run->updated)->toBe(0)
            ->and($run->unchanged)->toBe(0)
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

    it('fills blanks only, keeping a hand-entered name, weight and HS code', function (): void {
        $dataSource = catalogShopifyConnection();
        $product = Product::factory()->create([
            'client_id' => app(ClientContext::class)->default()->id,
            'sku' => 'SYN-TEE-RED',
            'name' => 'Tee, as the packer calls it',
            'weight' => 0.75,
            'hs_tariff_number' => '610990',
            'country_of_origin' => null,
            'barcode' => null,
            'gtin' => null,
            'manufacturer_part_number' => null,
        ]);
        fakeCatalogPages([catalogPage([catalogVariant()])]);

        syncCatalog($dataSource);

        $product->refresh();

        expect($product->name)->toBe('Tee, as the packer calls it')
            ->and((float) $product->weight)->toBe(0.75)
            ->and($product->hs_tariff_number)->toBe('610990')
            ->and($product->country_of_origin)->toBe('PT')
            ->and($product->barcode)->toBe('036000291452')
            ->and($dataSource->fresh()->productCatalogSyncRun()->updated)->toBe(1);
    });

    it('counts a Product the sync has nothing to add to as unchanged', function (): void {
        $dataSource = catalogShopifyConnection();
        fakeCatalogPages([catalogPage([catalogVariant()]), catalogPage([catalogVariant()])]);

        syncCatalog($dataSource, 'run-1');
        syncCatalog($dataSource, 'run-2');

        $run = $dataSource->fresh()->productCatalogSyncRun();

        expect($run->runId)->toBe('run-2')
            ->and([$run->created, $run->updated, $run->unchanged])->toBe([0, 0, 1]);
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

    it('keeps page one saved and queued for indexing when page two fails, and records the run failed', function (): void {
        $dataSource = catalogShopifyConnection();
        fakeCatalogPages([
            catalogPage([catalogVariant()], nextCursor: 'cursor-page-2'),
            MockResponse::make(['errors' => [['message' => 'Internal error', 'extensions' => ['code' => 'BAD_REQUEST']]]]),
        ]);

        expect(fn () => syncCatalog($dataSource))->toThrow(RuntimeException::class);

        expect(Product::pluck('sku')->all())->toBe(['SYN-TEE-RED']);
        Queue::assertPushed(MakeSearchable::class, fn (MakeSearchable $job): bool => $job->queue === 'low'
            && $job->models->pluck('sku')->all() === ['SYN-TEE-RED']);

        $run = $dataSource->fresh()->productCatalogSyncRun();

        expect($run->status)->toBe(ProductCatalogSyncStatus::Failed)
            ->and($run->created)->toBe(1)
            ->and($run->stoppedAt)->toBe('page 2')
            ->and($run->error)->toContain('Internal error')
            ->and($run->finishedAt)->not->toBeNull();
    });

    it('writes nothing, and records the run failed, while imports may not write Products', function (): void {
        config(['shipment-import.behavior.auto_update_products' => false]);
        $dataSource = catalogShopifyConnection();
        fakeCatalogPages([catalogPage([catalogVariant()])]);

        expect(fn () => syncCatalog($dataSource))->toThrow(RuntimeException::class);

        expect(Product::count())->toBe(0)
            ->and($dataSource->fresh()->productCatalogSyncRun()->status)->toBe(ProductCatalogSyncStatus::Failed);
    });
});

describe('the job', function (): void {
    it('is configured like an order import, on a lock of its own', function (): void {
        $job = new SyncProductCatalogJob(42, 1);
        $middleware = $job->middleware();

        expect($middleware)->toHaveCount(1)
            ->and($middleware[0])->toBeInstanceOf(PreventOverlappingImports::class)
            ->and($middleware[0]->key)->toBe('product-catalog-sync:42')
            ->and($middleware[0]->releaseAfter)->toBe(60)
            ->and($middleware[0]->expiresAfter)->toBe(1860)
            ->and($job->timeout)->toBe(1800)
            ->and($job->maxExceptions)->toBe(1)
            ->and($job->failOnTimeout)->toBeTrue()
            ->and($job->connection)->toBe('imports')
            ->and($job->queue)->toBe('imports')
            ->and($job->runId)->not->toBe((new SyncProductCatalogJob(42, 1))->runId);
    });

    it('waits behind another sync by time, not attempts', function (): void {
        $this->freezeTime();

        expect((new SyncProductCatalogJob(42, 1))->retryUntil()->getTimestamp())->toBe(now()->addSeconds(2100)->getTimestamp());
    });

    it('does not run a second sync of a connection while one is running', function (): void {
        Cache::lock('product-catalog-sync:42', 1860)->get();
        $job = (new SyncProductCatalogJob(42, 1))->withFakeQueueInteractions();

        $job->middleware()[0]->handle($job, fn () => throw new RuntimeException('should not run'));

        $job->assertReleased(delay: 60);
        $job->assertNotFailed();
    });

    it('runs beside an order import of the same connection', function (): void {
        Cache::lock('data-source-import:42', 1860)->get();
        $job = (new SyncProductCatalogJob(42, 1))->withFakeQueueInteractions();
        $ran = false;

        $job->middleware()[0]->handle($job, function () use (&$ran): void {
            $ran = true;
        });

        expect($ran)->toBeTrue();
        $job->assertNotReleased();
    });

    it('records a killed run failed, and runs a new sync once the lock expires', function (): void {
        $dataSource = catalogShopifyConnection();
        $killed = new SyncProductCatalogJob($dataSource->id, 1);

        // The killed worker took the lock and started its run; a worker killed
        // on its timeout releases nothing.
        Cache::lock('product-catalog-sync:'.$dataSource->id, SyncProductCatalogJob::LOCK_SECONDS)->get();
        $dataSource->recordProductCatalogSyncRun(ProductCatalogSyncRun::start($killed->runId)->withPage(3, 0, 0));
        $killed->failed(new TimeoutExceededException('App\\Jobs\\SyncProductCatalogJob has timed out.'));

        $run = $dataSource->fresh()->productCatalogSyncRun();

        expect($run->status)->toBe(ProductCatalogSyncStatus::Failed)
            ->and($run->created)->toBe(3)
            ->and($run->error)->toBe('The sync took longer than 30 minutes and was stopped. Products it had already saved are kept.');

        $next = (new SyncProductCatalogJob($dataSource->id, 1))->withFakeQueueInteractions();
        $next->middleware()[0]->handle($next, fn () => throw new RuntimeException('should wait'));
        $next->assertReleased(delay: 60);

        $this->travel(SyncProductCatalogJob::LOCK_SECONDS + 1)->seconds();

        fakeCatalogPages([catalogPage([catalogVariant()])]);
        $later = (new SyncProductCatalogJob($dataSource->id, 1))->withFakeQueueInteractions();
        $later->middleware()[0]->handle($later, fn () => app()->call([$later, 'handle']));

        $later->assertNotReleased();
        expect($dataSource->fresh()->productCatalogSyncRun()->status)->toBe(ProductCatalogSyncStatus::Complete)
            ->and(Product::where('sku', 'SYN-TEE-RED')->exists())->toBeTrue();
    });

    it('leaves a running sync alone when a sync waiting behind it fails', function (): void {
        $dataSource = catalogShopifyConnection();
        $running = new SyncProductCatalogJob($dataSource->id, 1);
        $dataSource->recordProductCatalogSyncRun(ProductCatalogSyncRun::start($running->runId));

        (new SyncProductCatalogJob($dataSource->id, 1))->failed(new MaxAttemptsExceededException('has been attempted too many times.'));

        expect($dataSource->fresh()->productCatalogSyncRun()->status)->toBe(ProductCatalogSyncStatus::Running);
    });

    it('does nothing for an inactive connection or one that cannot list a catalog', function (array $attributes): void {
        $dataSource = DataSource::factory()->create($attributes);

        app()->call([new SyncProductCatalogJob($dataSource->id, 1), 'handle']);

        expect($dataSource->fresh()->productCatalogSyncRun())->toBeNull();
    })->with([
        'inactive Shopify' => [['source_type' => ShopifySource::class, 'active' => false]],
        'Amazon' => [['source_type' => AmazonSource::class]],
        'Database' => [[]],
    ]);

    it('runs on a connection that imports no orders', function (): void {
        $dataSource = catalogShopifyConnection(['import_enabled' => false]);
        fakeCatalogPages([catalogPage([catalogVariant()])]);

        app()->call([new SyncProductCatalogJob($dataSource->id, 1), 'handle']);

        expect(Product::where('sku', 'SYN-TEE-RED')->exists())->toBeTrue();
    });
});

describe('the run status', function (): void {
    it('shows a run still marked running after its lock expired as interrupted', function (): void {
        $dataSource = catalogShopifyConnection();
        $dataSource->recordProductCatalogSyncRun(ProductCatalogSyncRun::start('run-killed')->withPage(4, 1, 0));

        expect($dataSource->productCatalogSyncRun()->isRunning())->toBeTrue()
            ->and(DataSourceForm::describeProductCatalogSync($dataSource))->toStartWith('Running');

        $this->travel(SyncProductCatalogJob::LOCK_SECONDS + 1)->seconds();

        expect($dataSource->productCatalogSyncRun()->isInterrupted())->toBeTrue()
            ->and($dataSource->productCatalogSyncRun()->isRunning())->toBeFalse()
            ->and(DataSourceForm::describeProductCatalogSync($dataSource))
            ->toStartWith('Interrupted')
            ->toContain('4 created, 1 updated, 0 unchanged')
            ->toContain('a new sync can start');
    });

    it('shows where a failed run stopped and why', function (): void {
        $dataSource = catalogShopifyConnection();
        $dataSource->recordProductCatalogSyncRun(ProductCatalogSyncRun::start('run-1')
            ->withPage(250, 0, 0)
            ->finish(ProductCatalogSyncStatus::Failed, stoppedAt: 'page 2', error: 'Shopify GraphQL error'));

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

    it('queues a sync of an active Shopify connection, whether or not it imports orders', function (bool $importEnabled): void {
        Queue::fake();
        $dataSource = catalogShopifyConnection(['import_enabled' => $importEnabled]);

        Livewire::test(EditDataSource::class, ['record' => $dataSource->id])
            ->assertActionVisible('sync_product_catalog')
            ->callAction('sync_product_catalog')
            ->assertNotified('Catalog sync queued');

        Queue::assertPushed(SyncProductCatalogJob::class, fn (SyncProductCatalogJob $job): bool => $job->dataSourceId === $dataSource->id
            && $job->connection === 'imports');
    })->with(['importing' => true, 'not importing' => false]);

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
        $dataSource->recordProductCatalogSyncRun(ProductCatalogSyncRun::start('run-1'));

        Livewire::test(EditDataSource::class, ['record' => $dataSource->id])
            ->assertActionDisabled('sync_product_catalog');

        $this->travel(SyncProductCatalogJob::LOCK_SECONDS + 1)->seconds();

        Livewire::test(EditDataSource::class, ['record' => $dataSource->id])
            ->assertActionEnabled('sync_product_catalog');
    });

    it('is not shown to a Manager', function (): void {
        $dataSource = catalogShopifyConnection();
        $this->actingAs(User::factory()->create(['role' => Role::Manager]));

        $this->get(EditDataSource::getUrl(['record' => $dataSource->id]))->assertForbidden();
    });

    it('is offered once after a new connection is connected, never started', function (): void {
        Queue::fake();
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
    it('lists a synced Product on no Package that lacks an HS code, which Missing Customs Data does not', function (): void {
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

        expect($noHsCode->hs_tariff_number)->toBeNull();

        Livewire::test(ListProducts::class)
            ->filterTable('incomplete_customs_data')
            ->assertCanSeeTableRecords([$noHsCode, $noWeight])
            ->assertCanNotSeeTableRecords([$complete]);

        Livewire::test(ListProducts::class)
            ->filterTable('missing_customs_data')
            ->assertCanNotSeeTableRecords([$noHsCode, $noWeight, $complete]);
    });
});
