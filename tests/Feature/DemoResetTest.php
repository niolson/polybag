<?php

use App\Console\Commands\DemoReset;
use App\Enums\PackageStatus;
use App\Enums\ShipmentStatus;
use App\Models\ChannelAlias;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Location;
use App\Models\Package;
use App\Models\PackageLabel;
use App\Models\Shipment;
use App\Models\ShippingMethod;
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

    $this->artisan('demo:reset', ['--days' => 30, '--open-hours' => 8])->assertSuccessful();

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

it('clears package export claims before package IDs are reused', function (): void {
    insertImportShipment($this->dataSource, 'D00REPEAT01', now('UTC')->subDays(5)->format('Y-m-d H:i:s'));

    $this->artisan('demo:reset')->assertSuccessful();

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

    $this->artisan('demo:reset')->assertSuccessful();

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
    $exportService->shouldReceive('exportPackage')
        ->andReturn(new ExportResult(success: false, deferred: true));
    app()->instance(PackageExportService::class, $exportService);

    $this->artisan('demo:reset')
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
