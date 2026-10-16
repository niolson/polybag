<?php

use App\Enums\AuditAction;
use App\Enums\PackageStatus;
use App\Enums\ShipmentStatus;
use App\Enums\ShipmentStatusOrigin;
use App\Http\Integrations\Shopify\Requests\GraphQL;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\ChannelAlias;
use App\Models\DataSource;
use App\Models\DataSourceLocation;
use App\Models\Location;
use App\Models\Package;
use App\Models\PackageLabel;
use App\Models\Shipment;
use App\Services\ShipmentImport\ImportResult;
use App\Services\ShipmentImport\ShipmentImportService;
use App\Services\ShipmentImport\ShopifyClosedFulfillmentOrderCloser;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Illuminate\Support\Facades\Cache;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;

/**
 * `order-reconciliation/06`: once a Shopify import has read the whole open
 * feed, the Shipments whose fulfillment order left it are looked up and closed
 * when Shopify closed them.
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
});

function reconcileFoId(string $id): string
{
    return "gid://shopify/FulfillmentOrder/{$id}";
}

/**
 * An open fulfillment order as the import feed carries it.
 *
 * @return array<string, mixed>
 */
function reconcileFeedNode(string $id, string $locationId = '1'): array
{
    return [
        'id' => reconcileFoId($id),
        'status' => 'OPEN',
        'order' => ['id' => "gid://shopify/Order/{$id}", 'name' => "#{$id}", 'email' => 'test@example.com'],
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
                'sku' => "SKU-{$id}", 'productTitle' => 'Widget', 'remainingQuantity' => 1,
                'requiresShipping' => true, 'weight' => null,
                'variant' => ['id' => "gid://shopify/ProductVariant/{$id}", 'barcodes' => ['nodes' => []]],
                'lineItem' => ['originalUnitPriceSet' => ['shopMoney' => ['amount' => '10.00']]],
            ]],
        ],
    ];
}

/**
 * A fulfillment order as the closing lookup reads it.
 *
 * @param  list<array<string, mixed>>  $fulfillments
 * @return array<string, mixed>
 */
function reconcileLookupNode(string $id, string $status, array $fulfillments = [], ?string $cancelledAt = null, string $locationId = '1'): array
{
    return [
        'id' => reconcileFoId($id),
        'status' => $status,
        'assignedLocation' => ['location' => ['id' => "gid://shopify/Location/{$locationId}"]],
        'order' => ['cancelledAt' => $cancelledAt],
        'fulfillments' => ['nodes' => $fulfillments],
    ];
}

/**
 * @return array<string, mixed>
 */
/**
 * A lookup node whose fulfillments run on past `$fulfillments`.
 *
 * @param  list<array<string, mixed>>  $fulfillments
 * @return array<string, mixed>
 */
function reconcilePagedLookupNode(string $id, array $fulfillments): array
{
    $node = reconcileLookupNode($id, 'CLOSED', $fulfillments);
    $node['fulfillments']['pageInfo'] = ['hasNextPage' => true, 'endCursor' => 'cursor-1'];

    return $node;
}

/**
 * A later page of one fulfillment order's fulfillments.
 *
 * @param  list<array<string, mixed>>  $fulfillments
 */
function reconcileFulfillmentPage(array $fulfillments, bool $hasNextPage = false): MockResponse
{
    return MockResponse::make(['data' => ['fulfillmentOrder' => ['fulfillments' => [
        'pageInfo' => ['hasNextPage' => $hasNextPage, 'endCursor' => $hasNextPage ? 'cursor-next' : null],
        'nodes' => $fulfillments,
    ]]]]);
}

function reconcileFulfillment(string $status = 'SUCCESS', ?string $number = null, ?string $company = null): array
{
    return [
        'status' => $status,
        'trackingInfo' => $number === null && $company === null ? [] : [['company' => $company, 'number' => $number]],
    ];
}

/**
 * An open Shipment this connection imported earlier.
 */
