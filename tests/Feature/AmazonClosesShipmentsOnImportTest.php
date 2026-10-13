<?php

use App\Enums\AuditAction;
use App\Enums\PackageStatus;
use App\Enums\ShipmentStatus;
use App\Enums\ShipmentStatusOrigin;
use App\Http\Integrations\Amazon\Requests\SearchOrders;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\ChannelAlias;
use App\Models\DataSource;
use App\Models\Package;
use App\Models\PackageLabel;
use App\Models\Setting;
use App\Models\Shipment;
use App\Services\OAuthService;
use App\Services\SettingsService;
use App\Services\ShipmentImport\ImportResult;
use App\Services\ShipmentImport\ShipmentImportService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;

/**
 * `order-reconciliation/07`: an Amazon import asks for every order updated
 * since it last read, open and closed alike, and closes the Shipments of the
 * orders Amazon shipped or cancelled.
 */
beforeEach(function (): void {
    Setting::updateOrCreate(['key' => 'require_mfa'], ['value' => '1', 'type' => 'boolean', 'group' => 'general']);
    app(SettingsService::class)->clearCache();
    Cache::put('amazon_sp_api_access_token_'.md5('test-refresh-token'), 'test-access-token', 3600);

    $this->travelTo(CarbonImmutable::parse('2026-10-13 12:00:00'));

    $this->dataSource = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'name' => 'Amazon',
        'settings' => ['channel_name' => 'Amazon', 'marketplace_id' => 'ATVPDKIKX0DER', 'lookback_days' => 30],
        'secret_settings' => [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'refresh_token' => 'test-refresh-token',
        ],
    ]);

    tap(Channel::factory()->create(['name' => 'Amazon']), fn (Channel $channel) => ChannelAlias::firstOrCreate(
        ['reference' => 'Amazon'],
        ['channel_id' => $channel->id],
    ));
});

function amazonSyncOrderId(int $id): string
{
    return sprintf('111-0000000-%07d', $id);
}

/**
 * An Amazon order carrying everything the v2026-01-01 Order schema requires.
 *
 * @param  list<array<string, mixed>>|null  $packages
 * @param  array<string, int>  $fulfilled  quantity fulfilled, keyed by order item ID
 * @return array<string, mixed>
 */
function amazonSyncOrder(int $id, string $status = 'UNSHIPPED', ?array $packages = null, array $fulfilled = []): array
{
    $order = [
        'orderId' => amazonSyncOrderId($id),
        'createdTime' => '2026-10-01T15:00:00Z',
        'lastUpdatedTime' => '2026-10-13T11:58:00Z',
        'salesChannel' => ['channelName' => 'AMAZON', 'marketplaceId' => 'ATVPDKIKX0DER'],
        'fulfillment' => ['fulfillmentStatus' => $status, 'fulfilledBy' => 'MERCHANT'],
        'recipient' => ['deliveryAddress' => [
            'name' => 'Jane Smith', 'addressLine1' => '456 Oak Ave', 'city' => 'Seattle',
            'stateOrRegion' => 'WA', 'postalCode' => '98101', 'countryCode' => 'US',
        ]],
        'orderItems' => collect(['ITEM-A', 'ITEM-B'])->map(fn (string $itemId): array => [
            'orderItemId' => $itemId,
            'quantityOrdered' => 1,
            'product' => ['title' => "Widget {$itemId}", 'sellerSku' => "SKU-{$itemId}"],
            'fulfillment' => ['quantityFulfilled' => $fulfilled[$itemId] ?? 0, 'quantityUnfulfilled' => 1 - ($fulfilled[$itemId] ?? 0)],
        ])->all(),
    ];

    if ($packages !== null) {
        $order['packages'] = $packages;
    }

    return $order;
}

/**
 * @return array<string, mixed>
 */
function amazonSyncPackage(?string $trackingNumber, string $carrier = 'UPS'): array
{
    return array_filter([
        'packageReferenceId' => '1',
        'carrier' => $carrier,
        'trackingNumber' => $trackingNumber,
    ], fn (mixed $value): bool => $value !== null);
}

/**
 * An open Shipment this connection imported earlier.
 *
 * @param  array<string, mixed>  $attributes
 */
function amazonSyncOpenShipment(DataSource $dataSource, int $id, array $attributes = []): Shipment
{
    return Shipment::factory()->create([
        'data_source_id' => $dataSource->id,
        'source_record_id' => amazonSyncOrderId($id),
        'shipment_reference' => amazonSyncOrderId($id),
        'status' => ShipmentStatus::Open,
        'status_origin' => null,
        'metadata' => ['amazon_order_id' => amazonSyncOrderId($id)],
        ...$attributes,
    ]);
}

/**
 * The connection read through `$minutesAgo` minutes ago, and read every open
 * order `$sweptHoursAgo` hours ago, from the account and marketplace it has now.
 */
