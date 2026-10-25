<?php

use App\Enums\ShipmentStatus;
use App\Http\Integrations\Shopify\Requests\GraphQL;
use App\Models\Channel;
use App\Models\ChannelAlias;
use App\Models\DataSource;
use App\Models\DataSourceLocation;
use App\Models\Location;
use App\Models\Shipment;
use App\Services\ShipmentImport\ImportResult;
use App\Services\ShipmentImport\ShipmentImportService;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Http\Response;
use Saloon\Laravel\Facades\Saloon;

/**
 * `import-memory/01`: a Shopify import re-points and closes over every
 * fulfillment order the run reads, however many pages they arrive on.
 */
beforeEach(function (): void {
    Cache::put('shopify_access_token_'.md5('test-shop.myshopify.com'), 'shpat_test_token', 3600);

    $this->dataSource = DataSource::factory()->create([
        'source_type' => ShopifySource::class,
        'name' => 'Shopify',
        'settings' => ['channel_name' => 'Shopify', 'shop_domain' => 'test-shop.myshopify.com'],
        'secret_settings' => ['client_id' => 'test-client-id', 'client_secret' => 'test-client-secret'],
    ]);

    tap(Channel::factory()->create(['name' => 'Shopify']), fn (Channel $channel) => ChannelAlias::firstOrCreate(
        ['reference' => 'Shopify'],
        ['channel_id' => $channel->id],
    ));

    DataSourceLocation::factory()->create([
        'data_source_id' => $this->dataSource,
        'external_id' => 'gid://shopify/Location/1',
        'location_id' => Location::factory(),
    ]);

    DataSourceLocation::factory()->create([
        'data_source_id' => $this->dataSource,
        'external_id' => 'gid://shopify/Location/9',
        'location_id' => null,
        'ignored_at' => now(),
    ]);

    // One Shipment to a write, so a page's rows are written apart from the next page's.
    config(['shipment-import.behavior.batch_size' => 1]);
});

function pagedFoId(string $id): string
{
    return "gid://shopify/FulfillmentOrder/{$id}";
}

/**
 * An open fulfillment order as the import reads it. Its goods are one of
 * the order's SKU, so two fulfillment orders of one order hold the same goods.
 *
 * @return array<string, mixed>
 */
function pagedNode(string $id, ?string $orderId = null, string $locationId = '1', string $status = 'OPEN'): array
{
    $orderId ??= $id;

    return [
        'id' => pagedFoId($id),
        'status' => $status,
        'order' => ['id' => "gid://shopify/Order/{$orderId}", 'name' => "#{$orderId}", 'email' => 'test@example.com'],
        'destination' => [
            'firstName' => 'Jane', 'lastName' => 'Smith', 'address1' => '456 Oak Ave',
            'city' => 'Seattle', 'province' => 'WA', 'zip' => '98101', 'countryCode' => 'US',
        ],
        'assignedLocation' => [
            'name' => "Warehouse {$locationId}",
            'location' => [
                'id' => "gid://shopify/Location/{$locationId}",
                'name' => "Warehouse {$locationId}",
                'isActive' => true,
                'address' => ['city' => 'Seattle', 'countryCode' => 'US'],
            ],
        ],
        'lineItems' => [
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            'nodes' => [[
                'id' => "gid://shopify/FulfillmentOrderLineItem/{$id}",
                'sku' => "SKU-{$orderId}", 'productTitle' => 'Widget', 'remainingQuantity' => 1,
                'requiresShipping' => true, 'weight' => null,
                'variant' => ['id' => "gid://shopify/ProductVariant/{$orderId}", 'barcodes' => ['nodes' => []]],
                'lineItem' => ['originalUnitPriceSet' => ['shopMoney' => ['amount' => '10.00']]],
            ]],
        ],
    ];
}

/** A fulfillment order as the closing lookup reads it. */
function pagedLookupNode(string $id, string $status, string $locationId = '1'): array
{
    return [
        'id' => pagedFoId($id),
        'status' => $status,
        'assignedLocation' => ['location' => ['id' => "gid://shopify/Location/{$locationId}"]],
        'order' => ['cancelledAt' => null],
        'fulfillments' => ['nodes' => []],
    ];
}