function reconcileOpenShipment(DataSource $dataSource, string $id, array $attributes = []): Shipment
{
    return Shipment::factory()->create([
        'data_source_id' => $dataSource->id,
        'source_record_id' => reconcileFoId($id),
        'shipment_reference' => "#{$id}",
        'status' => ShipmentStatus::Open,
        'status_origin' => null,
        'metadata' => ['shopify_order_id' => "gid://shopify/Order/{$id}", 'shopify_fulfillment_order_id' => reconcileFoId($id)],
        ...$attributes,
    ]);
}

/**
 * Run an import whose feed is `$feed` and whose closing lookup answers from
 * `$lookup`: lookup nodes keyed by fulfillment order ID (an ID missing from
 * it comes back null, as a deleted one does), or a fixed response.
 *
 * `$fulfillmentPages` answers the follow-up for a fulfillment order whose
 * fulfillments run past the lookup's first page: its later pages in order.
 *
 * @param  list<array<string, mixed>>  $feed
 * @param  array<string, array<string, mixed>>|MockResponse  $lookup
 * @param  array<string, list<MockResponse>>  $fulfillmentPages
 * @return array{result: ImportResult, lookups: list<list<string>>}
 */
function runReconcilingImport(DataSource $dataSource, array $feed, array|MockResponse $lookup = [], ?MockResponse $feedResponse = null, array $fulfillmentPages = []): array
{
    $lookups = [];

    Saloon::fake([GraphQL::class => function (PendingRequest $pendingRequest) use ($feed, $lookup, $feedResponse, &$fulfillmentPages, &$lookups): MockResponse {
        $body = $pendingRequest->body()?->all() ?? [];
        $query = (string) ($body['query'] ?? '');

        if (str_contains($query, 'currentAppInstallation')) {
            return shopifyAccessScopesResponse();
        }

        if (str_contains($query, 'FulfillmentOrderFulfillments')) {
            return array_shift($fulfillmentPages[$body['variables']['id']]);
        }

        if (str_contains($query, 'ClosedFulfillmentOrders')) {
            $ids = $body['variables']['ids'];
            $lookups[] = $ids;

            return $lookup instanceof MockResponse
                ? $lookup
                : MockResponse::make(['data' => ['nodes' => array_map(fn (string $id): ?array => $lookup[$id] ?? null, $ids)]]);
        }

        return $feedResponse ?? MockResponse::make(['data' => ['fulfillmentOrders' => [
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            'nodes' => $feed,
        ]]]);
    }]);

    $result = ShipmentImportService::forSource(new ShopifySource([
        'channel_name' => 'Shopify',
        'shop_domain' => 'test-shop.myshopify.com',
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
    ]), $dataSource)->import();

    return ['result' => $result, 'lookups' => $lookups];
}

