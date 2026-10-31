<?php

use App\Contracts\DirectCarrierAdapter;
use App\Contracts\PackageLabelWorkflow;
use App\DataTransferObjects\Shipping\CancelResponse;
use App\Enums\PackageExportStatus;
use App\Enums\PackageStatus;
use App\Events\PackageShipped;
use App\Http\Integrations\Amazon\Requests\ConfirmShipment;
use App\Http\Integrations\Shopify\Requests\GraphQL;
use App\Listeners\ExportShippedPackage;
use App\Models\AuditLog;
use App\Models\DataSource;
use App\Models\Package;
use App\Models\PackageExport;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use App\Services\ShipmentImport\PackageExportService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use App\Services\ShipmentImport\Sources\DatabaseSource;
use App\Services\ShopifyGoodsFingerprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;

/**
 * `live-store-demo/01`: in Demo, PolyBag writes nothing back to a live Shopify
 * or Amazon store. A development store gets every write, a Database
 * connection's write-back runs as before, and reads still run.
 */
beforeEach(function (): void {
    app(CarrierRegistry::class)->reset();

    $adapter = Mockery::mock(DirectCarrierAdapter::class);
    $adapter->shouldReceive('cancelShipment')->andReturn(CancelResponse::success('Label voided successfully.'));
    app(CarrierRegistry::class)->registerInstance('USPS', $adapter);
});

afterEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

/**
 * @return array<string, mixed>
 */
function demoWriteBackLivePlan(): array
{
    return ['partnerDevelopment' => false, 'publicDisplayName' => 'Basic'];
}

/**
 * @return array<string, mixed>
 */
function demoWriteBackDevelopmentPlan(): array
{
    return ['partnerDevelopment' => true, 'publicDisplayName' => 'Plus'];
}

/**
 * @return list<array<string, mixed>>
 */
function demoWriteBackLineItems(): array
{
    return [[
        'sku' => 'SKU-800',
        'remainingQuantity' => 1,
        'requiresShipping' => true,
        'variant' => ['id' => 'gid://shopify/ProductVariant/800'],
    ]];
}

/**
 * A store that answers the plan read with the plan given (or the response
 * given, for one that cannot be read), and every write and read the export and
 * void send. The goods come back on the fulfillment order given; with none,
 * the original fulfillment order is still closed by its fulfillment, as it is
 * when no cancel was sent.
 *
 * @param  array<string, mixed>|MockResponse  $plan
 */
function fakeDemoWriteBackStore(array|MockResponse $plan, ?string $reopenedOn = 'gid://shopify/FulfillmentOrder/54321'): void
{
    Saloon::fake([GraphQL::class => function (PendingRequest $request) use ($plan, $reopenedOn): MockResponse {
        $query = (string) ($request->body()?->all()['query'] ?? '');

        return match (true) {
            str_contains($query, 'plan {') => $plan instanceof MockResponse
                ? $plan
                : MockResponse::make(['data' => ['shop' => ['plan' => $plan]]]),
            str_contains($query, 'fulfillmentCreate(') => MockResponse::make(['data' => ['fulfillmentCreate' => [
                'fulfillment' => ['id' => 'gid://shopify/Fulfillment/1', 'status' => 'SUCCESS'],
                'userErrors' => [],
            ]]]),
            str_contains($query, 'fulfillmentCancel(') => MockResponse::make(['data' => ['fulfillmentCancel' => [
                'fulfillment' => ['id' => 'gid://shopify/Fulfillment/1', 'status' => 'CANCELLED'],
                'userErrors' => [],
            ]]]),
            str_contains($query, 'ShopifyOrderFulfillmentOrders') => MockResponse::make(['data' => ['order' => [
                'id' => 'gid://shopify/Order/800',
                'fulfillmentOrders' => [
                    'pageInfo' => ['hasNextPage' => false],
                    'nodes' => [$reopenedOn === null ? [
                        'id' => 'gid://shopify/FulfillmentOrder/12345',
                        'status' => 'CLOSED',
                        'supportedActions' => [],
                        'assignedLocation' => ['location' => ['id' => 'gid://shopify/Location/1']],
                        'lineItems' => ['pageInfo' => ['hasNextPage' => false], 'nodes' => []],
                    ] : [
                        'id' => $reopenedOn,
                        'status' => 'OPEN',
                        'supportedActions' => [['action' => 'CREATE_FULFILLMENT']],
                        'assignedLocation' => ['location' => ['id' => 'gid://shopify/Location/1']],
                        'lineItems' => ['pageInfo' => ['hasNextPage' => false], 'nodes' => demoWriteBackLineItems()],
                    ]],
                ],
            ]]]),
            default => throw new LogicException('Unexpected Shopify request: '.$query),
        };
    }]);
}

