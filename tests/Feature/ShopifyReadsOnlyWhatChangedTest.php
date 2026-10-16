<?php

use App\Enums\ShipmentStatus;
use App\Enums\ShipmentStatusOrigin;
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
use App\Services\ShopifyFulfillmentOrderActivationService;
use Carbon\CarbonImmutable;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;

/**
 * `order-reconciliation/11`: a Shopify connection that has read through asks
 * only for the orders updated since, and reads every open fulfillment order
 * on its first run, once a day, and after a location's mapping changes.
 */
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00'));
    Cache::put('shopify_access_token_'.md5('test-shop.myshopify.com'), 'shpat_test_token', now()->addDays(30));

    $this->dataSource = DataSource::factory()->create([
        'source_type' => ShopifySource::class,
        'name' => 'Shopify',
        'settings' => [
            'channel_name' => 'Shopify',
            'shop_domain' => 'test-shop.myshopify.com',
            'fulfillment_order_import_enabled' => true,
        ],
        'secret_settings' => ['client_id' => 'test-client-id', 'client_secret' => 'test-client-secret'],
    ]);

    tap(Channel::factory()->create(['name' => 'Shopify']), fn (Channel $channel) => ChannelAlias::firstOrCreate(
        ['reference' => 'Shopify'],
        ['channel_id' => $channel->id],
    ));

    $this->usedLocation = DataSourceLocation::factory()->create([
        'data_source_id' => $this->dataSource,
        'external_id' => 'gid://shopify/Location/1',
        'location_id' => Location::factory(),
    ]);

    $this->ignoredLocation = DataSourceLocation::factory()->create([
        'data_source_id' => $this->dataSource,
        'external_id' => 'gid://shopify/Location/9',
        'location_id' => null,
        'ignored_at' => now(),
    ]);

    // Creating the locations is not a change to their mapping.
    $this->dataSource->refresh();
});

function changesFoId(string $id): string
{
    return "gid://shopify/FulfillmentOrder/{$id}";
}

/**
 * A fulfillment order as the import reads it.
 *
 * @return array<string, mixed>
 */