describe('candidates', function (): void {
    it('looks up nothing when every open shipment is still in the feed', function (): void {
        reconcileOpenShipment($this->dataSource, '100');

        $run = runReconcilingImport($this->dataSource, [reconcileFeedNode('100')]);

        expect($run['lookups'])->toBe([])
            ->and($run['result']->shipmentsClosed)->toBe(0);
    });

    it('looks up an open shipment the run did not fetch, and only open ones', function (): void {
        reconcileOpenShipment($this->dataSource, '101');
        reconcileOpenShipment($this->dataSource, '102', ['status' => ShipmentStatus::Shipped, 'status_origin' => ShipmentStatusOrigin::PolyBag]);
        reconcileOpenShipment($this->dataSource, '103', ['status' => ShipmentStatus::Cancelled, 'status_origin' => ShipmentStatusOrigin::Manager]);

        $run = runReconcilingImport($this->dataSource, []);

        expect($run['lookups'])->toBe([[reconcileFoId('101')]]);
    });

    it('looks up an open shipment the run fetched at a location the connection ignores', function (): void {
        reconcileOpenShipment($this->dataSource, '104');

        $run = runReconcilingImport($this->dataSource, [reconcileFeedNode('104', '9')]);

        expect($run['lookups'])->toBe([[reconcileFoId('104')]]);
    });

    it('never looks up a shipment a manager reopened', function (): void {
        reconcileOpenShipment($this->dataSource, '105', ['status_origin' => ShipmentStatusOrigin::Manager]);

        $run = runReconcilingImport($this->dataSource, []);

        expect($run['lookups'])->toBe([]);
    });

    it('never looks up a shipment re-pointed at the fulfillment order that replaced its own', function (): void {
        runReconcilingImport($this->dataSource, [reconcileFeedNode('106')]);

        $replacement = reconcileFeedNode('106');
        $replacement['id'] = reconcileFoId('107');
        $replacement['lineItems']['nodes'][0]['id'] = 'gid://shopify/FulfillmentOrderLineItem/107';

        $run = runReconcilingImport($this->dataSource, [$replacement]);

        expect(Shipment::sole()->source_record_id)->toBe(reconcileFoId('107'))
            ->and($run['lookups'])->toBe([]);
    });

    it('leaves another connection\'s shipments alone', function (): void {
        $other = DataSource::factory()->create(['source_type' => ShopifySource::class, 'name' => 'Other store']);
        reconcileOpenShipment($other, '108');

        $run = runReconcilingImport($this->dataSource, []);

        expect($run['lookups'])->toBe([]);
    });

    it('does nothing when the fetch fails', function (): void {
        $shipment = reconcileOpenShipment($this->dataSource, '109');

        $run = runReconcilingImport(
            $this->dataSource,
            [],
            [reconcileFoId('109') => reconcileLookupNode('109', 'CANCELLED')],
            MockResponse::make(['errors' => [['message' => 'Internal error']]]),
        );

        expect($run['lookups'])->toBe([])
            ->and($run['result']->hasErrors())->toBeTrue()
            ->and($shipment->fresh()->status)->toBe(ShipmentStatus::Open);
    });

    it('asks for many fulfillment orders in one request per batch of 250', function (): void {
        foreach (range(1, ShopifyClosedFulfillmentOrderCloser::BATCH_SIZE + 1) as $id) {
            reconcileOpenShipment($this->dataSource, (string) (1000 + $id));
        }

        $run = runReconcilingImport($this->dataSource, []);

        expect($run['lookups'])->toHaveCount(2)
            ->and($run['lookups'][0])->toHaveCount(ShopifyClosedFulfillmentOrderCloser::BATCH_SIZE)
            ->and($run['lookups'][1])->toHaveCount(1);
    });
});