/**
 * Whether any Shopify request sent so far carried this text in its query.
 */
function demoWriteBackSent(string $needle): bool
{
    foreach (Saloon::mockClient()->getRecordedResponses() as $response) {
        $request = $response->getPendingRequest()->getRequest();

        if ($request instanceof GraphQL && str_contains((string) ($request->body()->all()['query'] ?? ''), $needle)) {
            return true;
        }
    }

    return false;
}

/**
 * A package shipped on our own USPS account for a Shopify order, exportable to
 * its connection. With a fulfillment ID, its export already made one.
 */
function demoWriteBackShopifyPackage(?string $fulfillmentId = null): Package
{
    $source = createShopifyDataSource(['export_enabled' => true], ['oauth_access_token' => 'shpat_test_token']);

    $shipment = Shipment::factory()->create([
        'data_source_id' => $source->id,
        'source_record_id' => 'gid://shopify/FulfillmentOrder/12345',
        'metadata' => [
            'shopify_order_id' => 'gid://shopify/Order/800',
            'shopify_fulfillment_order_id' => 'gid://shopify/FulfillmentOrder/12345',
            'shopify_location_id' => 'gid://shopify/Location/1',
            ShopifyGoodsFingerprint::METADATA_KEY => app(ShopifyGoodsFingerprint::class)
                ->forLineItems(demoWriteBackLineItems(), 'remainingQuantity'),
        ],
    ]);

    $package = Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'carrier' => 'USPS',
        'tracking_number' => '9400111899223456789012',
        'exported' => $fulfillmentId !== null,
    ]);

    $package->labels()->update(['shopify_fulfillment_id' => $fulfillmentId]);

    return $package;
}

function demoWriteBackExportRow(Package $package, DataSource $source): ?PackageExport
{
    return PackageExport::query()
        ->where('package_id', $package->id)
        ->where('data_source_id', $source->id)
        ->first();
}

/*
|--------------------------------------------------------------------------
| Exports
|--------------------------------------------------------------------------
*/

it('sends a live store no fulfillment in demo, and settles the export without failing or retrying it', function (): void {
    $this->app['env'] = 'demo';
    fakeDemoWriteBackStore(demoWriteBackLivePlan());
    $package = demoWriteBackShopifyPackage();
    $source = $package->shipment->dataSource;

    // What shipping the Package runs: its PackageShipped listener.
    (new ExportShippedPackage)->handle(new PackageShipped($package, $package->shipment));

    expect(demoWriteBackSent('mutation'))->toBeFalse();

    $export = demoWriteBackExportRow($package, $source);

    expect($export->status)->toBe(PackageExportStatus::Skipped)
        ->and($export->last_error)->toBeNull()
        ->and($export->completed_at)->not->toBeNull()
        ->and($package->fresh()->exported)->toBeTrue()
        ->and($package->labels()->sole()->shopify_fulfillment_id)->toBeNull();

    $this->travel(7)->hours();
    $service = app(PackageExportService::class);

    expect($service->previewUnexported(includePermanentFailures: true))->toBeEmpty()
        ->and($service->exportUnexported())->toBeEmpty()
        ->and($service->countRecoverablePermanentFailures())->toBe(0)
        ->and($service->exportPackage($package->fresh())->success)->toBeTrue()
        ->and(demoWriteBackExportRow($package, $source)->attempts)->toBe(1)
        ->and(demoWriteBackSent('mutation'))->toBeFalse();
});