function changesNode(string $id, string $status = 'OPEN', string $locationId = '1', ?string $orderId = null): array
{
    $orderId ??= $id;

    return [
        'id' => changesFoId($id),
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

/**
 * An order the search for changes returns, holding these fulfillment orders.
 *
 * @param  list<array<string, mixed>>  $fulfillmentOrders
 * @return array<string, mixed>
 */
function changedOrder(string $orderId, array $fulfillmentOrders, bool $more = false): array
{
    return [
        'id' => "gid://shopify/Order/{$orderId}",
        'fulfillmentOrders' => [
            'pageInfo' => ['hasNextPage' => $more, 'endCursor' => $more ? 'fo-cursor' : null],
            'nodes' => array_map(fn (array $node): array => ['id' => $node['id'], 'status' => $node['status']], $fulfillmentOrders),
        ],
    ];
}

/** A fulfillment order as the closing lookup reads it. */
function changesLookupNode(string $id, string $status, ?string $trackingNumber = null, string $locationId = '1'): array
{
    return [
        'id' => changesFoId($id),
        'status' => $status,
        'assignedLocation' => ['location' => ['id' => "gid://shopify/Location/{$locationId}"]],
        'order' => ['cancelledAt' => null],
        'fulfillments' => ['nodes' => $trackingNumber === null ? [] : [[
            'status' => 'SUCCESS',
            'trackingInfo' => [['company' => 'UPS', 'number' => $trackingNumber]],
        ]]],
    ];
}

function readThrough(DataSource $dataSource, CarbonImmutable $updatedThrough, ?CarbonImmutable $sweptAt = null): void
{
    $dataSource->forceFill([
        'import_updated_through' => $updatedThrough,
        'import_swept_at' => $sweptAt ?? $updatedThrough,
        'import_account_key' => 'shopify:test-shop.myshopify.com',
    ])->save();
}

/**
 * Run a scheduled import against a fake Shopify.
 *
 * `$feed` answers the full read, `$changedOrders` the search for changes, and
 * `$nodes` (keyed by ID) the import's fetch of changed fulfillment orders,
 * and `$lookups` (keyed by ID) the closing lookup. The token holds
 * `read_all_orders` unless `$readAllOrders` says otherwise. `$responses` overrides an operation with a
 * queue of responses, used in order.
 *
 * @param  list<array<string, mixed>>  $feed
 * @param  list<array<string, mixed>>  $changedOrders
 * @param  array<string, array<string, mixed>>  $nodes
 * @param  array<string, list<MockResponse|Closure>>  $responses
 * @return array{result: ImportResult, requests: list<array{operation: string, variables: array<string, mixed>}>}
 */
function runChangesImport(DataSource $dataSource, array $feed = [], array $changedOrders = [], array $nodes = [], array $lookups = [], array $responses = [], bool $readAllOrders = true): array
{
    $requests = [];
    $scopes = [
        ...ShopifyFulfillmentOrderActivationService::REQUIRED_SCOPES,
        ...($readAllOrders ? [ShopifySource::ALL_ORDERS_SCOPE] : []),
    ];

    Saloon::fake([GraphQL::class => function (PendingRequest $pendingRequest) use ($feed, $changedOrders, $nodes, $lookups, $scopes, &$responses, &$requests): MockResponse {
        $body = $pendingRequest->body()?->all() ?? [];
        $query = (string) ($body['query'] ?? '');
        $variables = $body['variables'] ?? [];
        $operation = preg_match('/^\s*(?:query|mutation)\s+(\w+)/', $query, $matches) ? $matches[1] : 'AccessScopes';
        $requests[] = ['operation' => $operation, 'variables' => $variables];

        if (($responses[$operation] ?? []) !== []) {
            $response = array_shift($responses[$operation]);

            return $response instanceof Closure ? $response() : $response;
        }

        return match ($operation) {
            'AccessScopes' => shopifyAccessScopesResponse($scopes),
            'FulfillmentOrders' => MockResponse::make(['data' => ['fulfillmentOrders' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'nodes' => $feed,
            ]]]),
            'ChangedOrders' => MockResponse::make(['data' => ['orders' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'nodes' => $changedOrders,
            ]]]),
            'ChangedFulfillmentOrders' => MockResponse::make(['data' => ['nodes' => array_map(
                fn (string $id): ?array => $nodes[$id] ?? null,
                $variables['ids'],
            )]]),
            'ClosedFulfillmentOrders' => MockResponse::make(['data' => ['nodes' => array_map(
                fn (string $id): ?array => $lookups[$id] ?? null,
                $variables['ids'],
            )]]),
            default => throw new RuntimeException("The fake Shopify has no answer for {$operation}."),
        };
    }]);

    $result = ShipmentImportService::forRecord($dataSource->fresh())->import();

    return ['result' => $result, 'requests' => $requests];
}

/**
 * @param  list<array{operation: string, variables: array<string, mixed>}>  $requests
 * @return list<string>
 */
function operations(array $requests): array
{
    return array_column($requests, 'operation');
}

function openShipmentOn(DataSource $dataSource, string $id, array $attributes = []): Shipment
{
    return Shipment::factory()->create([
        'data_source_id' => $dataSource->id,
        'source_record_id' => changesFoId($id),
        'shipment_reference' => "#{$id}",
        'status' => ShipmentStatus::Open,
        'status_origin' => null,
        'metadata' => ['shopify_order_id' => "gid://shopify/Order/{$id}", 'shopify_fulfillment_order_id' => changesFoId($id)],
        ...$attributes,
    ]);
}

