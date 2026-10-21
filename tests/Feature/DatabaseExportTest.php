<?php

use App\Enums\PackageExportStatus;
use App\Models\DataSource;
use App\Models\Package;
use App\Models\PackageExport;
use App\Models\Shipment;
use App\Services\ShipmentImport\PackageExportService;
use App\Services\ShipmentImport\Sources\DatabaseSource;
use App\Services\SshTunnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

/**
 * Stands in for the SSH tunnel, counting what the export does with it.
 */
class FakeExportTunnel extends SshTunnel
{
    public static int $opened = 0;

    public static int $closed = 0;

    public static ?string $failure = null;

    private bool $open = false;

    public function open(): int
    {
        self::$opened++;

        if (self::$failure !== null) {
            throw new RuntimeException(self::$failure);
        }

        $this->open = true;

        return 1;
    }

    public function close(): void
    {
        if ($this->open) {
            self::$closed++;
            $this->open = false;
        }
    }
}

beforeEach(function (): void {
    $this->erpPath = tempnam(sys_get_temp_dir(), 'polybag-erp-');

    config(['database.connections.erp_fixture' => [
        'driver' => 'sqlite',
        'database' => $this->erpPath,
        'prefix' => '',
    ]]);

    DB::connection('erp_fixture')->statement('CREATE TABLE orders (order_no TEXT, tracking_no TEXT, postage_cost REAL, height REAL)');

    FakeExportTunnel::$opened = 0;
    FakeExportTunnel::$closed = 0;
    FakeExportTunnel::$failure = null;

    $this->app->bind(SshTunnel::class, fn ($app, array $parameters): SshTunnel => new FakeExportTunnel(...$parameters));
});

afterEach(function (): void {
    DB::purge('erp_fixture');
    File::delete($this->erpPath);
});

/**
 * @param  array<string, mixed>  $settings
 */
function erpConnection(string $erpPath, array $settings = []): DataSource
{
    return DataSource::factory()->create([
        'name' => 'ERP',
        'source_type' => DatabaseSource::class,
        'settings' => array_merge([
            'db_driver' => 'sqlite',
            'db_database' => $erpPath,
            'export_enabled' => true,
            'export_query' => 'UPDATE orders SET tracking_no = :tracking_number, postage_cost = :cost, height = :height WHERE order_no = :shipment_reference',
        ], $settings),
    ]);
}

/**
 * @return array<string, mixed>
 */
function tunnelSettings(): array
{
    return [
        'ssh_enabled' => true,
        'ssh_host' => 'bastion.example.test',
        'ssh_user' => 'polybag',
        'ssh_host_key' => 'bastion.example.test ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExample',
        'ssh_remote_host' => '127.0.0.1',
        'ssh_remote_port' => 3306,
    ];
}

function shippedErpPackage(DataSource $source, string $orderNo): Package
{
    DB::connection('erp_fixture')->table('orders')->insert(['order_no' => $orderNo]);

    $shipment = Shipment::factory()->create([
        'data_source_id' => $source->id,
        'shipment_reference' => $orderNo,
    ]);

    return Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'tracking_number' => "TRK-{$orderNo}",
        'cost' => 7.25,
        'height' => 4.5,
    ]);
}

function erpOrder(string $orderNo): ?object
{
    return DB::connection('erp_fixture')->table('orders')->where('order_no', $orderNo)->first();
}

it('binds cost and dimensions to a Database export with no field mapping', function (): void {
    $source = erpConnection($this->erpPath);
    $package = shippedErpPackage($source, 'SO-1');

    $result = app(PackageExportService::class)->exportPackage($package);

    expect($result->success)->toBeTrue()
        ->and(erpOrder('SO-1')->tracking_no)->toBe('TRK-SO-1')
        ->and((float) erpOrder('SO-1')->postage_cost)->toBe(7.25)
        ->and((float) erpOrder('SO-1')->height)->toBe(4.5);
});