/**
 * Answer a Shopify import: the full read from `$pages` in order, a read of
 * fulfillment orders by ID from `$nodes`, and the closing lookup from
 * `$lookups`, each keyed by fulfillment order ID. An ID missing from either
 * comes back null, as a deleted one does.
 *
 * `$changedOrders` answers a search for changes; the token holds
 * `read_all_orders` only when it is given.
 *
 * @param  list<list<array<string, mixed>>>  $pages
 * @param  array<string, array<string, mixed>>  $lookups
 * @param  array<string, array<string, mixed>>  $nodes
 * @param  list<array<string, mixed>>|null  $changedOrders
 * @return array{result: ImportResult, lookups: list<list<string>>}
 */
function runPagedImport(DataSource $dataSource, array $pages, array $lookups = [], array $nodes = [], ?array $changedOrders = null): array
{
    $lookedUp = [];

    Saloon::fake([GraphQL::class => function (PendingRequest $pendingRequest) use ($pages, $lookups, $nodes, $changedOrders, &$lookedUp): MockResponse {
        $body = $pendingRequest->body()?->all() ?? [];
        $query = (string) ($body['query'] ?? '');
        $variables = $body['variables'] ?? [];

        if (str_contains($query, 'currentAppInstallation')) {
            return shopifyAccessScopesResponse([
                ...ShopifySource::REQUIRED_SCOPES,
                ...($changedOrders !== null ? [ShopifySource::ALL_ORDERS_SCOPE] : []),
            ]);
        }

        if (str_contains($query, 'ClosedFulfillmentOrders')) {
            $lookedUp[] = $variables['ids'];

            return MockResponse::make(['data' => ['nodes' => array_map(fn (string $id): ?array => $lookups[$id] ?? null, $variables['ids'])]]);
        }

        if (str_contains($query, 'orders(first:')) {
            return MockResponse::make(['data' => ['orders' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'nodes' => $changedOrders ?? [],
            ]]]);
        }

        if (str_contains($query, 'nodes(ids:')) {
            return MockResponse::make(['data' => ['nodes' => array_map(fn (string $id): ?array => $nodes[$id] ?? null, $variables['ids'])]]);
        }

        $page = (int) str_replace('page-', '', (string) ($variables['cursor'] ?? 'page-0'));
        $hasNextPage = $page < count($pages) - 1;

        return MockResponse::make(['data' => ['fulfillmentOrders' => [
            'pageInfo' => ['hasNextPage' => $hasNextPage, 'endCursor' => $hasNextPage ? 'page-'.($page + 1) : null],
            'nodes' => $pages[$page] ?? [],
        ]]]);
    }]);

    $result = ShipmentImportService::forRecord($dataSource->fresh())->import();

    return ['result' => $result, 'lookups' => $lookedUp];
}

/**
 * An order the search for changes returns, holding these fulfillment orders.
 *
 * @param  list<array<string, mixed>>  $fulfillmentOrders
 * @return array<string, mixed>
 */
function pagedChangedOrder(string $orderId, array $fulfillmentOrders): array
{
    return [
        'id' => "gid://shopify/Order/{$orderId}",
        'fulfillmentOrders' => [
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            'nodes' => array_map(fn (array $node): array => ['id' => $node['id'], 'status' => $node['status']], $fulfillmentOrders),
        ],
    ];
}

/**
 * Peak memory of an import whose full read returns `$pageCount` pages of
 * `$perPage` fulfillment orders, each built as it is asked for. Their goods
 * come from a catalog of 25 SKUs, as a store's do, so the products the import
 * creates do not grow with the orders.
 *
 * Saloon's own fake keeps every response it serves, decoded, which would
 * measure the fake. This one keeps none.
 */