function amazonSyncReadThrough(DataSource $dataSource, int $minutesAgo = 5, int $sweptHoursAgo = 1): void
{
    $dataSource->forceFill([
        'import_updated_through' => now()->subMinutes($minutesAgo),
        'import_swept_at' => now()->subHours($sweptHoursAgo),
        'import_account_key' => $dataSource->importAccountKey(),
    ])->save();
}

/**
 * Fake `searchOrders` as Amazon answers it. A search for changes (one asking
 * for cancelled orders too) answers from `$changes`, a search for every open
 * order from `$open`: each a list of orders for one page, or a list of pages.
 * A later page is asked for with a token naming it, as in `changes:1`, so a
 * read can carry on in a later run against the same pages. After `$budget`
 * requests, every request is throttled.
 *
 * @param  list<array<string, mixed>>|list<list<array<string, mixed>>>  $changes
 * @param  list<array<string, mixed>>|list<list<array<string, mixed>>>  $open
 * @return Closure(): list<array<string, mixed>> the queries sent so far
 */
function fakeAmazonSearchOrders(array $changes = [], array $open = [], ?int $budget = null, ?MockResponse $failure = null): Closure
{
    $queries = [];
    $asPages = fn (array $orders): array => isset($orders[0]) && array_is_list($orders[0]) ? $orders : [$orders];
    $pages = ['changes' => $asPages($changes), 'sweep' => $asPages($open)];

    Saloon::fake([
        SearchOrders::class => function (PendingRequest $pendingRequest) use ($pages, $budget, $failure, &$queries): MockResponse {
            $query = $pendingRequest->query()->all();
            $queries[] = $query;

            if ($failure !== null) {
                return $failure;
            }

            if ($budget !== null && count($queries) > $budget) {
                return MockResponse::make(['errors' => [['code' => 'QuotaExceeded', 'message' => 'You exceeded your quota for the requested resource.']]], 429);
            }

            $kind = str_contains((string) $query['fulfillmentStatuses'], 'CANCELLED') ? 'changes' : 'sweep';
            $index = isset($query['paginationToken']) ? (int) explode(':', (string) $query['paginationToken'])[1] : 0;
            $hasNext = isset($pages[$kind][$index + 1]);

            return MockResponse::make(['orders' => $pages[$kind][$index] ?? []]
                + ($hasNext ? ['pagination' => ['nextToken' => $kind.':'.($index + 1)]] : []));
        },
    ]);

    return function () use (&$queries): array {
        return $queries;
    };
}

/**
 * Run a scheduled import against {@see fakeAmazonSearchOrders()}.
 *
 * @param  list<array<string, mixed>>|list<list<array<string, mixed>>>  $changes
 * @param  list<array<string, mixed>>|list<list<array<string, mixed>>>  $open
 * @return array{result: ImportResult, queries: list<array<string, mixed>>}
 */
function runAmazonSyncImport(DataSource $dataSource, array $changes = [], array $open = [], ?MockResponse $failure = null, ?int $budget = null): array
{
    $queries = fakeAmazonSearchOrders($changes, $open, $budget, $failure);

    $result = ShipmentImportService::forRecord($dataSource->fresh())->import();

    return ['result' => $result, 'queries' => $queries()];
}

/**
 * One order per page, `$count` pages, numbered from `$from`.
 *
 * @return list<list<array<string, mixed>>>
 */
function amazonSyncPages(int $count, int $from = 1000): array
{
    return array_map(fn (int $id): array => [amazonSyncOrder($id)], range($from, $from + $count - 1));
}