describe('a connection that has read through', function (): void {
    it('asks only for the scopes and one page of changed orders when nothing changed', function (): void {
        readThrough($this->dataSource, now()->toImmutable()->subMinutes(5));
        openShipmentOn($this->dataSource, '100');

        $run = runChangesImport($this->dataSource);

        expect(operations($run['requests']))->toBe(['AccessScopes', 'ChangedOrders'])
            ->and($run['result']->hasErrors())->toBeFalse();
    });

    it('searches from where it read through, less a margin', function (): void {
        readThrough($this->dataSource, CarbonImmutable::parse('2026-10-14 11:55:00'));

        $run = runChangesImport($this->dataSource);

        expect($run['requests'][1]['variables']['query'])->toBe("updated_at:>'2026-10-14T11:53:00Z'");
    });

    it('imports the open fulfillment orders of the changed orders', function (): void {
        readThrough($this->dataSource, now()->toImmutable()->subMinutes(5));

        $run = runChangesImport(
            $this->dataSource,
            changedOrders: [changedOrder('200', [changesNode('200')])],
            nodes: [changesFoId('200') => changesNode('200')],
        );

        expect(operations($run['requests']))->toBe(['AccessScopes', 'ChangedOrders', 'ChangedFulfillmentOrders'])
            ->and(Shipment::sole()->source_record_id)->toBe(changesFoId('200'));
    });

    it('creates no shipment for a closed fulfillment order it never imported, nor asks after it', function (): void {
        readThrough($this->dataSource, now()->toImmutable()->subMinutes(5));

        $run = runChangesImport(
            $this->dataSource,
            changedOrders: [changedOrder('201', [changesNode('201', 'CLOSED'), changesNode('202', 'CANCELLED')])],
        );

        expect(Shipment::count())->toBe(0)
            ->and(operations($run['requests']))->toBe(['AccessScopes', 'ChangedOrders']);
    });

    it('neither imports nor closes a fulfillment order on hold, scheduled or incomplete', function (string $status): void {
        readThrough($this->dataSource, now()->toImmutable()->subMinutes(5));
        $shipment = openShipmentOn($this->dataSource, '203');

        $run = runChangesImport(
            $this->dataSource,
            changedOrders: [changedOrder('203', [changesNode('203', $status)])],
            lookups: [changesFoId('203') => changesLookupNode('203', $status)],
        );

        expect($shipment->fresh()->status)->toBe(ShipmentStatus::Open)
            ->and(Shipment::count())->toBe(1)
            ->and($run['result']->shipmentsClosed)->toBe(0);
    })->with(['ON_HOLD', 'SCHEDULED', 'INCOMPLETE']);

    it('closes an open shipment whose fulfillment order came back closed, by 06\'s table', function (): void {
        readThrough($this->dataSource, now()->toImmutable()->subMinutes(5));
        $fulfilled = openShipmentOn($this->dataSource, '204');
        $cancelled = openShipmentOn($this->dataSource, '205');

        $run = runChangesImport(
            $this->dataSource,
            changedOrders: [
                changedOrder('204', [changesNode('204', 'CLOSED')]),
                changedOrder('205', [changesNode('205', 'CANCELLED')]),
            ],
            lookups: [
                changesFoId('204') => changesLookupNode('204', 'CLOSED', '1Z999'),
                changesFoId('205') => changesLookupNode('205', 'CANCELLED'),
            ],
        );

        expect($run['result']->shipmentsClosed)->toBe(2)
            ->and($fulfilled->fresh()->status)->toBe(ShipmentStatus::Shipped)
            ->and($fulfilled->fresh()->status_origin)->toBe(ShipmentStatusOrigin::Source)
            ->and($cancelled->fresh()->status)->toBe(ShipmentStatus::Cancelled);
    });

    it('closes an open shipment whose fulfillment order moved to a location the connection ignores', function (): void {
        readThrough($this->dataSource, now()->toImmutable()->subMinutes(5));
        $shipment = openShipmentOn($this->dataSource, '206');

        $run = runChangesImport(
            $this->dataSource,
            changedOrders: [changedOrder('206', [changesNode('206', 'OPEN', '9')])],
            nodes: [changesFoId('206') => changesNode('206', 'OPEN', '9')],
            lookups: [changesFoId('206') => changesLookupNode('206', 'OPEN', locationId: '9')],
        );

        expect($run['result']->shipmentsClosed)->toBe(1)
            ->and($shipment->fresh()->status)->toBe(ShipmentStatus::Cancelled);
    });

    it('looks up only the shipments whose fulfillment orders it returned', function (): void {
        readThrough($this->dataSource, now()->toImmutable()->subMinutes(5));
        openShipmentOn($this->dataSource, '207');
        openShipmentOn($this->dataSource, '208');

        $run = runChangesImport(
            $this->dataSource,
            changedOrders: [changedOrder('207', [changesNode('207', 'CLOSED')])],
            lookups: [changesFoId('207') => changesLookupNode('207', 'CLOSED', '1Z1')],
        );

        $lookups = array_values(array_filter($run['requests'], fn (array $request): bool => $request['operation'] === 'ClosedFulfillmentOrders'));

        expect($lookups)->toHaveCount(1)
            ->and($lookups[0]['variables']['ids'])->toBe([changesFoId('207')]);
    });

    it('reads every fulfillment order of an order holding more than one page of them', function (): void {
        readThrough($this->dataSource, now()->toImmutable()->subMinutes(5));

        $run = runChangesImport(
            $this->dataSource,
            changedOrders: [changedOrder('209', [changesNode('209', 'CLOSED')], more: true)],
            nodes: [changesFoId('210') => changesNode('210', orderId: '209')],
            responses: ['OrderFulfillmentOrders' => [MockResponse::make(['data' => ['order' => ['fulfillmentOrders' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'nodes' => [['id' => changesFoId('210'), 'status' => 'OPEN']],
            ]]]])]],
        );

        expect(Shipment::sole()->source_record_id)->toBe(changesFoId('210'))
            ->and(collect($run['requests'])->firstWhere('operation', 'OrderFulfillmentOrders')['variables'])
            ->toBe(['id' => 'gid://shopify/Order/209', 'cursor' => 'fo-cursor']);
    });
});

