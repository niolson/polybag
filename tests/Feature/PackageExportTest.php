<?php

use App\Contracts\DataSourceInterface;
use App\Contracts\ExportDestinationInterface;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\PackageExportStatus;
use App\Enums\PostageSource;
use App\Enums\ServiceEvidence;
use App\Enums\VoidReason;
use App\Exceptions\PermanentExportException;
use App\Models\Carrier;
use App\Models\CarrierAlias;
use App\Models\Channel;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Package;
use App\Models\PackageExport;
use App\Models\Shipment;
use App\Services\SettingsService;
use App\Services\ShipmentImport\PackageExportService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function fakeExportSource(bool $exportEnabled = true, ?string $exportError = null, int $exportCode = 0): string
{
    $class = new class([]) implements DataSourceInterface, ExportDestinationInterface
    {
        public static bool $staticExportEnabled = true;

        public static ?string $staticExportError = null;

        public static int $staticExportCode = 0;

        /** @var array<int, array<string, mixed>> */
        public static array $exportedData = [];

        public function __construct(array $config = []) // @phpstan-ignore constructor.unusedParameter
        {}

        public function fetchShipments(): Collection
        {
            return collect();
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

        public function getDestinationName(): string
        {
            return 'test';
        }

        public function exportPackage(array $data): void
        {
            if (self::$staticExportError) {
                throw new RuntimeException(self::$staticExportError, self::$staticExportCode);
            }
            self::$exportedData[] = $data;
        }

        public function validateExportConfiguration(): void
        {
            if (! self::$staticExportEnabled) {
                throw new InvalidArgumentException('Export is not enabled.');
            }
        }
    };

    $className = get_class($class);
    $className::$staticExportEnabled = $exportEnabled;
    $className::$staticExportError = $exportError;
    $className::$staticExportCode = $exportCode;
    $className::$exportedData = [];

    return $className;
}

/**
 * @param  array<string, string>  $fieldMapping
 */
function fakeDataSource(string $driverClass, array $fieldMapping = [], bool $exportEnabled = true): DataSource
{
    return DataSource::factory()->create([
        'source_type' => $driverClass,
        'settings' => [
            'export_enabled' => $exportEnabled,
            'export_field_mapping' => $fieldMapping,
        ],
    ]);
}

function createShippedPackage(?Channel $channel = null, ?DataSource $importSource = null): Package
{
    $shipment = Shipment::factory()->create([
        'channel_id' => $channel?->id,
        'data_source_id' => $importSource?->id,
        'shipment_reference' => 'REF-001',
    ]);

    return Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'tracking_number' => '1234567890',
        'weight' => 5.50,
        'height' => 10.00,
        'width' => 8.00,
        'length' => 12.00,
        'cost' => 7.99,
        'carrier' => 'USPS',
        'service' => 'Priority Mail',
    ]);
}

it('has indexes aligned with export sweep and source-line lookups', function (): void {
    $packageIndexes = collect(Schema::getIndexes('packages'))->pluck('columns');
    $packageExportIndexes = collect(Schema::getIndexes('package_exports'))->pluck('columns');
    $shipmentItemIndexes = collect(Schema::getIndexes('shipment_items'));

    expect($packageIndexes)->toContain(['status', 'exported', 'id'])
        ->and($packageExportIndexes)->toContain(['package_id', 'status', 'locked_at'])
        ->and($shipmentItemIndexes->contains(fn (array $index): bool => $index['unique']
            && $index['columns'] === ['shipment_id', 'source_item_id']))->toBeTrue();
});

it('exports package data using configured field mapping', function (): void {
    $driverClass = fakeExportSource();
    $channel = Channel::factory()->create(['name' => 'TestChannel']);
    $importSource = fakeDataSource($driverClass, [
        'tracking_number' => 'tracking',
        'shipment_reference' => 'ref',
        'weight' => 'weight',
    ]);
    $package = createShippedPackage($channel, $importSource);

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    expect($result->success)->toBeTrue();
    expect($result->destinationsAttempted)->toBe(1);
    expect($result->destinationsSucceeded)->toBe(1);
    expect($driverClass::$exportedData)->toHaveCount(1);
    expect($driverClass::$exportedData[0])->toBe([
        'tracking' => '1234567890',
        'ref' => 'REF-001',
        'weight' => '5.50',
    ]);
});

it('exports the normalized carrier of record rather than the source spelling', function (): void {
    $usps = Carrier::factory()->usps()->create();
    CarrierAlias::factory()->for($usps)->create(['alias' => 'US Postal Service']);
    $driverClass = fakeExportSource();
    $importSource = fakeDataSource($driverClass, ['carrier' => 'carrier']);
    $package = createShippedPackage(importSource: $importSource);
    $package->update(['carrier' => 'US Postal Service', 'normalized_carrier_id' => $usps->id]);

    expect((new PackageExportService)->exportPackage($package)->success)->toBeTrue()
        ->and($driverClass::$exportedData[0])->toBe(['carrier' => 'USPS']);
});