describe('what an import searches for', function (): void {
    it('reads every open order on a connection that has never read through, and records how far it read', function (): void {
        $run = runAmazonSyncImport($this->dataSource, open: [amazonSyncOrder(1)]);

        $fresh = $this->dataSource->fresh();

        expect($run['queries'])->toHaveCount(1)
            ->and($run['queries'][0]['fulfillmentStatuses'])->toBe('UNSHIPPED,PARTIALLY_SHIPPED')
            ->and($run['queries'][0]['lastUpdatedAfter'])->toBe(now()->subDays(30)->toIso8601String())
            ->and($run['result']->shipmentsCreated)->toBe(1)
            ->and($fresh->import_updated_through->equalTo(now()))->toBeTrue()
            ->and($fresh->import_swept_at->equalTo(now()))->toBeTrue()
            ->and($fresh->import_reads)->toBeNull();
    });

    it('then asks only for what changed since, open and closed, in one request', function (): void {
        amazonSyncReadThrough($this->dataSource, minutesAgo: 5);

        $run = runAmazonSyncImport($this->dataSource, [amazonSyncOrder(2)]);

        expect($run['queries'])->toHaveCount(1)
            ->and($run['queries'][0]['fulfillmentStatuses'])->toBe('UNSHIPPED,PARTIALLY_SHIPPED,SHIPPED,CANCELLED')
            ->and($run['queries'][0]['lastUpdatedAfter'])->toBe(now()->subMinutes(15)->toIso8601String())
            ->and($run['queries'][0]['includedData'])->toContain('PACKAGES')
            ->and($run['result']->shipmentsCreated)->toBe(1)
            ->and($this->dataSource->fresh()->import_updated_through->equalTo(now()))->toBeTrue()
            ->and($this->dataSource->fresh()->import_swept_at->equalTo(now()->subHour()))->toBeTrue();

        Saloon::assertSentCount(1);
    });

    it('sends searches the vendored orders_2026-01-01 schema describes', function (): void {
        amazonSyncReadThrough($this->dataSource, sweptHoursAgo: 25);
        $order = amazonSyncOrder(3, 'SHIPPED', [amazonSyncPackage('1Z999'), amazonSyncPackage(null)]);
        assertMatchesSpApiSchema($order, 'Order', 'orders_2026-01-01');

        $run = runAmazonSyncImport($this->dataSource, [[$order], [amazonSyncOrder(4)]]);

        $document = json_decode((string) file_get_contents(base_path('tests/Fixtures/Schemas/orders_2026-01-01.json')), true);
        $parameters = collect($document['paths']['/orders/2026-01-01/orders']['get']['parameters'])->keyBy('name');

        expect($run['queries'])->toHaveCount(3);

        foreach ($run['queries'] as $query) {
            expect(array_keys($query))->each->toBeIn($parameters->keys()->all())
                ->and(array_diff(explode(',', $query['fulfillmentStatuses']), $parameters['fulfillmentStatuses']['items']['enum']))->toBe([])
                ->and(array_diff(explode(',', $query['includedData']), $parameters['includedData']['items']['enum']))->toBe([]);
        }
    });

    it('reads every open order again once a day, after what changed', function (): void {
        amazonSyncReadThrough($this->dataSource, minutesAgo: 5, sweptHoursAgo: 25);

        $run = runAmazonSyncImport($this->dataSource, [amazonSyncOrder(4)], [amazonSyncOrder(5)]);

        expect($run['queries'])->toHaveCount(2)
            ->and($run['queries'][0]['fulfillmentStatuses'])->toContain('CANCELLED')
            ->and($run['result']->shipmentsCreated)->toBe(2)
            ->and($this->dataSource->fresh()->import_swept_at->equalTo(now()))->toBeTrue();
    });

    it('asks for what changed after a gap of days, within the lookback', function (): void {
        amazonSyncReadThrough($this->dataSource, minutesAgo: 3 * 24 * 60);

        $run = runAmazonSyncImport($this->dataSource);

        expect($run['queries'][0]['fulfillmentStatuses'])->toBe('UNSHIPPED,PARTIALLY_SHIPPED,SHIPPED,CANCELLED');
    });

    it('reads every open order instead when it last read through longer ago than the lookback', function (): void {
        amazonSyncReadThrough($this->dataSource, minutesAgo: 31 * 24 * 60);

        $run = runAmazonSyncImport($this->dataSource, open: [amazonSyncOrder(6)]);

        expect($run['queries'])->toHaveCount(1)
            ->and($run['queries'][0]['fulfillmentStatuses'])->toBe('UNSHIPPED,PARTIALLY_SHIPPED');
    });

    it('reads every page of what changed', function (): void {
        amazonSyncReadThrough($this->dataSource);
        $cancelled = amazonSyncOpenShipment($this->dataSource, 7);

        $run = runAmazonSyncImport($this->dataSource, [[amazonSyncOrder(8)], [amazonSyncOrder(7, 'CANCELLED')]]);

        expect($run['queries'])->toHaveCount(2)
            ->and($run['queries'][1]['paginationToken'])->toBe('changes:1')
            ->and($run['queries'][1]['lastUpdatedAfter'])->toBe($run['queries'][0]['lastUpdatedAfter'])
            ->and($cancelled->fresh()->status)->toBe(ShipmentStatus::Cancelled);
    });

    it('keeps where it read through when the search fails, so the next run reads the same changes', function (): void {
        amazonSyncReadThrough($this->dataSource, minutesAgo: 5);
        $before = $this->dataSource->fresh()->import_updated_through;

        $run = runAmazonSyncImport($this->dataSource, failure: MockResponse::make(['errors' => [['code' => 'InternalFailure', 'message' => 'Internal error']]], 500));

        expect($run['result']->hasErrors())->toBeTrue()
            ->and($this->dataSource->fresh()->import_updated_through->equalTo($before))->toBeTrue()
            ->and($this->dataSource->fresh()->import_reads)->toBeNull();
    });

    it('leaves where it read through alone on a historical import', function (): void {
        Setting::updateOrCreate(['key' => 'sandbox_mode'], ['value' => '0', 'type' => 'boolean', 'group' => 'general']);
        app(SettingsService::class)->clearCache();
        amazonSyncReadThrough($this->dataSource, minutesAgo: 5);
        $before = $this->dataSource->fresh()->import_updated_through;
        $shipment = amazonSyncOpenShipment($this->dataSource, 9);

        Saloon::fake([SearchOrders::class => MockResponse::make(['orders' => [amazonSyncOrder(9, 'SHIPPED')]])]);

        ShipmentImportService::forRecord($this->dataSource->fresh(), [
            '_historical_import' => true,
            '_historical_created_after' => '2026-01-01T00:00:00Z',
            '_historical_created_before' => '2026-02-01T00:00:00Z',
            '_historical_max_orders' => 10,
        ])->import();

        expect($this->dataSource->fresh()->import_updated_through->equalTo($before))->toBeTrue()
            ->and(AuditLog::where('auditable_id', $shipment->id)->where('metadata->reason', 'Amazon reported the order shipped')->exists())->toBeFalse();
    });

    it('leaves where it read through alone in sandbox mode', function (): void {
        Setting::updateOrCreate(['key' => 'sandbox_mode'], ['value' => '1', 'type' => 'boolean', 'group' => 'general']);
        app(SettingsService::class)->clearCache();
        $shipment = amazonSyncOpenShipment($this->dataSource, 10);

        Saloon::fake([SearchOrders::class => MockResponse::make(['orders' => [amazonSyncOrder(10, 'CANCELLED')]])]);

        ShipmentImportService::forRecord($this->dataSource->fresh())->import();

        expect($this->dataSource->fresh()->import_updated_through)->toBeNull()
            ->and($shipment->fresh()->status)->toBe(ShipmentStatus::Open);
    });
});