it('reports a skipped export as a settled success, not an error', function (): void {
    $this->app['env'] = 'demo';
    fakeDemoWriteBackStore(demoWriteBackLivePlan());
    $package = demoWriteBackShopifyPackage();

    $result = app(PackageExportService::class)->exportPackage($package);

    expect($result->success)->toBeTrue()
        ->and($result->deferred)->toBeFalse()
        ->and($result->hasErrors())->toBeFalse()
        ->and($result->shouldRetry())->toBeFalse()
        ->and($result->destinationsAttempted)->toBe(0)
        ->and($result->destinationsSkipped)->toBe(1);
});

it('sends a development store its fulfillment in demo, as in production', function (): void {
    $this->app['env'] = 'demo';
    fakeDemoWriteBackStore(demoWriteBackDevelopmentPlan());
    $package = demoWriteBackShopifyPackage();

    $result = app(PackageExportService::class)->exportPackage($package);

    expect(demoWriteBackSent('fulfillmentCreate('))->toBeTrue()
        ->and($result->destinationsSucceeded)->toBe(1)
        ->and(demoWriteBackExportRow($package, $package->shipment->dataSource)->status)->toBe(PackageExportStatus::Succeeded)
        ->and($package->labels()->sole()->shopify_fulfillment_id)->toBe('gid://shopify/Fulfillment/1');
});

it('sends a store whose plan cannot be read nothing in demo, and retries the export', function (MockResponse $planResponse): void {
    $this->app['env'] = 'demo';
    fakeDemoWriteBackStore($planResponse);
    $package = demoWriteBackShopifyPackage();
    $source = $package->shipment->dataSource;

    $result = app(PackageExportService::class)->exportPackage($package);
    $export = demoWriteBackExportRow($package, $source);

    expect(demoWriteBackSent('plan {'))->toBeTrue()
        ->and(demoWriteBackSent('fulfillmentCreate('))->toBeFalse()
        ->and($export->status)->toBe(PackageExportStatus::RetryableFailed)
        ->and($export->last_error)->toBe(PackageExportService::UnknownStoreError)
        ->and($export->completed_at)->toBeNull()
        ->and($package->fresh()->exported)->toBeFalse()
        ->and($result->success)->toBeFalse()
        ->and($result->shouldRetry())->toBeTrue()
        ->and($result->destinationsAttempted)->toBe(0)
        ->and($result->destinationsSkipped)->toBe(0);

    // The plan reads again once the failure is no longer cached and the
    // retry's backoff has passed: a development store then gets its fulfillment.
    fakeDemoWriteBackStore(demoWriteBackDevelopmentPlan());
    $this->travel(6)->minutes();
    $service = app(PackageExportService::class);

    expect($service->previewUnexported()->pluck('id')->all())->toBe([$package->id]);

    $service->exportUnexported();

    expect(demoWriteBackSent('fulfillmentCreate('))->toBeTrue()
        ->and(demoWriteBackExportRow($package, $source)->status)->toBe(PackageExportStatus::Succeeded)
        ->and(demoWriteBackExportRow($package, $source)->attempts)->toBe(2)
        ->and($package->fresh()->exported)->toBeTrue();
})->with([
    'a server error' => [MockResponse::make(['errors' => 'Internal Server Error'], 500)],
    'no plan in the reply' => [MockResponse::make(['errors' => [['message' => 'Access denied for plan field.']]])],
]);