it('exports the raw carrier name when it normalized to nothing', function (): void {
    $driverClass = fakeExportSource();
    $importSource = fakeDataSource($driverClass, ['carrier' => 'carrier']);
    $package = createShippedPackage(importSource: $importSource);
    $package->update(['carrier' => 'Poste Italiane', 'normalized_carrier_id' => null]);

    expect((new PackageExportService)->exportPackage($package)->success)->toBeTrue()
        ->and($driverClass::$exportedData[0])->toBe(['carrier' => 'Poste Italiane']);
});

it('exports a service the postage source confirmed', function (): void {
    $driverClass = fakeExportSource();
    $importSource = fakeDataSource($driverClass, ['service' => 'service']);
    $package = createShippedPackage(importSource: $importSource);
    $package->update(['service' => 'Priority Mail', 'service_evidence' => ServiceEvidence::Confirmed]);

    expect((new PackageExportService)->exportPackage($package)->success)->toBeTrue()
        ->and($driverClass::$exportedData[0])->toBe(['service' => 'Priority Mail']);
});

it('withholds a service nobody confirmed rather than publishing a guess', function (array $attributes): void {
    $driverClass = fakeExportSource();
    $importSource = fakeDataSource($driverClass, ['service' => 'service', 'tracking_number' => 'tracking']);
    $package = createShippedPackage(importSource: $importSource);
    $package->update($attributes);

    // The export still goes out — omitting a field costs nothing, and the rest
    // of the confirmation is fact. ADR-0003 decision 7.
    expect((new PackageExportService)->exportPackage($package)->success)->toBeTrue()
        ->and($driverClass::$exportedData[0])->toBe(['service' => null, 'tracking' => '1234567890']);
})->with([
    'inferred by us, not reported by the source' => [[
        'service' => 'Priority Mail',
        'service_evidence' => ServiceEvidence::Inferred,
        'service_inference_method' => 'tracking_number_prefix',
        'service_ruleset_version' => '2026.09.1',
    ]],
    'unknown, with only a preference on record' => [[
        'service' => null,
        'requested_service' => 'Priority Mail',
        'service_evidence' => ServiceEvidence::Unknown,
    ]],
]);

it('skips export when shipment has no import source', function (): void {
    $channel = Channel::factory()->create(['name' => 'UnlinkedChannel']);
    $package = createShippedPackage($channel);

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    expect($result->success)->toBeFalse();
    expect($result->deferred)->toBeTrue();
    expect($result->destinationsAttempted)->toBe(0);
    expect($result->destinationsSucceeded)->toBe(0);
    expect($package->fresh()->exported)->toBeFalse();
    expect($service->exportUnexported())->toBeEmpty();
});

it('skips export when import source has export_enabled false', function (): void {
    $driverClass = fakeExportSource();
    $channel = Channel::factory()->create(['name' => 'TestChannel']);
    $importSource = fakeDataSource($driverClass, exportEnabled: false);
    $package = createShippedPackage($channel, $importSource);

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    expect($result->success)->toBeFalse();
    expect($result->deferred)->toBeTrue();
    expect($result->destinationsAttempted)->toBe(0);
    expect($package->fresh()->exported)->toBeFalse();

    $importSource->update(['settings' => ['export_enabled' => true]]);

    expect($service->exportUnexported())->toHaveKey($package->id)
        ->and($package->fresh()->exported)->toBeTrue();
});

it('registers a scheduled export command with a parseable boolean flag', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains($event->command, 'packages:export'));

    expect($event)->not->toBeNull()
        ->and($event->command)->toContain('packages:export --scheduled')
        ->and($event->command)->not->toContain("--scheduled='1'");

    $this->artisan('packages:export --scheduled')->assertSuccessful();
});

it('keeps scheduled export sweeps successful for retryable failures', function (): void {
    $source = fakeDataSource(fakeExportSource(exportError: 'Connection refused'));
    createShippedPackage(importSource: $source);

    $this->artisan('packages:export', ['--scheduled' => true])
        ->expectsOutputToContain('Connection refused')
        ->assertSuccessful();
});

it('does not schedule historical packages when export is enabled later', function (): void {
    $driverClass = fakeExportSource();
    $source = fakeDataSource($driverClass, exportEnabled: false);
    $package = createShippedPackage(importSource: $source);
    $package->update(['shipped_at' => now()->subDays(2)]);
    $source->update(['settings' => ['export_enabled' => true]]);

    $this->artisan('packages:export', ['--scheduled' => true])
        ->expectsOutput('No unexported packages found.')
        ->assertSuccessful();

    expect($driverClass::$exportedData)->toBeEmpty()
        ->and($package->fresh()->exported)->toBeFalse();
});