describe('a read Amazon throttles', function (): void {
    it('imports what it read and carries on from there next run, sending nothing again once throttled', function (): void {
        $pages = amazonSyncPages(25);

        // 2,500 open orders on a new connection, at 100 a page: the burst of 20 reads 20 pages.
        $first = runAmazonSyncImport($this->dataSource, open: $pages, budget: 20);
        $firstStartedAt = now()->toImmutable();
        $stored = $this->dataSource->fresh()->import_reads;

        expect($first['queries'])->toHaveCount(21)
            ->and($first['result']->shipmentsCreated)->toBe(20)
            ->and($first['result']->hasErrors())->toBeFalse()
            ->and($stored['sweep']['token'])->toBe('sweep:20')
            ->and($this->dataSource->fresh()->import_updated_through)->toBeNull();

        // Five minutes refill one or two requests.
        $this->travel(5)->minutes();
        $second = runAmazonSyncImport($this->dataSource, open: $pages, budget: 2);

        expect($second['queries'][0]['paginationToken'])->toBe('sweep:20')
            ->and($second['queries'][0]['lastUpdatedAfter'])->toBe($first['queries'][0]['lastUpdatedAfter'])
            ->and($second['result']->shipmentsCreated)->toBe(2);

        $this->travel(15)->minutes();
        $third = runAmazonSyncImport($this->dataSource, open: $pages, budget: 20);
        $fresh = $this->dataSource->fresh();

        // Finished: read through from when the read began, so anything updated
        // while it was spread over three runs is read again as a change.
        expect($third['result']->shipmentsCreated)->toBe(3)
            ->and(Shipment::count())->toBe(25)
            ->and($fresh->import_reads)->toBeNull()
            ->and($fresh->import_updated_through->equalTo($firstStartedAt))->toBeTrue()
            ->and($fresh->import_swept_at->equalTo($firstStartedAt))->toBeTrue();

        $this->travel(5)->minutes();
        $fourth = runAmazonSyncImport($this->dataSource, budget: 20);

        expect($fourth['queries'])->toHaveCount(1)
            ->and($fourth['queries'][0]['lastUpdatedAfter'])->toBe($firstStartedAt->subMinutes(10)->toIso8601String());
    });

    it('reads what changed first, and the daily read of every open order with what is left', function (): void {
        amazonSyncReadThrough($this->dataSource, minutesAgo: 5, sweptHoursAgo: 25);
        $sweep = amazonSyncPages(3);

        $first = runAmazonSyncImport($this->dataSource, [amazonSyncOrder(1)], $sweep, budget: 2);
        $fresh = $this->dataSource->fresh();

        expect($fresh->import_updated_through->equalTo(now()))->toBeTrue()
            ->and($fresh->import_swept_at->equalTo(now()->subHours(25)))->toBeTrue()
            ->and($fresh->import_reads['sweep']['token'])->toBe('sweep:1')
            ->and($fresh->import_reads)->not->toHaveKey('changes')
            ->and($first['result']->shipmentsCreated)->toBe(2);

        $this->travel(5)->minutes();
        $second = runAmazonSyncImport($this->dataSource, [], $sweep, budget: 3);

        expect($second['queries'])->toHaveCount(3)
            ->and($second['queries'][0]['fulfillmentStatuses'])->toContain('CANCELLED')
            ->and($second['queries'][1]['paginationToken'])->toBe('sweep:1')
            ->and(Shipment::count())->toBe(4)
            ->and($this->dataSource->fresh()->import_reads)->toBeNull();
    });

    it('does not start the daily read when the changes are throttled', function (): void {
        amazonSyncReadThrough($this->dataSource, minutesAgo: 5, sweptHoursAgo: 25);
        $before = $this->dataSource->fresh()->import_updated_through;

        $run = runAmazonSyncImport($this->dataSource, amazonSyncPages(2), amazonSyncPages(1, 2000), budget: 1);
        $fresh = $this->dataSource->fresh();

        expect($run['queries'])->toHaveCount(2)
            ->and($fresh->import_updated_through->equalTo($before))->toBeTrue()
            ->and($fresh->import_reads['changes']['token'])->toBe('changes:1')
            ->and($fresh->import_reads['sweep']['token'])->toBeNull();
    });

    it('starts over from every open order when it could not read the changes within a token\'s life', function (): void {
        amazonSyncReadThrough($this->dataSource, minutesAgo: 2 * 24 * 60);
        $this->dataSource->forceFill(['import_reads' => ['changes' => [
            'query' => ['marketplaceIds' => 'ATVPDKIKX0DER', 'fulfillmentStatuses' => 'UNSHIPPED,PARTIALLY_SHIPPED,SHIPPED,CANCELLED', 'lastUpdatedAfter' => '2026-10-11T11:50:00+00:00', 'includedData' => 'RECIPIENT,PROCEEDS,FULFILLMENT,PACKAGES'],
            'token' => 'changes:7',
            'token_at' => now()->subHours(AmazonSource::TOKEN_LIFETIME_HOURS + 1)->toIso8601String(),
            'started_at' => '2026-10-11T12:00:00+00:00',
        ]]])->save();

        $run = runAmazonSyncImport($this->dataSource, open: [amazonSyncOrder(1)]);
        $fresh = $this->dataSource->fresh();

        expect($run['queries'])->toHaveCount(1)
            ->and($run['queries'][0]['fulfillmentStatuses'])->toBe('UNSHIPPED,PARTIALLY_SHIPPED')
            ->and($run['queries'][0])->not->toHaveKey('paginationToken')
            ->and($fresh->import_updated_through->equalTo(now()))->toBeTrue()
            ->and($fresh->import_reads)->toBeNull();
    });

    it('starts a read over when Amazon refuses its token', function (): void {
        amazonSyncReadThrough($this->dataSource, minutesAgo: 5);
        $this->dataSource->forceFill(['import_reads' => ['changes' => [
            'query' => ['marketplaceIds' => 'ATVPDKIKX0DER', 'fulfillmentStatuses' => 'UNSHIPPED,PARTIALLY_SHIPPED,SHIPPED,CANCELLED', 'lastUpdatedAfter' => '2026-10-13T11:45:00+00:00', 'includedData' => 'RECIPIENT,PROCEEDS,FULFILLMENT,PACKAGES'],
            'token' => 'changes:3',
            'token_at' => now()->subHour()->toIso8601String(),
            'started_at' => '2026-10-13T11:55:00+00:00',
        ]]])->save();
        $before = $this->dataSource->fresh()->import_updated_through;

        $run = runAmazonSyncImport($this->dataSource, failure: MockResponse::make(['errors' => [['code' => 'InvalidInput', 'message' => 'Invalid pagination token']]], 400));
        $fresh = $this->dataSource->fresh();

        expect($run['result']->hasErrors())->toBeFalse()
            ->and($fresh->import_reads)->toBeNull()
            ->and($fresh->import_updated_through->equalTo($before))->toBeTrue();
    });

    it('keeps the stored read where it was when the run fails', function (): void {
        $pages = amazonSyncPages(3);
        runAmazonSyncImport($this->dataSource, open: $pages, budget: 1);

        $run = runAmazonSyncImport($this->dataSource, open: $pages, failure: MockResponse::make(['errors' => [['code' => 'InternalFailure', 'message' => 'Internal error']]], 500));

        expect($run['result']->hasErrors())->toBeTrue()
            ->and($this->dataSource->fresh()->import_reads['sweep']['token'])->toBe('sweep:1');
    });
});