it('fails an export query naming a parameter the export cannot supply, by name', function (): void {
    $source = erpConnection($this->erpPath, [
        'export_query' => 'UPDATE orders SET tracking_no = :tracking_number, postage_cost = :shipper_notes WHERE order_no = :shipment_reference',
    ]);
    $package = shippedErpPackage($source, 'SO-1');

    $result = app(PackageExportService::class)->exportPackage($package);

    $export = PackageExport::query()->where('package_id', $package->id)->sole();

    expect($result->success)->toBeFalse()
        ->and($export->status)->toBe(PackageExportStatus::PermanentlyFailed)
        ->and($export->last_error)->toBe('Export query names :shipper_notes, which the export does not supply.')
        ->and(erpOrder('SO-1')->tracking_no)->toBeNull();
});

it('refuses to save an export query naming a parameter the export cannot supply', function (): void {
    $validate = fn (string $query) => Validator::make(
        ['export_query' => $query],
        ['export_query' => [DatabaseSource::exportQueryParametersRule()]],
    );

    expect($validate('UPDATE orders SET postage_cost = :cost, width = :width WHERE order_no = :shipment_reference')->passes())->toBeTrue()
        ->and($validate("UPDATE orders SET note = 'at 12:30', shipped = created_at::date WHERE order_no = :shipment_reference")->passes())->toBeTrue()
        ->and($validate("UPDATE orders SET tracking_no = :tracking_number -- was :old_tracking\n /* :legacy */ WHERE order_no = :shipment_reference")->passes())->toBeTrue()
        ->and($validate('UPDATE orders SET note = :notes, x = :other WHERE order_no = :shipment_reference')->errors()->first('export_query'))
        ->toBe('Export Query names :notes, :other, which the export does not supply.');
});

it('writes tracking back through the SSH tunnel, one tunnel for a batch', function (): void {
    $source = erpConnection($this->erpPath, tunnelSettings());
    shippedErpPackage($source, 'SO-1');
    shippedErpPackage($source, 'SO-2');
    shippedErpPackage($source, 'SO-3');

    $results = app(PackageExportService::class)->exportUnexported();

    expect(collect($results)->every(fn ($result): bool => $result->success))->toBeTrue()
        ->and(erpOrder('SO-1')->tracking_no)->toBe('TRK-SO-1')
        ->and(erpOrder('SO-3')->tracking_no)->toBe('TRK-SO-3')
        ->and(FakeExportTunnel::$opened)->toBe(1)
        ->and(FakeExportTunnel::$closed)->toBe(1);
});

it('opens and closes the tunnel for a single export', function (): void {
    $source = erpConnection($this->erpPath, tunnelSettings());
    $package = shippedErpPackage($source, 'SO-1');

    $result = app(PackageExportService::class)->exportPackage($package);

    expect($result->success)->toBeTrue()
        ->and(FakeExportTunnel::$opened)->toBe(1)
        ->and(FakeExportTunnel::$closed)->toBe(1);
});

it('closes the tunnel when a batch aborts', function (): void {
    $source = erpConnection($this->erpPath, tunnelSettings());
    $package = shippedErpPackage($source, 'SO-1');
    $service = app(PackageExportService::class);

    expect(fn () => $service->batch(function () use ($service, $package): never {
        $service->exportPackage($package);

        throw new RuntimeException('Aborted');
    }))->toThrow(RuntimeException::class, 'Aborted');

    expect(FakeExportTunnel::$opened)->toBe(1)
        ->and(FakeExportTunnel::$closed)->toBe(1);
});

it('reports a tunnel that will not open as a retryable tunnel failure, tried once per batch', function (): void {
    FakeExportTunnel::$failure = 'SSH tunnel failed: Permission denied (publickey).';

    $source = erpConnection($this->erpPath, tunnelSettings());
    shippedErpPackage($source, 'SO-1');
    shippedErpPackage($source, 'SO-2');

    $results = app(PackageExportService::class)->exportUnexported();

    $exports = PackageExport::query()->get();

    expect($results)->toHaveCount(2)
        ->and($exports)->toHaveCount(2)
        ->and($exports->every(fn (PackageExport $export): bool => $export->status === PackageExportStatus::RetryableFailed
            && $export->last_error === 'SSH tunnel failed: Permission denied (publickey).'))->toBeTrue()
        ->and(FakeExportTunnel::$opened)->toBe(1);
});