it('continues scheduled retries for historical packages with an export ledger', function (): void {
    $driverClass = fakeExportSource();
    $source = fakeDataSource($driverClass);
    $package = createShippedPackage(importSource: $source);
    $package->update(['shipped_at' => now()->subDays(2)]);
    PackageExport::query()->create([
        'package_id' => $package->id,
        'data_source_id' => $source->id,
        'status' => PackageExportStatus::RetryableFailed,
        'attempts' => 1,
    ]);
    $this->travel(5)->minutes();

    $this->artisan('packages:export', ['--scheduled' => true])->assertSuccessful();

    expect($driverClass::$exportedData)->toHaveCount(1)
        ->and($package->fresh()->exported)->toBeTrue();
});

it('marks package as exported on success', function (): void {
    $driverClass = fakeExportSource();
    $channel = Channel::factory()->create(['name' => 'TestChannel']);
    $importSource = fakeDataSource($driverClass, ['tracking_number' => 'tracking', 'shipment_reference' => 'ref']);
    $package = createShippedPackage($channel, $importSource);

    $service = new PackageExportService;
    $service->exportPackage($package);

    expect($package->fresh()->exported)->toBeTrue();
});

it('invalidates export state when a package is voided and exports new tracking after reship', function (): void {
    Event::fake();
    $driverClass = fakeExportSource();
    $source = fakeDataSource($driverClass, ['tracking_number' => 'tracking_number']);
    $package = createShippedPackage(importSource: $source);
    $service = new PackageExportService;

    $service->exportPackage($package);
    $package->clearShipping(VoidReason::Operator);

    expect($package->exported)->toBeFalse()
        ->and($package->packageExports()->count())->toBe(0);

    $package->markShipped(ShipResponse::success(
        trackingNumber: 'NEW-TRACKING',
        cost: 9.99,
        carrier: 'USPS',
        service: 'Priority Mail',
    ), PostageSource::CarrierAccount);
    $result = $service->exportPackage($package);

    expect($result->success)->toBeTrue()
        ->and($driverClass::$exportedData)->toHaveCount(2)
        ->and($driverClass::$exportedData[1]['tracking_number'])->toBe('NEW-TRACKING');
});

it('does not mark package as exported when a destination fails', function (): void {
    $driverClass = fakeExportSource(exportError: 'Connection refused');
    $channel = Channel::factory()->create(['name' => 'TestChannel']);
    $importSource = fakeDataSource($driverClass, ['tracking_number' => 'tracking', 'shipment_reference' => 'ref']);
    $package = createShippedPackage($channel, $importSource);

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    expect($result->success)->toBeFalse();
    expect($result->hasErrors())->toBeTrue();
    expect($result->errors[0])->toContain('Connection refused');
    expect($package->fresh()->exported)->toBeFalse();
});

it('skips export when driver does not implement ExportDestinationInterface', function (): void {
    $importOnlyClass = new class([]) implements DataSourceInterface
    {
        public function __construct(array $config = []) {} // @phpstan-ignore constructor.unusedParameter

        public function fetchShipments(): Collection
        {
            return collect();
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

    $driverClass = get_class($importOnlyClass);
    $channel = Channel::factory()->create(['name' => 'TestChannel']);
    $importSource = DataSource::factory()->create([
        'source_type' => $driverClass,
        'settings' => ['export_enabled' => true],
    ]);
    $package = createShippedPackage($channel, $importSource);

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    expect($result->success)->toBeFalse();
    expect($result->destinationsAttempted)->toBe(1);
    expect($package->fresh()->exported)->toBeFalse();
});

it('exports unexported packages via exportUnexported', function (): void {
    $driverClass = fakeExportSource();
    $channel = Channel::factory()->create(['name' => 'TestChannel']);
    $importSource = fakeDataSource($driverClass, ['tracking_number' => 'tracking', 'shipment_reference' => 'ref']);

    $shipment = Shipment::factory()->create([
        'channel_id' => $channel->id,
        'data_source_id' => $importSource->id,
        'shipment_reference' => 'REF-A',
    ]);
    $pkg1 = Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'exported' => false,
        'tracking_number' => 'TRACK-A',
    ]);

    $shipment2 = Shipment::factory()->create([
        'channel_id' => $channel->id,
        'data_source_id' => $importSource->id,
        'shipment_reference' => 'REF-B',
    ]);
    $pkg2 = Package::factory()->shipped()->create([
        'shipment_id' => $shipment2->id,
        'exported' => false,
        'tracking_number' => 'TRACK-B',
    ]);

    // Already exported — should be skipped
    $shipment3 = Shipment::factory()->create([
        'channel_id' => $channel->id,
        'data_source_id' => $importSource->id,
        'shipment_reference' => 'REF-C',
    ]);
    Package::factory()->exported()->create([
        'shipment_id' => $shipment3->id,
        'tracking_number' => 'TRACK-C',
    ]);

    $service = new PackageExportService;
    $results = $service->exportUnexported();

    expect($results)->toHaveCount(2);
    expect($pkg1->fresh()->exported)->toBeTrue();
    expect($pkg2->fresh()->exported)->toBeTrue();
});