describe('another seller account or marketplace', function (): void {
    beforeEach(function (): void {
        $this->dataSource->update(['settings' => [
            ...$this->dataSource->settings,
            'auth_mode' => 'authorization_code',
            'amazon_selling_partner_id' => 'A1SELLERONE',
        ]]);
        amazonSyncReadThrough($this->dataSource, minutesAgo: 5);
        $this->dataSource->forceFill(['import_reads' => ['sweep' => [
            'query' => ['marketplaceIds' => 'ATVPDKIKX0DER', 'fulfillmentStatuses' => 'UNSHIPPED,PARTIALLY_SHIPPED', 'lastUpdatedAfter' => '2026-09-13T12:00:00+00:00', 'includedData' => 'RECIPIENT,PROCEEDS,FULFILLMENT'],
            'token' => 'sweep:4',
            'token_at' => now()->subMinutes(5)->toIso8601String(),
            'started_at' => '2026-10-13T11:00:00+00:00',
        ]]])->save();
    });

    /**
     * Assert the run started over: one read of every open order, from the
     * start, recorded against the connection as it is now.
     *
     * @param  array{result: ImportResult, queries: list<array<string, mixed>>}  $run
     */
    function expectStartedOver(DataSource $dataSource, array $run, string $marketplaceId = 'ATVPDKIKX0DER'): void
    {
        $fresh = $dataSource->fresh();

        expect($run['queries'])->toHaveCount(1)
            ->and($run['queries'][0]['marketplaceIds'])->toBe($marketplaceId)
            ->and($run['queries'][0]['fulfillmentStatuses'])->toBe('UNSHIPPED,PARTIALLY_SHIPPED')
            ->and($run['queries'][0])->not->toHaveKey('paginationToken')
            ->and($fresh->import_updated_through->equalTo(now()))->toBeTrue()
            ->and($fresh->import_reads)->toBeNull()
            ->and($fresh->import_account_key)->toBe($fresh->importAccountKey());
    }

    it('carries on as before when nothing about the account changed', function (): void {
        $run = runAmazonSyncImport($this->dataSource, open: amazonSyncPages(5));

        expect($run['queries'][0]['fulfillmentStatuses'])->toContain('CANCELLED')
            ->and($run['queries'][1]['paginationToken'])->toBe('sweep:4');
    });

    it('starts over in a new marketplace', function (): void {
        $this->dataSource->update(['settings' => [...$this->dataSource->settings, 'marketplace_id' => 'A2EUQ1WTGCTBG2']]);

        expectStartedOver($this->dataSource, runAmazonSyncImport($this->dataSource, open: [amazonSyncOrder(1)]), 'A2EUQ1WTGCTBG2');
    });

    it('starts over when reconnected to another seller account in the same marketplace', function (): void {
        app(OAuthService::class)->disconnectDataSource('sp-api', $this->dataSource);
        // As the OAuth callback does: the new refresh token, and its access token cached.
        $this->dataSource->refresh()->mergeSecret('refresh_token', 'test-refresh-token');
        Cache::put('amazon_sp_api_access_token_'.md5('test-refresh-token'), 'test-access-token', 3600);
        $this->dataSource->update(['settings' => [
            ...$this->dataSource->settings,
            'auth_mode' => 'authorization_code',
            'amazon_selling_partner_id' => 'A2SELLERTWO',
        ]]);

        expectStartedOver($this->dataSource, runAmazonSyncImport($this->dataSource, open: [amazonSyncOrder(1)]));
    });

    it('carries on when reconnected to the same seller account', function (): void {
        app(OAuthService::class)->disconnectDataSource('sp-api', $this->dataSource);
        // As the OAuth callback does: the new refresh token, and its access token cached.
        $this->dataSource->refresh()->mergeSecret('refresh_token', 'test-refresh-token');
        Cache::put('amazon_sp_api_access_token_'.md5('test-refresh-token'), 'test-access-token', 3600);
        $this->dataSource->update(['settings' => [
            ...$this->dataSource->settings,
            'auth_mode' => 'authorization_code',
            'amazon_selling_partner_id' => 'A1SELLERONE',
        ]]);

        $run = runAmazonSyncImport($this->dataSource, open: amazonSyncPages(5));

        expect($run['queries'][1]['paginationToken'])->toBe('sweep:4');
    });

    it('starts over when a connection with its own credentials is given another refresh token', function (): void {
        $settings = $this->dataSource->settings;
        unset($settings['auth_mode'], $settings['amazon_selling_partner_id']);
        $this->dataSource->update(['settings' => $settings]);
        amazonSyncReadThrough($this->dataSource, minutesAgo: 5);

        $this->dataSource->mergeSecret('refresh_token', 'another-sellers-refresh-token');
        $this->dataSource->save();
        Cache::put('amazon_sp_api_access_token_'.md5('another-sellers-refresh-token'), 'test-access-token', 3600);

        expectStartedOver($this->dataSource, runAmazonSyncImport($this->dataSource, open: [amazonSyncOrder(1)]));
    });

    it('starts over after a run that was reading the old marketplace when it changed', function (): void {
        $sent = 0;

        // Someone picks a new marketplace while the run is reading; Amazon
        // then throttles it, so it stores where it stopped.
        Saloon::fake([SearchOrders::class => function () use (&$sent): MockResponse {
            if (++$sent > 1) {
                return MockResponse::make(['errors' => [['code' => 'QuotaExceeded', 'message' => 'You exceeded your quota']]], 429);
            }

            $record = DataSource::query()->findOrFail($this->dataSource->id);
            $record->update(['settings' => [...$record->settings, 'marketplace_id' => 'A2EUQ1WTGCTBG2']]);

            return MockResponse::make(['orders' => [amazonSyncOrder(1)], 'pagination' => ['nextToken' => 'changes:1']]);
        }]);

        ShipmentImportService::forRecord($this->dataSource->fresh())->import();

        $this->travel(5)->minutes();

        expectStartedOver($this->dataSource, runAmazonSyncImport($this->dataSource, open: [amazonSyncOrder(2)]), 'A2EUQ1WTGCTBG2');
    });
});