it('fails an export permanently once a plan has stayed unreadable through every retry', function (): void {
    $this->app['env'] = 'demo';
    fakeDemoWriteBackStore(MockResponse::make(['errors' => 'Internal Server Error'], 500));
    $package = demoWriteBackShopifyPackage();
    $source = $package->shipment->dataSource;
    $export = PackageExport::query()->create([
        'package_id' => $package->id,
        'data_source_id' => $source->id,
        'status' => PackageExportStatus::RetryableFailed,
        'attempts' => 31,
    ]);
    $this->travel(6)->hours();

    $result = app(PackageExportService::class)->exportPackage($package);

    expect($result->shouldRetry())->toBeFalse()
        ->and($result->hasPermanentErrors())->toBeTrue()
        ->and($export->refresh()->status)->toBe(PackageExportStatus::PermanentlyFailed)
        ->and($export->last_error)->toBe(PackageExportService::UnknownStoreError)
        ->and($package->fresh()->exported)->toBeFalse()
        ->and(demoWriteBackSent('fulfillmentCreate('))->toBeFalse();
});

it('sends an Amazon connection no confirmation in demo', function (): void {
    $this->app['env'] = 'demo';
    Saloon::fake([]);

    $source = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'name' => 'Amazon Export',
        'settings' => ['channel_name' => 'Amazon', 'marketplace_ids' => ['ATVPDKIKX0DER'], 'export_enabled' => true],
        'secret_settings' => ['client_id' => 'id', 'client_secret' => 'secret', 'refresh_token' => 'token'],
    ]);
    $shipment = Shipment::factory()->create([
        'data_source_id' => $source->id,
        'metadata' => ['amazon_order_id' => '111-2222222-3333333'],
    ]);
    $package = Package::factory()->shipped()->create(['shipment_id' => $shipment->id, 'exported' => false]);

    $result = app(PackageExportService::class)->exportPackage($package);

    Saloon::assertNotSent(ConfirmShipment::class);
    Saloon::assertNothingSent();

    expect($result->success)->toBeTrue()
        ->and(demoWriteBackExportRow($package, $source)->status)->toBe(PackageExportStatus::Skipped)
        ->and($package->fresh()->exported)->toBeTrue();
});