it('limits each unexported package sweep', function (): void {
    $driverClass = fakeExportSource();
    $source = fakeDataSource($driverClass);
    $shipment = Shipment::factory()->create(['data_source_id' => $source]);
    $packages = Package::factory()->count(105)->shipped()->create([
        'shipment_id' => $shipment,
        'exported' => false,
    ]);

    $results = (new PackageExportService)->exportUnexported();

    expect($results)->toHaveCount(100)
        ->and($packages->filter(fn (Package $package): bool => $package->fresh()->exported)->count())->toBe(100);
});

it('runs export command with dry-run option', function (): void {
    $source = fakeDataSource(fakeExportSource());
    $channel = Channel::factory()->create(['name' => 'TestChannel']);
    $shipment = Shipment::factory()->create([
        'channel_id' => $channel->id,
        'data_source_id' => $source,
        'shipment_reference' => 'REF-CMD',
    ]);
    Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'exported' => false,
        'tracking_number' => 'TRACK-CMD',
        'carrier' => 'USPS',
    ]);

    $this->artisan('packages:export', ['--dry-run' => true])
        ->expectsOutputToContain('1 packages to export')
        ->expectsOutputToContain('TRACK-CMD')
        ->assertExitCode(0);
});

it('limits export dry-run output to the requested package', function (): void {
    $source = fakeDataSource(fakeExportSource());
    $first = createShippedPackage(importSource: $source);
    $first->update(['tracking_number' => 'DRY-RUN-FIRST']);
    $second = createShippedPackage(importSource: $source);
    $second->update(['tracking_number' => 'DRY-RUN-SECOND']);

    $this->artisan('packages:export', [
        '--dry-run' => true,
        '--package' => $second->id,
    ])
        ->expectsOutputToContain('1 packages to export')
        ->expectsOutputToContain('DRY-RUN-SECOND')
        ->doesntExpectOutputToContain('DRY-RUN-FIRST')
        ->assertExitCode(0);
});

it('uses the real sweep eligibility and limit for dry-run', function (): void {
    $source = fakeDataSource(fakeExportSource());
    $shipment = Shipment::factory()->create(['data_source_id' => $source]);
    Package::factory()->count(105)->shipped()->create([
        'shipment_id' => $shipment,
        'exported' => false,
    ]);
    $ineligible = createShippedPackage();
    $ineligible->update(['tracking_number' => 'NOT-ELIGIBLE']);

    $this->artisan('packages:export', ['--dry-run' => true])
        ->expectsOutputToContain('Found 100 packages to export')
        ->doesntExpectOutputToContain('NOT-ELIGIBLE')
        ->assertSuccessful();
});

it('previews permanent failure recovery without mutating it during dry-run', function (): void {
    $source = fakeDataSource(fakeExportSource());
    $package = createShippedPackage(importSource: $source);
    $export = PackageExport::query()->create([
        'package_id' => $package->id,
        'data_source_id' => $source->id,
        'status' => PackageExportStatus::PermanentlyFailed,
        'attempts' => 3,
        'last_error' => 'Fixable configuration',
    ]);

    $this->artisan('packages:export', [
        '--dry-run' => true,
        '--retry-permanent' => true,
    ])
        ->expectsOutputToContain('Would reopen 1 permanent export failure(s)')
        ->expectsOutputToContain('Found 1 packages to export')
        ->assertSuccessful();

    expect($export->refresh()->status)->toBe(PackageExportStatus::PermanentlyFailed)
        ->and($export->attempts)->toBe(3);
});

it('rejects a non-numeric export package option', function (): void {
    $this->artisan('packages:export', ['--package' => 'not-a-package'])
        ->expectsOutputToContain('Package must be a positive integer')
        ->assertExitCode(1);
});

it('runs export command with validate-only when no export-enabled sources', function (): void {
    $this->artisan('packages:export', ['--validate-only' => true])
        ->expectsOutputToContain('No export-enabled data sources configured')
        ->assertExitCode(0);
});

it('exports to global export source even when package has no import source', function (): void {
    app(SettingsService::class)->set('multi_client_enabled', true, 'boolean');
    $driverClass = fakeExportSource();

    $globalSource = DataSource::factory()->create([
        'source_type' => $driverClass,
        'global_export' => true,
        'settings' => [
            'export_enabled' => true,
            'export_field_mapping' => ['tracking_number' => 'tracking'],
        ],
    ]);

    $package = createShippedPackage(); // no import source, no client

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    expect($result->success)->toBeTrue();
    expect($result->destinationsAttempted)->toBe(1);
    expect($result->destinationsSucceeded)->toBe(1);
    expect($driverClass::$exportedData)->toHaveCount(1);
    expect($driverClass::$exportedData[0])->toHaveKey('tracking');
});