function pagedImportPeakMemory(DataSource $dataSource, int $pageCount, int $perPage, int $firstId): int
{
    MockClient::destroyGlobal();

    $nonRecording = new class extends MockClient
    {
        public function recordResponse(Response $response): void {}
    };

    $nonRecording::global([GraphQL::class => function (PendingRequest $pendingRequest) use ($pageCount, $perPage, $firstId): MockResponse {
        $body = $pendingRequest->body()?->all() ?? [];
        $query = (string) ($body['query'] ?? '');

        if (str_contains($query, 'currentAppInstallation')) {
            return shopifyAccessScopesResponse();
        }

        if (str_contains($query, 'ClosedFulfillmentOrders')) {
            return MockResponse::make(['data' => ['nodes' => []]]);
        }

        $page = (int) str_replace('page-', '', (string) ($body['variables']['cursor'] ?? 'page-0'));
        $hasNextPage = $page < $pageCount - 1;

        return MockResponse::make(['data' => ['fulfillmentOrders' => [
            'pageInfo' => ['hasNextPage' => $hasNextPage, 'endCursor' => $hasNextPage ? 'page-'.($page + 1) : null],
            'nodes' => array_map(function (int $n) use ($firstId, $page, $perPage): array {
                $node = pagedNode((string) ($firstId + $page * $perPage + $n));
                $node['lineItems']['nodes'][0]['sku'] = 'SKU-'.($n % 25);

                return $node;
            }, range(1, $perPage)),
        ]]]);
    }]);

    gc_collect_cycles();

    // The import resets the peak when it starts, so this is the run's own.
    ShipmentImportService::forRecord($dataSource->fresh())->import();

    return memory_get_peak_usage();
}

describe('re-pointing', function (): void {
    it('re-points a shipment at a replacement read on an earlier page than anything else the run writes', function (): void {
        runPagedImport($this->dataSource, [[pagedNode('100')]]);
        $shipment = Shipment::sole();

        // The original is closed and gone from the feed. Its replacement leads
        // page 1, ahead of other work spread over the pages after it.
        $run = runPagedImport($this->dataSource, [
            [pagedNode('101', orderId: '100'), pagedNode('110')],
            [pagedNode('111')],
            [pagedNode('112')],
        ]);

        $fresh = $shipment->fresh();

        expect($fresh->source_record_id)->toBe(pagedFoId('101'))
            ->and($fresh->metadata['shopify_fulfillment_order_id'])->toBe(pagedFoId('101'))
            ->and($fresh->status)->toBe(ShipmentStatus::Open)
            ->and(Shipment::where('shipment_reference', '#100')->count())->toBe(1)
            ->and(Shipment::count())->toBe(4)
            ->and($run['lookups'])->toBe([])
            ->and($run['result']->hasErrors())->toBeFalse();
    });

    it('re-points onto neither of two new fulfillment orders on different pages that match one shipment', function (): void {
        runPagedImport($this->dataSource, [[pagedNode('200')]]);
        $shipment = Shipment::sole();

        runPagedImport($this->dataSource, [
            [pagedNode('201', orderId: '200')],
            [pagedNode('210')],
            [pagedNode('202', orderId: '200')],
        ]);

        expect($shipment->fresh()->source_record_id)->toBe(pagedFoId('200'))
            ->and(Shipment::where('shipment_reference', '#200')->pluck('source_record_id')->sort()->values()->all())
            ->toBe([pagedFoId('200'), pagedFoId('201'), pagedFoId('202')]);
    });

    it('re-points onto neither of two changed fulfillment orders read in different batches that match one shipment', function (): void {
        runPagedImport($this->dataSource, [[pagedNode('300')]]);
        $shipment = Shipment::sole();

        $this->dataSource->forceFill([
            'import_updated_through' => CarbonImmutable::now()->subMinutes(5),
            'import_swept_at' => CarbonImmutable::now()->subMinutes(5),
            'import_account_key' => 'shopify:test-shop.myshopify.com',
        ])->save();

        // 249 open fulfillment orders at the ignored location fill the first
        // read of 250 behind the first match, so the second falls in the next.
        $first = pagedNode('301', orderId: '300');
        $fillers = array_map(fn (int $n): array => pagedNode((string) (5000 + $n), locationId: '9'), range(1, 249));
        $second = pagedNode('302', orderId: '300');
        $open = [$first, ...$fillers, $second];

        runPagedImport(
            $this->dataSource,
            [],
            nodes: collect($open)->keyBy('id')->all(),
            changedOrders: [
                pagedChangedOrder('300', [pagedNode('300', status: 'CLOSED'), $first]),
                pagedChangedOrder('5000', $fillers),
                pagedChangedOrder('300b', [$second]),
            ],
        );

        expect($shipment->fresh()->source_record_id)->toBe(pagedFoId('300'))
            ->and(Shipment::where('shipment_reference', '#300')->pluck('source_record_id')->sort()->values()->all())
            ->toBe([pagedFoId('300'), pagedFoId('301'), pagedFoId('302')]);
    });
});