describe('a label void', function (): void {
    it('re-points the shipment at the replacement and neither imports it again nor closes it', function (): void {
        // Imported by a full read, so the shipment carries its goods.
        runChangesImport($this->dataSource, feed: [changesNode('300')]);
        $shipment = Shipment::sole();

        $original = changesNode('300', 'CLOSED');
        $replacement = changesNode('301', orderId: '300');

        $run = runChangesImport(
            $this->dataSource,
            changedOrders: [changedOrder('300', [$original, $replacement])],
            nodes: [changesFoId('301') => $replacement],
        );

        $fresh = $shipment->fresh();

        expect(Shipment::count())->toBe(1)
            ->and($fresh->source_record_id)->toBe(changesFoId('301'))
            ->and($fresh->status)->toBe(ShipmentStatus::Open)
            ->and(operations($run['requests']))->not->toContain('ClosedFulfillmentOrders');
    });
});

describe('reading every open fulfillment order', function (): void {
    it('reads in full on the first run, and records where it read through', function (): void {
        $run = runChangesImport($this->dataSource, feed: [changesNode('400')]);
        $fresh = $this->dataSource->fresh();

        expect(operations($run['requests']))->toBe(['AccessScopes', 'FulfillmentOrders'])
            ->and($fresh->import_updated_through->equalTo(now()))->toBeTrue()
            ->and($fresh->import_swept_at->equalTo(now()))->toBeTrue()
            ->and($fresh->import_account_key)->toBe('shopify:test-shop.myshopify.com');
    });

    it('searches for changes on the next run, and moves only where it read through', function (): void {
        runChangesImport($this->dataSource, feed: [changesNode('401')]);
        $sweptAt = $this->dataSource->fresh()->import_swept_at;

        $this->travel(5)->minutes();
        $run = runChangesImport($this->dataSource);
        $fresh = $this->dataSource->fresh();

        expect(operations($run['requests']))->toBe(['AccessScopes', 'ChangedOrders'])
            ->and($fresh->import_updated_through->equalTo(now()))->toBeTrue()
            ->and($fresh->import_swept_at->equalTo($sweptAt))->toBeTrue();
    });

    it('reads in full once a day', function (): void {
        readThrough(
            $this->dataSource,
            now()->toImmutable()->subMinutes(5),
            now()->toImmutable()->subHours(ShopifySource::FULL_READ_HOURS),
        );

        $run = runChangesImport($this->dataSource);

        expect(operations($run['requests']))->toBe(['AccessScopes', 'FulfillmentOrders'])
            ->and($this->dataSource->fresh()->import_swept_at->equalTo(now()))->toBeTrue();
    });

    it('reads in full after a gap longer than a day', function (): void {
        readThrough($this->dataSource, now()->toImmutable()->subDays(3));

        $run = runChangesImport($this->dataSource);

        expect(operations($run['requests']))->toBe(['AccessScopes', 'FulfillmentOrders']);
    });

    it('reads in full after a location is ignored or un-ignored', function (Closure $change): void {
        readThrough($this->dataSource, now()->toImmutable()->subMinutes(5));
        $change($this);

        $run = runChangesImport($this->dataSource);

        expect(operations($run['requests']))->toBe(['AccessScopes', 'FulfillmentOrders']);
    })->with([
        'ignored' => fn ($test) => $test->usedLocation->update(['location_id' => null, 'ignored_at' => now()]),
        'un-ignored' => fn ($test) => $test->ignoredLocation->update(['location_id' => Location::factory()->create()->id, 'ignored_at' => null]),
    ]);

    it('does not read in full when a location is synchronized without its mapping changing', function (): void {
        readThrough($this->dataSource, now()->toImmutable()->subMinutes(5));
        $this->usedLocation->update(['name' => 'Renamed', 'last_seen_at' => now()]);

        $run = runChangesImport($this->dataSource);

        expect(operations($run['requests']))->toBe(['AccessScopes', 'ChangedOrders']);
    });

    it('reads in full every run on a connection without read_all_orders', function (): void {
        readThrough($this->dataSource, now()->toImmutable()->subMinutes(5));

        $run = runChangesImport($this->dataSource, readAllOrders: false);

        expect(operations($run['requests']))->toBe(['AccessScopes', 'FulfillmentOrders'])
            ->and($this->dataSource->fresh()->import_swept_at->equalTo(now()))->toBeTrue();

        $this->travel(5)->minutes();
        $run = runChangesImport($this->dataSource, readAllOrders: false);

        expect(operations($run['requests']))->toBe(['AccessScopes', 'FulfillmentOrders']);
    });

    it('reads in full when the connection is pointed at another shop', function (): void {
        readThrough($this->dataSource, now()->toImmutable()->subMinutes(5));
        $this->dataSource->forceFill(['import_account_key' => 'shopify:other-shop.myshopify.com'])->save();

        $run = runChangesImport($this->dataSource);

        expect(operations($run['requests']))->toBe(['AccessScopes', 'FulfillmentOrders'])
            ->and($this->dataSource->fresh()->import_account_key)->toBe('shopify:test-shop.myshopify.com');
    });
});