describe('what Amazon reports', function (): void {
    beforeEach(function (): void {
        amazonSyncReadThrough($this->dataSource);
    });

    it('cancels a shipment whose order Amazon cancelled, recording the source', function (): void {
        $shipment = amazonSyncOpenShipment($this->dataSource, 20);

        $run = runAmazonSyncImport($this->dataSource, [amazonSyncOrder(20, 'CANCELLED')]);

        $fresh = $shipment->fresh();
        $audit = AuditLog::where('auditable_id', $shipment->id)->where('action', AuditAction::ShipmentUpdated)->sole();

        expect($run['result']->shipmentsClosed)->toBe(1)
            ->and($fresh->status)->toBe(ShipmentStatus::Cancelled)
            ->and($fresh->status_origin)->toBe(ShipmentStatusOrigin::Source)
            ->and($fresh->status_changed_at)->not->toBeNull()
            ->and($audit->metadata['reason'])->toBe('Amazon reported the order cancelled');
    });

    it('marks a shipment shipped with the carrier and tracking of a package that is not PolyBag\'s', function (): void {
        $shipment = amazonSyncOpenShipment($this->dataSource, 21);

        runAmazonSyncImport($this->dataSource, [amazonSyncOrder(21, 'SHIPPED', [amazonSyncPackage('1Z999AA10123456784', 'UPS')])]);

        $fresh = $shipment->fresh();

        expect($fresh->status)->toBe(ShipmentStatus::Shipped)
            ->and($fresh->status_origin)->toBe(ShipmentStatusOrigin::Source)
            ->and($fresh->external_carrier)->toBe('UPS')
            ->and($fresh->external_tracking_number)->toBe('1Z999AA10123456784');
    });

    it('marks a shipment shipped, with no tracking, when Amazon lists no packages', function (): void {
        $shipment = amazonSyncOpenShipment($this->dataSource, 22);

        runAmazonSyncImport($this->dataSource, [amazonSyncOrder(22, 'SHIPPED')]);

        $fresh = $shipment->fresh();

        expect($fresh->status)->toBe(ShipmentStatus::Shipped)
            ->and($fresh->external_carrier)->toBeNull()
            ->and($fresh->external_tracking_number)->toBeNull();
    });

    it('marks a shipment shipped when its only package has no tracking number', function (): void {
        $shipment = amazonSyncOpenShipment($this->dataSource, 23);

        runAmazonSyncImport($this->dataSource, [amazonSyncOrder(23, 'SHIPPED', [amazonSyncPackage(null, 'Other')])]);

        $fresh = $shipment->fresh();

        expect($fresh->status)->toBe(ShipmentStatus::Shipped)
            ->and($fresh->external_carrier)->toBe('Other')
            ->and($fresh->external_tracking_number)->toBeNull();
    });

    it('leaves a shipment open when Amazon still lists the package of a voided PolyBag Label', function (): void {
        $shipment = amazonSyncOpenShipment($this->dataSource, 24);
        $package = Package::factory()->for($shipment)->create(['status' => PackageStatus::Unshipped]);
        PackageLabel::factory()->voided()->for($package)->create(['tracking_number' => '9400111899223344556677']);

        // The export confirmed the order before the void, and a confirmation
        // cannot be withdrawn.
        $run = runAmazonSyncImport($this->dataSource, [amazonSyncOrder(24, 'SHIPPED', [amazonSyncPackage('9400111899223344556677', 'USPS')])]);

        expect($shipment->fresh()->status)->toBe(ShipmentStatus::Open)
            ->and($run['result']->shipmentsClosed)->toBe(0);
    });

    it('ships a shipment that has a voided PolyBag Label when another system shipped it', function (): void {
        $shipment = amazonSyncOpenShipment($this->dataSource, 25);
        $package = Package::factory()->for($shipment)->create(['status' => PackageStatus::Unshipped]);
        PackageLabel::factory()->voided()->for($package)->create(['tracking_number' => 'POLYBAG-TEST-LABEL']);

        runAmazonSyncImport($this->dataSource, [amazonSyncOrder(25, 'SHIPPED', [
            amazonSyncPackage('POLYBAG-TEST-LABEL', 'USPS'),
            amazonSyncPackage('7712345678', 'FedEx'),
        ])]);

        $fresh = $shipment->fresh();

        expect($fresh->status)->toBe(ShipmentStatus::Shipped)
            ->and($fresh->external_carrier)->toBe('FedEx')
            ->and($fresh->external_tracking_number)->toBe('7712345678');
    });

    it('imports an order still open rather than closing its shipment', function (string $status): void {
        $shipment = amazonSyncOpenShipment($this->dataSource, 26);

        $run = runAmazonSyncImport($this->dataSource, [amazonSyncOrder(26, $status, [amazonSyncPackage('1Z999AA10123456784')], ['ITEM-A' => $status === 'PARTIALLY_SHIPPED' ? 1 : 0])]);

        expect($shipment->fresh()->status)->toBe(ShipmentStatus::Open)
            ->and($run['result']->shipmentsClosed)->toBe(0)
            ->and($run['result']->shipmentsUpdated)->toBe(1);
    })->with(['UNSHIPPED', 'PARTIALLY_SHIPPED']);

    it('creates no shipment for a closed order PolyBag never imported', function (): void {
        runAmazonSyncImport($this->dataSource, [amazonSyncOrder(27, 'SHIPPED'), amazonSyncOrder(28, 'CANCELLED')]);

        expect(Shipment::count())->toBe(0);
    });

    it('never closes a shipment a manager reopened, or another connection\'s', function (): void {
        $reopened = amazonSyncOpenShipment($this->dataSource, 29, ['status_origin' => ShipmentStatusOrigin::Manager]);
        $other = amazonSyncOpenShipment(DataSource::factory()->create(['source_type' => AmazonSource::class, 'name' => 'Other seller']), 30);

        $run = runAmazonSyncImport($this->dataSource, [amazonSyncOrder(29, 'CANCELLED'), amazonSyncOrder(30, 'CANCELLED')]);

        expect($reopened->fresh()->status)->toBe(ShipmentStatus::Open)
            ->and($other->fresh()->status)->toBe(ShipmentStatus::Open)
            ->and($run['result']->shipmentsClosed)->toBe(0);
    });

    it('leaves a shipment PolyBag shipped as it is when Amazon reports it shipped', function (): void {
        $shipment = amazonSyncOpenShipment($this->dataSource, 31, ['status' => ShipmentStatus::Shipped, 'status_origin' => ShipmentStatusOrigin::PolyBag]);

        runAmazonSyncImport($this->dataSource, [amazonSyncOrder(31, 'SHIPPED', [amazonSyncPackage('1Z999')])]);

        expect($shipment->fresh()->status_origin)->toBe(ShipmentStatusOrigin::PolyBag)
            ->and(AuditLog::where('auditable_id', $shipment->id)->where('action', AuditAction::ShipmentUpdated)->exists())->toBeFalse();
    });

    it('closes each shipment Amazon closed and counts them', function (): void {
        $cancelled = amazonSyncOpenShipment($this->dataSource, 32);
        $shipped = amazonSyncOpenShipment($this->dataSource, 33);
        $untouched = amazonSyncOpenShipment($this->dataSource, 34);

        $run = runAmazonSyncImport($this->dataSource, [
            amazonSyncOrder(32, 'CANCELLED'),
            amazonSyncOrder(33, 'SHIPPED', [amazonSyncPackage('1Z999')]),
        ]);

        expect($run['result']->shipmentsClosed)->toBe(2)
            ->and($cancelled->fresh()->status)->toBe(ShipmentStatus::Cancelled)
            ->and($shipped->fresh()->status)->toBe(ShipmentStatus::Shipped)
            ->and($untouched->fresh()->status)->toBe(ShipmentStatus::Open);
    });
});