describe('what Shopify reports', function (): void {
    it('cancels a shipment whose order Shopify cancelled, recording the source', function (): void {
        $shipment = reconcileOpenShipment($this->dataSource, '200');

        $run = runReconcilingImport($this->dataSource, [], [
            reconcileFoId('200') => reconcileLookupNode('200', 'CLOSED', [reconcileFulfillment('SUCCESS', '1Z999')], cancelledAt: '2026-10-13T10:00:00Z'),
        ]);

        $fresh = $shipment->fresh();
        $audit = AuditLog::where('auditable_id', $shipment->id)->where('action', AuditAction::ShipmentUpdated)->sole();

        expect($run['result']->shipmentsClosed)->toBe(1)
            ->and($fresh->status)->toBe(ShipmentStatus::Cancelled)
            ->and($fresh->status_origin)->toBe(ShipmentStatusOrigin::Source)
            ->and($fresh->status_changed_at)->not->toBeNull()
            ->and($fresh->wasClosedBySource())->toBeTrue()
            ->and($audit->metadata['reason'])->toBe('Shopify reported the order cancelled')
            ->and($audit->old_values)->toBe(['status' => 'open', 'status_origin' => null])
            ->and($audit->new_values['status'])->toBe('cancelled');
    });

    it('cancels a shipment whose fulfillment order Shopify cancelled', function (): void {
        $shipment = reconcileOpenShipment($this->dataSource, '201');

        runReconcilingImport($this->dataSource, [], [reconcileFoId('201') => reconcileLookupNode('201', 'CANCELLED')]);

        expect($shipment->fresh()->status)->toBe(ShipmentStatus::Cancelled);
    });

    it('marks a shipment shipped with the tracking of a fulfillment that is not PolyBag\'s', function (): void {
        $shipment = reconcileOpenShipment($this->dataSource, '202');

        runReconcilingImport($this->dataSource, [], [
            reconcileFoId('202') => reconcileLookupNode('202', 'CLOSED', [reconcileFulfillment('SUCCESS', '1Z999AA10123456784', 'UPS')]),
        ]);

        $fresh = $shipment->fresh();

        expect($fresh->status)->toBe(ShipmentStatus::Shipped)
            ->and($fresh->status_origin)->toBe(ShipmentStatusOrigin::Source)
            ->and($fresh->external_carrier)->toBe('UPS')
            ->and($fresh->external_tracking_number)->toBe('1Z999AA10123456784');
    });

    it('marks a shipment shipped, with no tracking, when the fulfillment has no tracking number', function (): void {
        $shipment = reconcileOpenShipment($this->dataSource, '203');

        runReconcilingImport($this->dataSource, [], [
            reconcileFoId('203') => reconcileLookupNode('203', 'CLOSED', [reconcileFulfillment('SUCCESS')]),
        ]);

        $fresh = $shipment->fresh();

        expect($fresh->status)->toBe(ShipmentStatus::Shipped)
            ->and($fresh->external_carrier)->toBeNull()
            ->and($fresh->external_tracking_number)->toBeNull();
    });

    it('leaves a shipment open when its only fulfillment carries a voided PolyBag Label\'s tracking number', function (): void {
        $shipment = reconcileOpenShipment($this->dataSource, '204');
        $package = Package::factory()->for($shipment)->create(['status' => PackageStatus::Unshipped]);
        PackageLabel::factory()->voided()->for($package)->create(['tracking_number' => '9400111899223344556677']);

        // Cancelling the fulfillment failed after the void, so Shopify still
        // lists it, spaced and cased its own way.
        $run = runReconcilingImport($this->dataSource, [], [
            reconcileFoId('204') => reconcileLookupNode('204', 'CLOSED', [reconcileFulfillment('SUCCESS', '9400 1118 9922 3344 5566 77', 'USPS')]),
        ]);

        expect($shipment->fresh()->status)->toBe(ShipmentStatus::Open)
            ->and($run['result']->shipmentsClosed)->toBe(0);
    });

    it('ships a shipment that has a voided PolyBag Label when another system shipped it', function (): void {
        $shipment = reconcileOpenShipment($this->dataSource, '205');
        $package = Package::factory()->for($shipment)->create(['status' => PackageStatus::Unshipped]);
        PackageLabel::factory()->voided()->for($package)->create(['tracking_number' => 'polybag-test-label']);

        runReconcilingImport($this->dataSource, [], [
            reconcileFoId('205') => reconcileLookupNode('205', 'CLOSED', [
                reconcileFulfillment('SUCCESS', 'POLYBAG-TEST-LABEL', 'USPS'),
                reconcileFulfillment('SUCCESS', '7712345678', 'FedEx'),
            ]),
        ]);

        $fresh = $shipment->fresh();

        expect($fresh->status)->toBe(ShipmentStatus::Shipped)
            ->and($fresh->external_carrier)->toBe('FedEx')
            ->and($fresh->external_tracking_number)->toBe('7712345678');
    });

    it('cancels a shipment whose fulfillment order closed with no live fulfillment', function (): void {
        $shipment = reconcileOpenShipment($this->dataSource, '206');

        runReconcilingImport($this->dataSource, [], [
            reconcileFoId('206') => reconcileLookupNode('206', 'CLOSED', [
                reconcileFulfillment('CANCELLED', '1Z1', 'UPS'),
                reconcileFulfillment('ERROR', '1Z2', 'UPS'),
                reconcileFulfillment('FAILURE', '1Z3', 'UPS'),
            ]),
        ]);

        expect($shipment->fresh()->status)->toBe(ShipmentStatus::Cancelled);
    });

    it('cancels a shipment whose fulfillment order moved to a location the connection ignores', function (): void {
        $shipment = reconcileOpenShipment($this->dataSource, '207');

        runReconcilingImport($this->dataSource, [reconcileFeedNode('207', '9')], [
            reconcileFoId('207') => reconcileLookupNode('207', 'OPEN', locationId: '9'),
        ]);

        $fresh = $shipment->fresh();

        expect($fresh->status)->toBe(ShipmentStatus::Cancelled)
            ->and(AuditLog::where('auditable_id', $shipment->id)->where('action', AuditAction::ShipmentUpdated)->sole()->metadata['reason'])
            ->toBe('Shopify moved the fulfillment order to a location this connection ignores');
    });

    it('changes nothing while the fulfillment order is not closed', function (string $status): void {
        $shipment = reconcileOpenShipment($this->dataSource, '208');

        $run = runReconcilingImport($this->dataSource, [], [reconcileFoId('208') => reconcileLookupNode('208', $status)]);

        expect($shipment->fresh()->status)->toBe(ShipmentStatus::Open)
            ->and($run['result']->shipmentsClosed)->toBe(0);
    })->with(['OPEN', 'IN_PROGRESS', 'ON_HOLD', 'SCHEDULED', 'INCOMPLETE']);

    it('changes nothing for a fulfillment order Shopify no longer has', function (): void {
        $shipment = reconcileOpenShipment($this->dataSource, '209');

        runReconcilingImport($this->dataSource, [], []);

        expect($shipment->fresh()->status)->toBe(ShipmentStatus::Open);
    });

    it('closes a shipment and keeps its package draft', function (): void {
        $shipment = reconcileOpenShipment($this->dataSource, '210');
        $draft = Package::factory()->for($shipment)->create(['status' => PackageStatus::Unshipped]);

        runReconcilingImport($this->dataSource, [], [reconcileFoId('210') => reconcileLookupNode('210', 'CANCELLED')]);

        expect($shipment->fresh()->status)->toBe(ShipmentStatus::Cancelled)
            ->and($draft->fresh())->not->toBeNull();
    });

    it('closes each shipment Shopify closed and counts them', function (): void {
        $cancelled = reconcileOpenShipment($this->dataSource, '211');
        $shipped = reconcileOpenShipment($this->dataSource, '212');
        $open = reconcileOpenShipment($this->dataSource, '213');

        $run = runReconcilingImport($this->dataSource, [], [
            reconcileFoId('211') => reconcileLookupNode('211', 'CANCELLED'),
            reconcileFoId('212') => reconcileLookupNode('212', 'CLOSED', [reconcileFulfillment('SUCCESS', '1Z9', 'UPS')]),
            reconcileFoId('213') => reconcileLookupNode('213', 'ON_HOLD'),
        ]);

        expect($run['lookups'])->toHaveCount(1)
            ->and($run['result']->shipmentsClosed)->toBe(2)
            ->and($cancelled->fresh()->status)->toBe(ShipmentStatus::Cancelled)
            ->and($shipped->fresh()->status)->toBe(ShipmentStatus::Shipped)
            ->and($open->fresh()->status)->toBe(ShipmentStatus::Open);
    });
});