describe('a Database connection', function (): void {
    beforeEach(function (): void {
        $this->erpPath = tempnam(sys_get_temp_dir(), 'polybag-demo-erp-');

        config(['database.connections.demo_erp_fixture' => [
            'driver' => 'sqlite',
            'database' => $this->erpPath,
            'prefix' => '',
        ]]);

        DB::connection('demo_erp_fixture')->statement('CREATE TABLE orders (order_no TEXT, tracking_no TEXT)');
        DB::connection('demo_erp_fixture')->table('orders')->insert(['order_no' => 'SO-DEMO-1']);

        $this->database = DataSource::factory()->create([
            'name' => 'ERP',
            'source_type' => DatabaseSource::class,
            'settings' => [
                'db_driver' => 'sqlite',
                'db_database' => $this->erpPath,
                'export_enabled' => true,
                'export_query' => 'UPDATE orders SET tracking_no = :tracking_number WHERE order_no = :shipment_reference',
            ],
        ]);

        $this->package = Package::factory()->shipped()->create([
            'shipment_id' => Shipment::factory()->create([
                'data_source_id' => $this->database->id,
                'shipment_reference' => 'SO-DEMO-1',
            ])->id,
            'tracking_number' => 'TRK-DEMO-1',
        ]);
    });

    afterEach(function (): void {
        DB::purge('demo_erp_fixture');
        File::delete($this->erpPath);
    });

    it('is exported to in demo as before', function (): void {
        $this->app['env'] = 'demo';

        $result = app(PackageExportService::class)->exportPackage($this->package);

        expect($result->destinationsSucceeded)->toBe(1)
            ->and(DB::connection('demo_erp_fixture')->table('orders')->value('tracking_no'))->toBe('TRK-DEMO-1')
            ->and(demoWriteBackExportRow($this->package, $this->database)->status)->toBe(PackageExportStatus::Succeeded);
    });

    it('is exported to in demo while a live store with global export on is skipped', function (): void {
        $this->app['env'] = 'demo';
        config()->set('features.multi_client_enabled', true);
        fakeDemoWriteBackStore(demoWriteBackLivePlan());
        $live = createShopifyDataSource(['export_enabled' => true], ['oauth_access_token' => 'shpat_test_token']);
        $live->update(['global_export' => true]);

        $result = app(PackageExportService::class)->exportPackage($this->package);

        expect($result->success)->toBeTrue()
            ->and($result->destinationsSucceeded)->toBe(1)
            ->and($result->destinationsSkipped)->toBe(1)
            ->and(DB::connection('demo_erp_fixture')->table('orders')->value('tracking_no'))->toBe('TRK-DEMO-1')
            ->and(demoWriteBackExportRow($this->package, $live)->status)->toBe(PackageExportStatus::Skipped)
            ->and($this->package->fresh()->exported)->toBeTrue()
            ->and(demoWriteBackSent('fulfillmentCreate('))->toBeFalse();
    });

    it('retries a failed Database export without re-sending a skipped live store', function (): void {
        $this->app['env'] = 'demo';
        config()->set('features.multi_client_enabled', true);
        fakeDemoWriteBackStore(demoWriteBackLivePlan());
        $live = createShopifyDataSource(['export_enabled' => true], ['oauth_access_token' => 'shpat_test_token']);
        $live->update(['global_export' => true]);
        DB::connection('demo_erp_fixture')->statement('ALTER TABLE orders RENAME TO orders_offline');
        $service = app(PackageExportService::class);

        $result = $service->exportPackage($this->package);
        $skipped = demoWriteBackExportRow($this->package, $live);

        expect($result->shouldRetry())->toBeTrue()
            ->and($skipped->status)->toBe(PackageExportStatus::Skipped)
            ->and(demoWriteBackExportRow($this->package, $this->database)->status)->toBe(PackageExportStatus::RetryableFailed)
            ->and($this->package->fresh()->exported)->toBeFalse();

        DB::connection('demo_erp_fixture')->statement('ALTER TABLE orders_offline RENAME TO orders');
        $this->travel(6)->minutes();

        expect($service->previewUnexported()->pluck('id')->all())->toBe([$this->package->id]);

        $results = $service->exportUnexported();

        expect($results[$this->package->id]->destinationsSucceeded)->toBe(1)
            ->and($results[$this->package->id]->destinationsSkipped)->toBe(0)
            ->and(demoWriteBackExportRow($this->package, $live)->attempts)->toBe(1)
            ->and(demoWriteBackExportRow($this->package, $live)->status)->toBe(PackageExportStatus::Skipped)
            ->and(demoWriteBackExportRow($this->package, $live)->completed_at)->toEqual($skipped->completed_at)
            ->and(demoWriteBackExportRow($this->package, $this->database)->status)->toBe(PackageExportStatus::Succeeded)
            ->and(DB::connection('demo_erp_fixture')->table('orders')->value('tracking_no'))->toBe('TRK-DEMO-1')
            ->and($this->package->fresh()->exported)->toBeTrue()
            ->and(demoWriteBackSent('fulfillmentCreate('))->toBeFalse();
    });
});

it('exports to a store outside demo without asking its plan', function (string $environment): void {
    $this->app['env'] = $environment;
    fakeDemoWriteBackStore(demoWriteBackLivePlan());
    $package = demoWriteBackShopifyPackage();

    $result = app(PackageExportService::class)->exportPackage($package);

    expect(demoWriteBackSent('plan {'))->toBeFalse()
        ->and(demoWriteBackSent('fulfillmentCreate('))->toBeTrue()
        ->and($result->destinationsSucceeded)->toBe(1)
        ->and($result->destinationsSkipped)->toBe(0)
        ->and(demoWriteBackExportRow($package, $package->shipment->dataSource)->status)->toBe(PackageExportStatus::Succeeded);
})->with(['production', 'local']);

/*
|--------------------------------------------------------------------------
| Fulfillment cancel on void
|--------------------------------------------------------------------------
*/