describe('where it read through', function (): void {
    it('does not move when the search fails', function (): void {
        $readThrough = now()->toImmutable()->subMinutes(5);
        readThrough($this->dataSource, $readThrough);

        $run = runChangesImport($this->dataSource, responses: [
            'ChangedOrders' => [MockResponse::make(['errors' => [['message' => 'Internal error']]])],
        ]);

        expect($run['result']->hasErrors())->toBeTrue()
            ->and($this->dataSource->fresh()->import_updated_through->equalTo($readThrough))->toBeTrue();
    });

    it('does not move when a closing lookup fails, so the next run reads the same changes', function (): void {
        $readThrough = now()->toImmutable()->subMinutes(5);
        readThrough($this->dataSource, $readThrough);
        $shipment = openShipmentOn($this->dataSource, '500');

        $run = runChangesImport(
            $this->dataSource,
            changedOrders: [changedOrder('500', [changesNode('500', 'CLOSED')])],
            responses: ['ClosedFulfillmentOrders' => [MockResponse::make(['errors' => [['message' => 'Internal error']]])]],
        );

        expect($run['result']->hasErrors())->toBeTrue()
            ->and($shipment->fresh()->status)->toBe(ShipmentStatus::Open)
            ->and($this->dataSource->fresh()->import_updated_through->equalTo($readThrough))->toBeTrue();
    });

    it('makes the next run read in full when a location\'s mapping changes during this one', function (bool $firstRun): void {
        if (! $firstRun) {
            readThrough($this->dataSource, now()->toImmutable()->subMinutes(5));
        }

        // The location is ignored while Shopify answers the run's read.
        $operation = $firstRun ? 'FulfillmentOrders' : 'ChangedOrders';
        $connection = $firstRun ? 'fulfillmentOrders' : 'orders';

        runChangesImport($this->dataSource, responses: [$operation => [function () use ($connection): MockResponse {
            $this->usedLocation->update(['location_id' => null, 'ignored_at' => now()]);

            return MockResponse::make(['data' => [$connection => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'nodes' => [],
            ]]]);
        }]]);

        $this->travel(5)->minutes();
        $run = runChangesImport($this->dataSource);

        expect(operations($run['requests']))->toBe(['AccessScopes', 'FulfillmentOrders']);
    })->with(['on a first run' => true, 'on a search for changes' => false]);
});

