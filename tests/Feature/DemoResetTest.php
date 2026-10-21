<?php

use App\Console\Commands\DemoReset;
use App\Enums\CarrierPackaging;
use App\Enums\LabelBatchItemStatus;
use App\Enums\LabelBatchStatus;
use App\Enums\PackageStatus;
use App\Enums\ShipmentStatus;
use App\Enums\ShipmentStatusOrigin;
use App\Enums\TrackingStatus;
use App\Models\BoxSize;
use App\Models\ChannelAlias;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\LabelBatch;
use App\Models\Location;
use App\Models\Manifest;
use App\Models\Package;
use App\Models\PackageLabel;
use App\Models\RateQuote;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Models\ShippingMethodAlias;
use App\Models\User;
use App\Services\ShipmentImport\ExportResult;
use App\Services\ShipmentImport\PackageExportService;
use App\Services\ShipmentImport\ShipmentImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function importConnectionFor(DataSource $source): string
{
    return 'import_'.$source->id;
}

function setUpImportDatabase(DataSource $source): void
{
    $connection = importConnectionFor($source);

    Config::set("database.connections.{$connection}", [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    Schema::connection($connection)->create('shipments', function ($table): void {
        $table->string('id')->primary();
        $table->string('first_name')->nullable();
        $table->string('last_name')->nullable();
        $table->string('company')->nullable();
        $table->string('address1')->nullable();
        $table->string('address2')->nullable();
        $table->string('city')->nullable();
        $table->string('state')->nullable();
        $table->string('zip')->nullable();
        $table->string('country')->default('US');
        $table->boolean('residential')->default(true);
        $table->string('phone')->nullable();
        $table->string('email')->nullable();
        $table->decimal('value', 10, 2)->nullable();
        $table->string('shipping_method')->nullable();
        $table->string('channel')->nullable();
        $table->string('status')->default('ready');
        $table->string('exported')->default('n');
        $table->string('tracking_number')->nullable();
        $table->string('carrier')->nullable();
        $table->dateTime('exported_at')->nullable();
        $table->dateTime('created_at')->nullable();
        $table->dateTime('updated_at')->nullable();
    });

    Schema::connection($connection)->create('shipment_items', function ($table): void {
        $table->id();
        $table->string('shipment_id');
        $table->string('sku');
        $table->string('name')->nullable();
        $table->string('barcode')->nullable();
        $table->integer('quantity');
        $table->decimal('weight', 10, 2)->nullable();
        $table->decimal('value', 10, 2)->nullable();
        $table->boolean('transparency')->default(false);
    });
}

function insertImportShipment(DataSource $source, string $id, string $createdAt, string $channel = 'Shopify'): void
{
    $connection = importConnectionFor($source);

    DB::connection($connection)->table('shipments')->insert([
        'id' => $id,
        'first_name' => 'Demo',
        'last_name' => 'Customer',
        'address1' => '1600 Pennsylvania Avenue NW',
        'city' => 'Washington',
        'state' => 'DC',
        'zip' => '20500',
        'country' => 'US',
        'phone' => '202-456-1111',
        'value' => 49.99,
        'shipping_method' => '1',
        'channel' => $channel,
        'status' => 'ready',
        'exported' => 'n',
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    DB::connection($connection)->table('shipment_items')->insert([
        'shipment_id' => $id,
        'sku' => 'SKU-'.$id,
        'name' => 'Demo Product',
        'barcode' => 'BC-'.$id,
        'quantity' => 2,
        'weight' => 1.5,
        'value' => 24.99,
    ]);
}

beforeEach(function (): void {
    Client::factory()->default()->create();
    Location::factory()->default()->create();
    User::factory()->create();
    ShippingMethod::factory()->create();

    $this->dataSource = DataSource::factory()->create([
        'settings' => [
            'shipments_table' => 'shipments',
            'shipment_items_table' => 'shipment_items',
            'export_enabled' => true,
            'export_query' => "update shipments set exported = 'y', tracking_number = :tracking_number, carrier = :carrier, exported_at = CURRENT_TIMESTAMP where id = :shipment_reference",
        ],
    ]);

    setUpImportDatabase($this->dataSource);
});

it('explicitly truncates the package export ledger during reset', function (): void {
    $tables = (new ReflectionClass(DemoReset::class))->getConstant('TRANSACTIONAL_TABLES');

    expect($tables)->toContain('package_exports');
});

it('resets demo data end to end', function (): void {
    // Leftover transactional data from a previous demo that must be wiped
    Package::factory()->shipped()->create();

    insertImportShipment($this->dataSource, 'D00OLD0001', now('UTC')->subDays(10)->format('Y-m-d H:i:s'));
    insertImportShipment($this->dataSource, 'D00OLD0002', now('UTC')->subDays(2)->format('Y-m-d H:i:s'), 'Amazon');
    insertImportShipment($this->dataSource, 'D00NEW0001', now('UTC')->subMinutes(30)->format('Y-m-d H:i:s'));

    $this->artisan('demo:reset', ['--days' => 30, '--open-hours' => 8, '--full-export' => true])->assertSuccessful();

    // Leftover data is gone; only the three imported shipments remain
    expect(Shipment::count())->toBe(3)
        ->and(Shipment::whereNull('data_source_id')->count())->toBe(0);

    // Historical shipments are shipped with fabricated, manifested packages
    foreach (['D00OLD0001', 'D00OLD0002'] as $reference) {
        $shipment = Shipment::where('shipment_reference', $reference)->firstOrFail();

        expect($shipment->status)->toBe(ShipmentStatus::Shipped)
            ->and($shipment->created_at->lessThan(now()->subDay()))->toBeTrue()
            ->and($shipment->packages)->toHaveCount(1);

        $package = $shipment->packages->first();

        expect($shipment->status_origin)->toBe(ShipmentStatusOrigin::PolyBag)
            ->and($shipment->status_changed_at?->equalTo($package->shipped_at))->toBeTrue();

        expect($package->status)->toBe(PackageStatus::Shipped)
            ->and($package->tracking_number)->not->toBeNull()
            ->and($package->cost)->not->toBeNull()
            ->and($package->manifest_id)->not->toBeNull()
            ->and($package->packageItems)->toHaveCount(1)
            // Fabricated shipped, so the label row a shipped package has to
            // have is fabricated with it, from the same facts.
            ->and($package->activeLabel?->tracking_number)->toBe($package->tracking_number)
            ->and((float) $package->activeLabel?->cost)->toBe((float) $package->cost);
    }

    // The leftover package's label went with it: nothing orphaned an active row.
    expect(PackageLabel::count())->toBe(Package::where('status', PackageStatus::Shipped)->count());

    // The recent shipment stays open for the live demo
    $recent = Shipment::where('shipment_reference', 'D00NEW0001')->firstOrFail();

    expect($recent->status)->toBe(ShipmentStatus::Open)
        ->and($recent->packages)->toHaveCount(0);

    // Channel aliases were created so imports resolve channels
    expect(ChannelAlias::where('reference', 'Shopify')->exists())->toBeTrue()
        ->and(ChannelAlias::where('reference', 'Amazon')->exists())->toBeTrue();

    // Tracking data was written back to the import database for history only
    $connection = importConnectionFor($this->dataSource);
    $exported = DB::connection($connection)->table('shipments')->where('exported', 'y')->pluck('id');

    expect($exported)->toContain('D00OLD0001', 'D00OLD0002')
        ->and($exported)->not->toContain('D00NEW0001')
        ->and(DB::connection($connection)->table('shipments')->where('id', 'D00OLD0001')->value('tracking_number'))->not->toBeNull();

    // Dashboard stats were rebuilt
    expect(DB::table('daily_shipping_stats')->count())->toBeGreaterThan(0);
});

it('refuses before wiping anything when a demo shipment would have no shipping method', function (?string $reference, string $named): void {
    $leftover = Package::factory()->shipped()->create();

    insertImportShipment($this->dataSource, 'D00NEW0001', now('UTC')->subMinutes(30)->format('Y-m-d H:i:s'));
    DB::connection(importConnectionFor($this->dataSource))->table('shipments')->update(['shipping_method' => $reference]);

    // Nothing is bought for a shipment with no method (`carrier-catalog-reset/16`),
    // so a reset must not leave one open.
    $this->artisan('demo:reset', ['--days' => 30, '--open-hours' => 8])
        ->expectsOutputToContain($named)
        ->assertFailed();

    expect(Package::whereKey($leftover->id)->exists())->toBeTrue()
        ->and(Shipment::where('shipment_reference', 'D00NEW0001')->exists())->toBeFalse();
})->with([
    'an unmapped reference' => ['EXPRESS-99', 'EXPRESS-99'],
    'no reference at all' => [null, '(none)'],
]);

it('checks each row\'s shipping method against the client its client column names', function (): void {
    $acme = Client::factory()->create(['name' => 'Acme']);
    Client::factory()->create(['name' => 'Bolt']);
    $express = ShippingMethod::factory()->create();
    ShippingMethodAlias::create(['client_id' => $acme->id, 'reference' => 'EXPRESS', 'shipping_method_id' => $express->id]);

    $this->dataSource->update(['settings' => [...$this->dataSource->settings, 'client_column' => 'client']]);
    $connection = importConnectionFor($this->dataSource);
    Schema::connection($connection)->table('shipments', fn ($table) => $table->string('client')->nullable());

    insertImportShipment($this->dataSource, 'D00ACME001', now('UTC')->subMinutes(30)->format('Y-m-d H:i:s'));
    DB::connection($connection)->table('shipments')->update(['shipping_method' => 'EXPRESS', 'client' => ' acme ']);

    // Acme's alias resolves Acme's row, though the source itself has no client.
    $this->artisan('demo:reset', ['--days' => 30, '--open-hours' => 8])->assertSuccessful();

    expect(Shipment::where('shipment_reference', 'D00ACME001')->sole()->shipping_method_id)->toBe($express->id);

    // The same reference on Bolt's row resolves to nothing: the alias is Acme's.
    insertImportShipment($this->dataSource, 'D00BOLT001', now('UTC')->subMinutes(20)->format('Y-m-d H:i:s'));
    DB::connection($connection)->table('shipments')->where('id', 'D00BOLT001')->update(['shipping_method' => 'EXPRESS', 'client' => 'Bolt']);

    $this->artisan('demo:reset', ['--days' => 30, '--open-hours' => 8])
        ->expectsOutputToContain('EXPRESS (Bolt)')
        ->assertFailed();
});

it('clears package export claims before package IDs are reused', function (): void {
    insertImportShipment($this->dataSource, 'D00REPEAT01', now('UTC')->subDays(5)->format('Y-m-d H:i:s'));

    $this->artisan('demo:reset', ['--full-export' => true])->assertSuccessful();

    $connection = importConnectionFor($this->dataSource);
    expect(DB::table('package_exports')->count())->toBeGreaterThan(0);

    DB::connection($connection)->table('shipments')
        ->where('id', 'D00REPEAT01')
        ->update([
            'exported' => 'n',
            'tracking_number' => null,
            'carrier' => null,
            'exported_at' => null,
        ]);

    $this->artisan('demo:reset', ['--full-export' => true])->assertSuccessful();

    expect(DB::connection($connection)->table('shipments')->where('id', 'D00REPEAT01')->value('tracking_number'))
        ->not->toBeNull();

    // Package IDs were reused; had the first reset's label rows survived the
    // truncate, the second would have died on the unique index.
    expect(PackageLabel::count())->toBe(Package::where('status', PackageStatus::Shipped)->count());
});

it('does not duplicate shipments when the live import runs after a reset', function (): void {
    insertImportShipment($this->dataSource, 'D00OLD0001', now('UTC')->subDays(5)->format('Y-m-d H:i:s'));
    insertImportShipment($this->dataSource, 'D00NEW0001', now('UTC')->subMinutes(10)->format('Y-m-d H:i:s'));

    $this->artisan('demo:reset')->assertSuccessful();

    expect(Shipment::count())->toBe(2);

    // Simulate the scheduled import running with the source's own configuration
    $result = ShipmentImportService::forRecord($this->dataSource->fresh())->import();

    expect($result->shipmentsCreated)->toBe(0)
        ->and(Shipment::count())->toBe(2)
        ->and(Shipment::where('shipment_reference', 'D00OLD0001')->firstOrFail()->status)->toBe(ShipmentStatus::Shipped);
});

it('marks the history exported at the source in one statement by default', function (): void {
    insertImportShipment($this->dataSource, 'D00OLD0001', now('UTC')->subDays(5)->format('Y-m-d H:i:s'));
    insertImportShipment($this->dataSource, 'D00NEW0001', now('UTC')->subMinutes(10)->format('Y-m-d H:i:s'));
    insertImportShipment($this->dataSource, 'D00ANCIENT', now('UTC')->subDays(90)->format('Y-m-d H:i:s'));

    $exportService = Mockery::mock(PackageExportService::class);
    $exportService->shouldNotReceive('exportPackage');
    app()->instance(PackageExportService::class, $exportService);

    $this->artisan('demo:reset', ['--days' => 30])->assertSuccessful();

    $rows = DB::connection(importConnectionFor($this->dataSource))->table('shipments')->pluck('exported', 'id');

    expect($rows['D00OLD0001'])->toBe('y')
        ->and($rows['D00NEW0001'])->toBe('y')
        ->and($rows['D00ANCIENT'])->toBe('n')
        ->and(Package::where('exported', false)->count())->toBe(0)
        ->and(Shipment::count())->toBe(2);
});

it('marks fabricated packages exported without touching the import database when --skip-export is passed', function (): void {
    insertImportShipment($this->dataSource, 'D00OLD0001', now('UTC')->subDays(5)->format('Y-m-d H:i:s'));

    $this->artisan('demo:reset', ['--skip-export' => true])->assertSuccessful();

    $connection = importConnectionFor($this->dataSource);

    expect(Package::where('exported', false)->count())->toBe(0)
        ->and(DB::connection($connection)->table('shipments')->where('exported', 'y')->count())->toBe(0);
});

it('does not abort demo write-back when package exports are deferred', function (): void {
    foreach (range(1, 12) as $index) {
        insertImportShipment(
            $this->dataSource,
            'D00DEFER'.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
            now('UTC')->subDays(2)->format('Y-m-d H:i:s'),
        );
    }

    $exportService = Mockery::mock(PackageExportService::class);
    $exportService->shouldReceive('batch')->andReturnUsing(fn (Closure $export): mixed => $export());
    $exportService->shouldReceive('exportPackage')
        ->andReturn(new ExportResult(success: false, deferred: true));
    app()->instance(PackageExportService::class, $exportService);

    $this->artisan('demo:reset', ['--full-export' => true])
        ->doesntExpectOutputToContain('Aborting export write-back')
        ->assertSuccessful();
});

it('refuses to run outside demo, local, or testing environments', function (): void {
    $this->app['env'] = 'production';

    $this->artisan('demo:reset')->assertFailed();
});

it('fails when no active database data source exists', function (): void {
    $this->dataSource->update(['active' => false]);

    $this->artisan('demo:reset')->assertFailed();
});

it('fabricates rate quotes, own boxes and billing rates for the reports', function (): void {
    $own = BoxSize::factory()->create(['materials_cost' => null]);
    $priced = BoxSize::factory()->create(['materials_cost' => 0.90]);
    BoxSize::factory()->inactive()->create();
    BoxSize::factory()->carrierPackaging(CarrierPackaging::UspsFlatRateEnvelope)->create();
    $client = Client::factory()->create(['pick_fee_first_item' => null, 'pick_fee_additional_item' => null, 'label_fee_per_package' => 0.75]);

    insertImportShipment($this->dataSource, 'D00OLD0001', now('UTC')->subDays(10)->format('Y-m-d H:i:s'));
    insertImportShipment($this->dataSource, 'D00OLD0002', now('UTC')->subDays(2)->format('Y-m-d H:i:s'));

    $this->artisan('demo:reset', ['--days' => 30, '--open-hours' => 8])->assertSuccessful();

    $shipped = Package::where('status', PackageStatus::Shipped)->get();
    expect($shipped)->toHaveCount(2);

    foreach ($shipped as $package) {
        $quotes = RateQuote::where('package_id', $package->id)->get();

        expect($quotes->count())->toBeGreaterThanOrEqual(2)
            ->and($quotes->where('selected', true))->toHaveCount(1)
            ->and((float) $quotes->firstWhere('selected', true)->quoted_price)->toBe((float) $package->cost)
            ->and($package->box_size_id)->toBeIn([$own->id, $priced->id]);
    }

    expect((float) $client->fresh()->pick_fee_first_item)->toBe(1.25)
        ->and((float) $client->fresh()->label_fee_per_package)->toBe(0.75)
        ->and((float) $own->fresh()->materials_cost)->toBe(0.40)
        ->and((float) $priced->fresh()->materials_cost)->toBe(0.90);
});

describe('demo preparation', function (): void {
    beforeEach(function (): void {
        BoxSize::factory()->create(['active' => true, 'carrier_packaging' => null]);
    });

    it('sorts domestic open orders ahead of military ones', function (): void {
        insertImportShipment($this->dataSource, 'D00DOM0001', now('UTC')->subMinutes(90)->format('Y-m-d H:i:s'));
        insertImportShipment($this->dataSource, 'D00APO0001', now('UTC')->subMinutes(10)->format('Y-m-d H:i:s'));
        DB::connection(importConnectionFor($this->dataSource))->table('shipments')
            ->where('id', 'D00APO0001')->update(['city' => 'APO', 'state' => 'AE', 'zip' => '09012']);

        $this->artisan('demo:reset', ['--days' => 30, '--open-hours' => 8])->assertSuccessful();

        $newest = Shipment::where('status', ShipmentStatus::Open)->orderByDesc('created_at')->first();

        expect($newest->shipment_reference)->toBe('D00DOM0001');
    });

    it('sets EU duties terms where a client has none and keeps a chosen one', function (): void {
        $default = Client::query()->where('is_default', true)->first();
        $chosen = Client::factory()->create(['duties_policy' => ['EU' => 'ddu', 'CA' => 'ddp']]);
        insertImportShipment($this->dataSource, 'D00OLD0001', now('UTC')->subDays(2)->format('Y-m-d H:i:s'));

        $this->artisan('demo:reset', ['--days' => 30, '--open-hours' => 8])->assertSuccessful();

        expect($default->fresh()->duties_policy)->toBe(['EU' => 'ddp'])
            ->and($chosen->fresh()->duties_policy)->toBe(['EU' => 'ddu', 'CA' => 'ddp']);
    });

    it('stores a sample label and manifest image so Reprint is offered', function (): void {
        insertImportShipment($this->dataSource, 'D00OLD0001', now('UTC')->subDays(2)->format('Y-m-d H:i:s'));

        $this->artisan('demo:reset', ['--days' => 30, '--open-hours' => 8])->assertSuccessful();

        $package = Package::where('status', PackageStatus::Shipped)->firstOrFail();

        expect(base64_decode((string) $package->label_data))->toStartWith('%PDF-')
            ->and(base64_decode((string) Manifest::firstOrFail()->image))->toStartWith('%PDF-');
    });

    it('delivers packages shipped more than three days ago and leaves newer ones', function (): void {
        insertImportShipment($this->dataSource, 'D00OLD0001', now('UTC')->subDays(10)->format('Y-m-d H:i:s'));
        insertImportShipment($this->dataSource, 'D00OLD0002', now('UTC')->subHours(20)->format('Y-m-d H:i:s'));

        $this->artisan('demo:reset', ['--days' => 30, '--open-hours' => 8])->assertSuccessful();

        $old = Shipment::where('shipment_reference', 'D00OLD0001')->firstOrFail()->packages->first();
        $new = Shipment::where('shipment_reference', 'D00OLD0002')->firstOrFail()->packages->first();

        expect($old->tracking_status)->toBe(TrackingStatus::Delivered)
            ->and($old->delivered_at->greaterThan($old->shipped_at))->toBeTrue()
            ->and($new->tracking_status)->toBeNull();
    });

    it('seeds one finished label batch with two failures', function (): void {
        foreach (range(1, 3) as $i) {
            insertImportShipment($this->dataSource, "D00OLD000{$i}", now('UTC')->subDays(2)->format('Y-m-d H:i:s'));
            insertImportShipment($this->dataSource, "D00NEW000{$i}", now('UTC')->subMinutes(30)->format('Y-m-d H:i:s'));
        }

        $this->artisan('demo:reset', ['--days' => 30, '--open-hours' => 8])->assertSuccessful();

        $batch = LabelBatch::with('items')->sole();

        expect($batch->status)->toBe(LabelBatchStatus::CompletedWithErrors)
            ->and($batch->successful_shipments)->toBe(3)
            ->and($batch->failed_shipments)->toBe(2)
            ->and($batch->items->where('status', LabelBatchItemStatus::Failed))->toHaveCount(2)
            ->and($batch->items->where('status', LabelBatchItemStatus::Failed)->first()->error_message)->not->toBeEmpty();
    });
});