it('sends a live store no fulfillment cancel on a void in demo, and still reads for a replacement', function (): void {
    $this->app['env'] = 'demo';
    // With no cancel, the fulfillment order stays closed by the fulfillment,
    // and Shopify reports no open one to move the shipment onto.
    fakeDemoWriteBackStore(demoWriteBackLivePlan(), reopenedOn: null);
    $package = demoWriteBackShopifyPackage(fulfillmentId: 'gid://shopify/Fulfillment/1');

    $result = app(PackageLabelWorkflow::class)->voidLabel($package, User::factory()->manager()->create());

    expect(demoWriteBackSent('fulfillmentCancel('))->toBeFalse()
        ->and(demoWriteBackSent('ShopifyOrderFulfillmentOrders'))->toBeTrue()
        ->and($result->success)->toBeTrue()
        ->and($result->warning)->toContain('Demo mode sends nothing to a live store')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);

    $shipment = $package->shipment->refresh();

    expect($shipment->metadata)->not->toHaveKey('shopify_fulfillment_order_id')
        ->and($shipment->source_record_id)->toBe('gid://shopify/FulfillmentOrder/12345');

    $note = AuditLog::query()
        ->where('auditable_type', $shipment->getMorphClass())
        ->where('auditable_id', $shipment->id)
        ->get()
        ->firstWhere(fn (AuditLog $log): bool => array_key_exists('superseded_fulfillment_order_id', $log->metadata ?? []));

    expect($note->metadata['superseded_fulfillment_order_id'])->toBe('gid://shopify/FulfillmentOrder/12345')
        ->and($note->metadata['fulfillment_order_id'])->toBeNull()
        ->and($note->metadata['reason'])->not->toContain('closed the fulfillment order on the void');
});

it('sends no fulfillment cancel on a void in demo when the plan cannot be read, and says so', function (): void {
    $this->app['env'] = 'demo';
    fakeDemoWriteBackStore(MockResponse::make(['errors' => 'Internal Server Error'], 500), reopenedOn: null);
    $package = demoWriteBackShopifyPackage(fulfillmentId: 'gid://shopify/Fulfillment/1');

    $result = app(PackageLabelWorkflow::class)->voidLabel($package, User::factory()->manager()->create());

    expect(demoWriteBackSent('fulfillmentCancel('))->toBeFalse()
        ->and(demoWriteBackSent('ShopifyOrderFulfillmentOrders'))->toBeTrue()
        ->and($result->success)->toBeTrue()
        ->and($result->warning)->toContain('could not read this Shopify store\'s plan')
        ->and($result->warning)->toContain('left this order\'s fulfillment in place')
        ->and($result->warning)->not->toContain('live store');
});

it('cancels a development store\'s fulfillment on a void in demo, as in production', function (): void {
    $this->app['env'] = 'demo';
    fakeDemoWriteBackStore(demoWriteBackDevelopmentPlan(), reopenedOn: 'gid://shopify/FulfillmentOrder/54321');
    $package = demoWriteBackShopifyPackage(fulfillmentId: 'gid://shopify/Fulfillment/1');

    $result = app(PackageLabelWorkflow::class)->voidLabel($package, User::factory()->manager()->create());

    expect(demoWriteBackSent('fulfillmentCancel('))->toBeTrue()
        ->and($result->warning)->toBeNull()
        ->and($package->shipment->refresh()->metadata['shopify_fulfillment_order_id'])
        ->toBe('gid://shopify/FulfillmentOrder/54321');
});

it('cancels the fulfillment on a void outside demo without asking the plan', function (string $environment): void {
    $this->app['env'] = $environment;
    fakeDemoWriteBackStore(demoWriteBackLivePlan());
    $package = demoWriteBackShopifyPackage(fulfillmentId: 'gid://shopify/Fulfillment/1');

    $result = app(PackageLabelWorkflow::class)->voidLabel($package, User::factory()->manager()->create());

    expect(demoWriteBackSent('plan {'))->toBeFalse()
        ->and(demoWriteBackSent('fulfillmentCancel('))->toBeTrue()
        ->and($result->warning)->toBeNull();
})->with(['production', 'local']);