describe('a read Shopify throttles', function (): void {
    it('waits for the bucket to refill and carries on in the same run', function (): void {
        Sleep::fake();
        $logged = [];
        Log::listen(function (MessageLogged $message) use (&$logged): void {
            $logged[] = $message;
        });

        $run = runChangesImport($this->dataSource, feed: [changesNode('600')], responses: ['FulfillmentOrders' => [
            MockResponse::make([
                'errors' => [['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']]],
                'extensions' => ['cost' => [
                    'requestedQueryCost' => 618,
                    'throttleStatus' => ['maximumAvailable' => 2000, 'currentlyAvailable' => 118, 'restoreRate' => 100],
                ]],
            ]),
        ]]);

        expect($run['result']->hasErrors())->toBeFalse()
            ->and(Shipment::sole()->source_record_id)->toBe(changesFoId('600'))
            ->and(operations($run['requests']))->toBe(['AccessScopes', 'FulfillmentOrders', 'FulfillmentOrders']);

        Sleep::assertSequence([Sleep::for(5)->seconds()]);

        $throttled = collect($logged)->where('message', 'Shopify throttled a read; waiting for the bucket to refill');
        expect($throttled)->toHaveCount(1)
            ->and($throttled->sole()->context)->toMatchArray([
                'shop_domain' => 'test-shop.myshopify.com',
                'wait_seconds' => 5,
                'currently_available' => 118,
                'maximum_available' => 2000,
            ]);
    });

    it('gives up after repeated throttling, recording nothing', function (): void {
        Sleep::fake();
        $throttled = MockResponse::make([
            'errors' => [['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']]],
            'extensions' => ['cost' => [
                'requestedQueryCost' => 618,
                'throttleStatus' => ['maximumAvailable' => 2000, 'currentlyAvailable' => 0, 'restoreRate' => 100],
            ]],
        ]);

        $run = runChangesImport($this->dataSource, responses: ['FulfillmentOrders' => array_fill(0, 10, $throttled)]);

        expect($run['result']->hasErrors())->toBeTrue()
            ->and($this->dataSource->fresh()->import_updated_through)->toBeNull();

        Sleep::assertSleptTimes(9);
    });
});