describe('a fulfillment order with more fulfillments than one page', function (): void {
    it('reads past ten cancelled fulfillments to the one that shipped', function (): void {
        $shipment = reconcileOpenShipment($this->dataSource, '400');
        $cancelled = array_fill(0, 10, reconcileFulfillment('CANCELLED', 'OLD', 'UPS'));

        runReconcilingImport(
            $this->dataSource,
            [],
            [reconcileFoId('400') => reconcilePagedLookupNode('400', $cancelled)],
            fulfillmentPages: [reconcileFoId('400') => [reconcileFulfillmentPage([reconcileFulfillment('SUCCESS', '7712345678', 'FedEx')])]],
        );

        $fresh = $shipment->fresh();

        expect($fresh->status)->toBe(ShipmentStatus::Shipped)
            ->and($fresh->external_tracking_number)->toBe('7712345678');
    });

    it('reads every page before deciding every parcel is PolyBag\'s', function (): void {
        $shipment = reconcileOpenShipment($this->dataSource, '401');
        $package = Package::factory()->for($shipment)->create(['status' => PackageStatus::Unshipped]);
        PackageLabel::factory()->voided()->for($package)->create(['tracking_number' => 'OURS']);
        $ours = array_fill(0, 10, reconcileFulfillment('SUCCESS', 'OURS', 'USPS'));

        runReconcilingImport(
            $this->dataSource,
            [],
            [reconcileFoId('401') => reconcilePagedLookupNode('401', $ours)],
            fulfillmentPages: [reconcileFoId('401') => [
                reconcileFulfillmentPage([reconcileFulfillment('SUCCESS', 'OURS', 'USPS')], hasNextPage: true),
                reconcileFulfillmentPage([reconcileFulfillment('SUCCESS', '1Z999', 'UPS')]),
            ]],
        );

        expect($shipment->fresh()->status)->toBe(ShipmentStatus::Shipped)
            ->and($shipment->fresh()->external_tracking_number)->toBe('1Z999');
    });

    it('leaves the shipment for the next run when a later page fails, and closes the rest', function (): void {
        $paged = reconcileOpenShipment($this->dataSource, '402');
        $cancelled = reconcileOpenShipment($this->dataSource, '403');

        $run = runReconcilingImport(
            $this->dataSource,
            [],
            [
                reconcileFoId('402') => reconcilePagedLookupNode('402', array_fill(0, 10, reconcileFulfillment('CANCELLED'))),
                reconcileFoId('403') => reconcileLookupNode('403', 'CANCELLED'),
            ],
            fulfillmentPages: [reconcileFoId('402') => [MockResponse::make(['errors' => [['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']]]])]],
        );

        expect($paged->fresh()->status)->toBe(ShipmentStatus::Open)
            ->and($cancelled->fresh()->status)->toBe(ShipmentStatus::Cancelled)
            ->and($run['result']->shipmentsClosed)->toBe(1)
            ->and($run['result']->errors)->toHaveCount(1)
            ->and($run['result']->errors[0])->toContain('#402');
    });
});