describe('a line shipped elsewhere', function (): void {
    it('leaves a shipment with no package', function (): void {
        runAmazonSyncImport($this->dataSource, open: [amazonSyncOrder(60)]);
        $shipment = Shipment::where('source_record_id', amazonSyncOrderId(60))->sole();

        expect($shipment->shipmentItems()->count())->toBe(2);

        runAmazonSyncImport($this->dataSource, [amazonSyncOrder(60, 'PARTIALLY_SHIPPED', fulfilled: ['ITEM-B' => 1])]);

        expect($shipment->shipmentItems()->pluck('source_item_id')->all())->toBe(['ITEM-A'])
            ->and($shipment->fresh()->status)->toBe(ShipmentStatus::Open);
    });

    it('stays on a shipment with a package', function (): void {
        runAmazonSyncImport($this->dataSource, open: [amazonSyncOrder(61)]);
        $shipment = Shipment::where('source_record_id', amazonSyncOrderId(61))->sole();
        Package::factory()->for($shipment)->create(['status' => PackageStatus::Unshipped]);

        runAmazonSyncImport($this->dataSource, [amazonSyncOrder(61, 'PARTIALLY_SHIPPED', fulfilled: ['ITEM-B' => 1])]);

        expect($shipment->shipmentItems()->orderBy('source_item_id')->pluck('source_item_id')->all())->toBe(['ITEM-A', 'ITEM-B']);
    });
});