it('exports to both primary source and global export source', function (): void {
    app(SettingsService::class)->set('multi_client_enabled', true, 'boolean');
    $driverClass = fakeExportSource();

    $primarySource = fakeDataSource($driverClass, ['tracking_number' => 'primary_tracking']);
    $globalSource = DataSource::factory()->create([
        'source_type' => $driverClass,
        'global_export' => true,
        'settings' => [
            'export_enabled' => true,
            'export_field_mapping' => ['tracking_number' => 'global_tracking'],
        ],
    ]);

    $package = createShippedPackage(importSource: $primarySource);

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    expect($result->success)->toBeTrue();
    expect($result->destinationsAttempted)->toBe(2);
    expect($result->destinationsSucceeded)->toBe(2);
    expect($driverClass::$exportedData)->toHaveCount(2);

    $keys = array_column($driverClass::$exportedData, null);
    $allKeys = array_merge(...array_map('array_keys', $driverClass::$exportedData));
    expect($allKeys)->toContain('primary_tracking')->toContain('global_tracking');
});

it('does not resend to a successful destination when another destination is retried', function (): void {
    app(SettingsService::class)->set('multi_client_enabled', true, 'boolean');
    $successfulDriver = fakeExportSource();
    $failingDriver = new class([]) implements DataSourceInterface, ExportDestinationInterface
    {
        public static int $attempts = 0;

        public function __construct(array $config = []) {} // @phpstan-ignore constructor.unusedParameter

        public function fetchShipments(): Collection
        {
            return collect();
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

        public function getDestinationName(): string
        {
            return 'failing-test';
        }

        public function exportPackage(array $data): void
        {
            self::$attempts++;

            throw new RuntimeException('Connection timed out');
        }

        public function validateExportConfiguration(): void {}
    };
    $failingDriverClass = $failingDriver::class;
    $primary = fakeDataSource($successfulDriver, ['tracking_number' => 'tracking']);
    DataSource::factory()->create([
        'source_type' => $failingDriverClass,
        'global_export' => true,
        'settings' => ['export_enabled' => true],
    ]);
    $package = createShippedPackage(importSource: $primary);
    $service = new PackageExportService;

    $first = $service->exportPackage($package);
    $this->travel(5)->minutes();
    $second = $service->exportPackage($package->fresh());

    expect($first->shouldRetry())->toBeTrue()
        ->and($second->shouldRetry())->toBeTrue()
        ->and($successfulDriver::$exportedData)->toHaveCount(1)
        ->and($failingDriverClass::$attempts)->toBe(2)
        ->and($package->fresh()->exported)->toBeFalse();
});

it('does not run a destination while another worker holds its export claim', function (): void {
    $driverClass = fakeExportSource();
    $source = fakeDataSource($driverClass, ['tracking_number' => 'tracking']);
    $package = createShippedPackage(importSource: $source);
    PackageExport::query()->create([
        'package_id' => $package->id,
        'data_source_id' => $source->id,
        'status' => PackageExportStatus::Processing,
        'attempts' => 1,
        'locked_at' => now(),
    ]);

    $result = (new PackageExportService)->exportPackage($package);

    expect($result->destinationsAttempted)->toBe(0)
        ->and($result->success)->toBeFalse()
        ->and($result->deferred)->toBeTrue()
        ->and($driverClass::$exportedData)->toBeEmpty()
        ->and($package->fresh()->exported)->toBeFalse();
});

it('continues the package sweep when acquiring one destination claim fails', function (): void {
    $driverClass = fakeExportSource();
    $source = fakeDataSource($driverClass);
    $shipment = Shipment::factory()->create(['data_source_id' => $source]);
    $packages = Package::factory()->count(2)->shipped()->create([
        'shipment_id' => $shipment,
        'exported' => false,
    ]);
    $throwOnClaim = true;

    DB::connection()->beforeExecuting(function (string $query) use (&$throwOnClaim): void {
        if ($throwOnClaim && str_contains(strtolower($query), 'insert') && str_contains($query, 'package_exports')) {
            $throwOnClaim = false;

            throw new RuntimeException('Simulated claim lock timeout');
        }
    });

    $results = (new PackageExportService)->exportUnexported();

    expect($results)->toHaveCount(2)
        ->and(collect($results)->filter->success)->toHaveCount(1)
        ->and(collect($results)->filter->shouldRetry())->toHaveCount(1)
        ->and($packages->filter(fn (Package $package): bool => $package->fresh()->exported)->count())->toBe(1);
});

it('keeps retrying a recent destination failure after ten attempts', function (): void {
    $driverClass = fakeExportSource(exportError: 'Connection refused');
    $source = fakeDataSource($driverClass);
    $package = createShippedPackage(importSource: $source);
    $export = PackageExport::query()->create([
        'package_id' => $package->id,
        'data_source_id' => $source->id,
        'status' => PackageExportStatus::RetryableFailed,
        'attempts' => 9,
    ]);
    $this->travel(6)->hours();

    $result = (new PackageExportService)->exportPackage($package);

    expect($result->shouldRetry())->toBeTrue()
        ->and($export->refresh()->attempts)->toBe(10)
        ->and($export->status)->toBe(PackageExportStatus::RetryableFailed);
});

it('stops retrying a destination after the backoff retry budget is exhausted', function (): void {
    $driverClass = fakeExportSource(exportError: 'Connection refused');
    $source = fakeDataSource($driverClass);
    $package = createShippedPackage(importSource: $source);
    $export = PackageExport::query()->create([
        'package_id' => $package->id,
        'data_source_id' => $source->id,
        'status' => PackageExportStatus::RetryableFailed,
        'attempts' => 31,
    ]);
    $this->travel(6)->hours();

    $result = (new PackageExportService)->exportPackage($package);

    expect($result->shouldRetry())->toBeFalse()
        ->and($export->refresh()->status)->toBe(PackageExportStatus::PermanentlyFailed);
});

it('backs scheduled retries off after a recent failure', function (): void {
    $source = fakeDataSource(fakeExportSource());
    $package = createShippedPackage(importSource: $source);
    PackageExport::query()->create([
        'package_id' => $package->id,
        'data_source_id' => $source->id,
        'status' => PackageExportStatus::RetryableFailed,
        'attempts' => 1,
    ]);
    $service = new PackageExportService;

    expect($service->exportUnexported())->toBeEmpty();

    $this->travel(5)->minutes();

    expect($service->exportUnexported())->toHaveKey($package->id);
});

it('does not bypass destination backoff when a sibling destination is already settled', function (): void {
    app(SettingsService::class)->set('multi_client_enabled', true, 'boolean');
    $failingDriver = fakeExportSource(exportError: 'Connection refused');
    $primary = fakeDataSource($failingDriver);
    $global = DataSource::factory()->create([
        'source_type' => $failingDriver,
        'global_export' => true,
        'settings' => ['export_enabled' => true],
    ]);
    $package = createShippedPackage(importSource: $primary);
    PackageExport::query()->create([
        'package_id' => $package->id,
        'data_source_id' => $primary->id,
        'status' => PackageExportStatus::RetryableFailed,
        'attempts' => 1,
    ]);
    PackageExport::query()->create([
        'package_id' => $package->id,
        'data_source_id' => $global->id,
        'status' => PackageExportStatus::Succeeded,
        'attempts' => 1,
        'completed_at' => now(),
    ]);
    $service = new PackageExportService;

    $service->exportPackage($package);

    $export = PackageExport::query()
        ->where('package_id', $package->id)
        ->where('data_source_id', $primary->id)
        ->firstOrFail();

    expect($export->attempts)->toBe(1)
        ->and($export->status)->toBe(PackageExportStatus::RetryableFailed);
});

it('does not retry a permanent client error response', function (): void {
    $driver = new class([]) implements DataSourceInterface, ExportDestinationInterface
    {
        public function __construct(array $config = []) {} // @phpstan-ignore constructor.unusedParameter

        public function fetchShipments(): Collection
        {
            return collect();
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

        public function getDestinationName(): string
        {
            return 'permanent-test';
        }

        public function exportPackage(array $data): void
        {
            throw new PermanentExportException('Order is already shipped');
        }

        public function validateExportConfiguration(): void {}
    };
    $driverClass = $driver::class;
    $source = fakeDataSource($driverClass);
    $package = createShippedPackage(importSource: $source);

    $result = (new PackageExportService)->exportPackage($package);
    $export = PackageExport::query()->where('package_id', $package->id)->firstOrFail();

    expect($result->shouldRetry())->toBeFalse()
        ->and($export->status)->toBe(PackageExportStatus::PermanentlyFailed);
});

it('retries recoverable invalid configuration errors', function (): void {
    $driver = new class([]) implements DataSourceInterface, ExportDestinationInterface
    {
        public function __construct(array $config = []) {} // @phpstan-ignore constructor.unusedParameter

        public function fetchShipments(): Collection
        {
            return collect();
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

        public function getDestinationName(): string
        {
            return 'configuration-test';
        }

        public function exportPackage(array $data): void
        {
            throw new InvalidArgumentException('OAuth is not configured yet.');
        }

        public function validateExportConfiguration(): void {}
    };
    $source = fakeDataSource($driver::class);
    $package = createShippedPackage(importSource: $source);

    $result = (new PackageExportService)->exportPackage($package);
    $export = PackageExport::query()->where('package_id', $package->id)->firstOrFail();

    expect($result->shouldRetry())->toBeTrue()
        ->and($export->status)->toBe(PackageExportStatus::RetryableFailed);
});

it('retries database exceptions with non-numeric SQLSTATE codes', function (): void {
    $driver = new class([]) implements DataSourceInterface, ExportDestinationInterface
    {
        public function __construct(array $config = []) {} // @phpstan-ignore constructor.unusedParameter

        public function fetchShipments(): Collection
        {
            return collect();
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

        public function getDestinationName(): string
        {
            return 'database-deadlock-test';
        }

        public function exportPackage(array $data): void
        {
            $previous = new class('deadlock detected') extends PDOException
            {
                public function __construct(string $message)
                {
                    parent::__construct($message);
                    $this->code = '40P01';
                }
            };

            throw new QueryException('pgsql', 'update shipments set exported = true', [], $previous);
        }

        public function validateExportConfiguration(): void {}
    };
    $source = fakeDataSource($driver::class);
    $package = createShippedPackage(importSource: $source);

    $result = (new PackageExportService)->exportPackage($package);
    $export = PackageExport::query()->where('package_id', $package->id)->firstOrFail();

    expect($result->shouldRetry())->toBeTrue()
        ->and($export->status)->toBe(PackageExportStatus::RetryableFailed);
});

it('prioritizes newer packages ahead of an old retry backlog', function (): void {
    $driverClass = fakeExportSource();
    $source = fakeDataSource($driverClass);
    $shipment = Shipment::factory()->create(['data_source_id' => $source]);
    $oldPackages = Package::factory()->count(100)->shipped()->create([
        'shipment_id' => $shipment,
        'exported' => false,
    ]);
    $oldPackages->each(function (Package $package) use ($source): void {
        PackageExport::query()->create([
            'package_id' => $package->id,
            'data_source_id' => $source->id,
            'status' => PackageExportStatus::RetryableFailed,
            'attempts' => 1,
        ]);
    });
    $this->travel(5)->minutes();
    $newPackage = Package::factory()->shipped()->create([
        'shipment_id' => $shipment,
        'exported' => false,
    ]);

    $results = (new PackageExportService)->exportUnexported();

    expect($results)->toHaveCount(100)->toHaveKey($newPackage->id);
});

it('reserves sweep capacity for an older retry among new packages', function (): void {
    $driverClass = fakeExportSource();
    $source = fakeDataSource($driverClass);
    $shipment = Shipment::factory()->create(['data_source_id' => $source]);
    $oldPackage = Package::factory()->shipped()->create([
        'shipment_id' => $shipment,
        'exported' => false,
    ]);
    PackageExport::query()->create([
        'package_id' => $oldPackage->id,
        'data_source_id' => $source->id,
        'status' => PackageExportStatus::RetryableFailed,
        'attempts' => 1,
    ]);
    $this->travel(5)->minutes();
    Package::factory()->count(100)->shipped()->create([
        'shipment_id' => $shipment,
        'exported' => false,
    ]);

    $results = (new PackageExportService)->exportUnexported();

    expect($results)->toHaveCount(100)->toHaveKey($oldPackage->id);
});

it('exports a newly added global destination despite an existing permanent failure', function (): void {
    app(SettingsService::class)->set('multi_client_enabled', true, 'boolean');
    $primary = fakeDataSource(fakeExportSource());
    $package = createShippedPackage(importSource: $primary);
    $package->update(['shipped_at' => now()->subDays(2)]);
    PackageExport::query()->create([
        'package_id' => $package->id,
        'data_source_id' => $primary->id,
        'status' => PackageExportStatus::PermanentlyFailed,
        'attempts' => 1,
        'last_error' => 'Old permanent failure',
    ]);
    $global = DataSource::factory()->create([
        'source_type' => fakeExportSource(),
        'global_export' => true,
        'settings' => ['export_enabled' => true],
    ]);

    $results = (new PackageExportService)->exportUnexported(scheduled: true);

    expect($results)->toHaveKey($package->id)
        ->and(PackageExport::query()
            ->where('package_id', $package->id)
            ->where('data_source_id', $global->id)
            ->value('status'))->toBe(PackageExportStatus::Succeeded);
});

it('handles a package deleted while its destination is exporting', function (): void {
    $driver = new class([]) implements DataSourceInterface, ExportDestinationInterface
    {
        public static ?int $packageId = null;

        public function __construct(array $config = []) {} // @phpstan-ignore constructor.unusedParameter

        public function fetchShipments(): Collection
        {
            return collect();
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

        public function getDestinationName(): string
        {
            return 'deleting-test';
        }

        public function exportPackage(array $data): void
        {
            Package::query()->whereKey(self::$packageId)->delete();
        }

        public function validateExportConfiguration(): void {}
    };
    $source = fakeDataSource($driver::class);
    $package = createShippedPackage(importSource: $source);
    $driver::$packageId = $package->id;

    $result = (new PackageExportService)->exportPackage($package);

    expect($result->success)->toBeFalse()
        ->and($result->deferred)->toBeTrue();
});

it('can explicitly retry a permanent export failure', function (): void {
    $driverClass = fakeExportSource();
    $source = fakeDataSource($driverClass);
    $package = createShippedPackage(importSource: $source);
    $export = PackageExport::query()->create([
        'package_id' => $package->id,
        'data_source_id' => $source->id,
        'status' => PackageExportStatus::PermanentlyFailed,
        'attempts' => 1,
        'last_error' => 'Missing configuration',
        'completed_at' => now(),
    ]);

    $skipped = (new PackageExportService)->exportPackage($package);

    expect($skipped->success)->toBeFalse()
        ->and($skipped->deferred)->toBeFalse()
        ->and($skipped->destinationsAttempted)->toBe(0)
        ->and($skipped->errors[0])->toContain('Missing configuration');

    $this->artisan('packages:export', [
        '--retry-permanent' => true,
        '--package' => $package->id,
    ])->assertExitCode(0);

    expect($export->refresh()->status)->toBe(PackageExportStatus::Succeeded)
        ->and($package->fresh()->exported)->toBeTrue();
});

it('does not reopen permanent failures for already exported packages', function (): void {
    $source = fakeDataSource(fakeExportSource());
    $package = createShippedPackage(importSource: $source);
    $package->update(['exported' => true]);
    $export = PackageExport::query()->create([
        'package_id' => $package->id,
        'data_source_id' => $source->id,
        'status' => PackageExportStatus::PermanentlyFailed,
        'attempts' => 2,
        'last_error' => 'Original permanent error',
    ]);

    $reset = (new PackageExportService)->retryPermanentFailures();

    expect($reset)->toBe(0)
        ->and($export->refresh()->status)->toBe(PackageExportStatus::PermanentlyFailed)
        ->and($export->last_error)->toBe('Original permanent error');
});

it('does not double-export when global source is also the primary source', function (): void {
    app(SettingsService::class)->set('multi_client_enabled', true, 'boolean');
    $driverClass = fakeExportSource();

    $source = DataSource::factory()->create([
        'source_type' => $driverClass,
        'global_export' => true,
        'settings' => [
            'export_enabled' => true,
            'export_field_mapping' => ['tracking_number' => 'tracking'],
        ],
    ]);

    $package = createShippedPackage(importSource: $source);

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    expect($result->destinationsAttempted)->toBe(1); // not 2
    expect($driverClass::$exportedData)->toHaveCount(1);
});

it('does not fan out to global export sources in single-client mode', function (): void {
    $driverClass = fakeExportSource();

    DataSource::factory()->create([
        'source_type' => $driverClass,
        'global_export' => true,
        'settings' => [
            'export_enabled' => true,
            'export_field_mapping' => ['tracking_number' => 'tracking'],
        ],
    ]);

    $package = createShippedPackage(); // no import source

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    expect($result->success)->toBeFalse();
    expect($result->deferred)->toBeTrue();
    expect($result->destinationsAttempted)->toBe(0);
    expect($driverClass::$exportedData)->toHaveCount(0);
});

it('still exports to the primary source in single-client mode', function (): void {
    $driverClass = fakeExportSource();

    $primarySource = fakeDataSource($driverClass, ['tracking_number' => 'tracking']);

    $package = createShippedPackage(importSource: $primarySource);

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    expect($result->success)->toBeTrue();
    expect($result->destinationsAttempted)->toBe(1);
    expect($driverClass::$exportedData)->toHaveCount(1);
});

it('skips inactive global export sources', function (): void {
    app(SettingsService::class)->set('multi_client_enabled', true, 'boolean');
    $driverClass = fakeExportSource();

    DataSource::factory()->create([
        'source_type' => $driverClass,
        'global_export' => true,
        'active' => false,
        'settings' => ['export_enabled' => true],
    ]);

    $package = createShippedPackage();

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    expect($result->success)->toBeFalse();
    expect($result->deferred)->toBeTrue();
    expect($result->destinationsAttempted)->toBe(0);
    expect($driverClass::$exportedData)->toHaveCount(0);
});

it('skips global export sources where export_enabled is false', function (): void {
    app(SettingsService::class)->set('multi_client_enabled', true, 'boolean');
    $driverClass = fakeExportSource();

    DataSource::factory()->create([
        'source_type' => $driverClass,
        'global_export' => true,
        'active' => true,
        'settings' => ['export_enabled' => false],
    ]);

    $package = createShippedPackage();

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    expect($result->success)->toBeFalse();
    expect($result->deferred)->toBeTrue();
    expect($result->destinationsAttempted)->toBe(0);
    expect($driverClass::$exportedData)->toHaveCount(0);
});