describe('closing', function (): void {
    it('closes a shipment whose fulfillment order moved to an ignored location, read on a later page', function (): void {
        $shipment = Shipment::factory()->create([
            'data_source_id' => $this->dataSource->id,
            'source_record_id' => pagedFoId('400'),
            'shipment_reference' => '#400',
            'status' => ShipmentStatus::Open,
            'status_origin' => null,
            'metadata' => ['shopify_order_id' => 'gid://shopify/Order/400', 'shopify_fulfillment_order_id' => pagedFoId('400')],
        ]);

        $run = runPagedImport(
            $this->dataSource,
            [[pagedNode('410')], [pagedNode('400', locationId: '9')]],
            [pagedFoId('400') => pagedLookupNode('400', 'OPEN', '9')],
        );

        expect($run['lookups'])->toBe([[pagedFoId('400')]])
            ->and($run['result']->shipmentsClosed)->toBe(1)
            ->and($shipment->fresh()->status)->toBe(ShipmentStatus::Cancelled);
    });

    it('closes nothing when a later page of the read fails', function (): void {
        $shipment = Shipment::factory()->create([
            'data_source_id' => $this->dataSource->id,
            'source_record_id' => pagedFoId('500'),
            'shipment_reference' => '#500',
            'status' => ShipmentStatus::Open,
            'status_origin' => null,
            'metadata' => ['shopify_order_id' => 'gid://shopify/Order/500', 'shopify_fulfillment_order_id' => pagedFoId('500')],
        ]);

        Saloon::fake([GraphQL::class => function (PendingRequest $pendingRequest): MockResponse {
            $body = $pendingRequest->body()?->all() ?? [];
            $query = (string) ($body['query'] ?? '');

            if (str_contains($query, 'currentAppInstallation')) {
                return shopifyAccessScopesResponse();
            }

            if (str_contains($query, 'ClosedFulfillmentOrders')) {
                throw new RuntimeException('The run looked up a Shipment after a failed read.');
            }

            if (($body['variables']['cursor'] ?? null) === 'page-1') {
                return MockResponse::make(['errors' => [['message' => 'Internal error']]]);
            }

            return MockResponse::make(['data' => ['fulfillmentOrders' => [
                'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'page-1'],
                'nodes' => [pagedNode('510')],
            ]]]);
        }]);

        $result = ShipmentImportService::forRecord($this->dataSource->fresh())->import();

        expect($result->hasErrors())->toBeTrue()
            ->and($result->shipmentsClosed)->toBe(0)
            ->and($shipment->fresh()->status)->toBe(ShipmentStatus::Open);
    });
});

it('holds about as much memory for twenty pages of fulfillment orders as for one', function (): void {
    config(['shipment-import.behavior.batch_size' => 100]);

    // Warm what a first run loads once, so neither run below is charged for it.
    pagedImportPeakMemory($this->dataSource, 1, 10, 900000);

    $onePage = pagedImportPeakMemory($this->dataSource, 1, 100, 100000);
    $twentyPages = pagedImportPeakMemory($this->dataSource, 20, 100, 200000);

    // Holding every row costs about 20 KB a fulfillment order (`01`'s
    // Measured). The run does hold every identity, at well under 1 KB each.
    expect(Shipment::count())->toBe(10 + 100 + 2000)
        ->and($twentyPages - $onePage)->toBeLessThan(1900 * 1024);
});