it('reads every tracking number Shopify lists on a fulfillment', function (): void {
    $shipment = reconcileOpenShipment($this->dataSource, '404');
    $package = Package::factory()->for($shipment)->create(['status' => PackageStatus::Unshipped]);
    PackageLabel::factory()->voided()->for($package)->create(['tracking_number' => 'OURS']);

    $fulfillment = ['status' => 'SUCCESS', 'trackingInfo' => [
        ...array_fill(0, 5, ['company' => 'USPS', 'number' => 'OURS']),
        ['company' => 'UPS', 'number' => '1Z999'],
    ]];

    runReconcilingImport($this->dataSource, [], [reconcileFoId('404') => reconcileLookupNode('404', 'CLOSED', [$fulfillment])]);

    expect($shipment->fresh()->status)->toBe(ShipmentStatus::Shipped)
        ->and($shipment->fresh()->external_tracking_number)->toBe('1Z999');
});

it('asks Shopify for every tracking number rather than the first few', function (): void {
    reconcileOpenShipment($this->dataSource, '405');

    runReconcilingImport($this->dataSource, []);

    Saloon::assertSent(fn (GraphQL $request): bool => str_contains((string) $request->body()->all()['query'], 'ClosedFulfillmentOrders')
        && str_contains((string) $request->body()->all()['query'], 'trackingInfo {')
        && str_contains((string) $request->body()->all()['query'], 'pageInfo { hasNextPage endCursor }'));
});

it('changes nothing and still completes the run when the lookup fails', function (MockResponse $failure): void {
    $shipment = reconcileOpenShipment($this->dataSource, '300');

    $run = runReconcilingImport($this->dataSource, [reconcileFeedNode('301')], $failure);

    expect($shipment->fresh()->status)->toBe(ShipmentStatus::Open)
        ->and($run['result']->shipmentsCreated)->toBe(1)
        ->and($run['result']->shipmentsClosed)->toBe(0)
        ->and($run['result']->errors)->toHaveCount(1)
        ->and($run['result']->errors[0])->toContain('checked again next run');
})->with([
    'throttled' => fn () => MockResponse::make(['errors' => [['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']]]]),
    'server error' => fn () => MockResponse::make(['errors' => 'Internal Server Error'], 500),
]);
