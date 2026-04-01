<?php

use App\Contracts\ExportDestinationInterface;
use App\Contracts\ImportSourceInterface;
use App\Models\Channel;
use App\Models\Package;
use App\Models\Shipment;
use App\Services\ShipmentImport\PackageExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

function fakeExportSource(bool $exportEnabled = true, ?string $exportError = null): string
{
    // Return an anonymous class that implements both interfaces
    $class = new class([]) implements ExportDestinationInterface, ImportSourceInterface
    {
        public static bool $staticExportEnabled = true;

        public static ?string $staticExportError = null;

        /** @var array<int, array<string, mixed>> */
        public static array $exportedData = [];

        public function __construct(array $config = []) // @phpstan-ignore constructor.unusedParameter
        {}

        public function getSourceName(): string
        {
            return 'test';
        }

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
                throw new RuntimeException(self::$staticExportError);
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
    $className::$exportedData = [];

    return $className;
}

function createShippedPackage(?Channel $channel = null): Package
{
    $shipment = Shipment::factory()->create([
        'channel_id' => $channel?->id,
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

it('exports package data using configured field mapping', function (): void {
    $driverClass = fakeExportSource();
    $channel = Channel::factory()->create(['name' => 'TestChannel']);
    $package = createShippedPackage($channel);

    config([
        'shipment-import.sources.test_source' => [
            'driver' => $driverClass,
            'export' => [
                'enabled' => true,
                'query' => 'UPDATE orders SET tracking = :tracking WHERE id = :ref',
                'field_mapping' => [
                    'tracking_number' => 'tracking',
                    'shipment_reference' => 'ref',
                    'weight' => 'weight',
                ],
            ],
        ],
        'shipment-import.export_channel_map' => [
            'TestChannel' => ['test_source'],
        ],
    ]);

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

it('uses wildcard channel mapping as fallback', function (): void {
    $driverClass = fakeExportSource();
    $channel = Channel::factory()->create(['name' => 'UnmappedChannel']);
    $package = createShippedPackage($channel);

    config([
        'shipment-import.sources.test_source' => [
            'driver' => $driverClass,
            'export' => [
                'enabled' => true,
                'query' => 'UPDATE orders SET tracking = :tracking WHERE id = :ref',
                'field_mapping' => [
                    'tracking_number' => 'tracking',
                    'shipment_reference' => 'ref',
                ],
            ],
        ],
        'shipment-import.export_channel_map' => [
            '*' => ['test_source'],
        ],
    ]);

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    expect($result->success)->toBeTrue();
    expect($result->destinationsAttempted)->toBe(1);
    expect($driverClass::$exportedData)->toHaveCount(1);
});

it('skips export when no channel mapping matches', function (): void {
    $channel = Channel::factory()->create(['name' => 'UnmappedChannel']);
    $package = createShippedPackage($channel);

    config([
        'shipment-import.export_channel_map' => [
            'SomeOtherChannel' => ['test_source'],
        ],
    ]);

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    expect($result->success)->toBeTrue();
    expect($result->destinationsAttempted)->toBe(0);
    expect($result->destinationsSucceeded)->toBe(0);
});

it('marks package as exported on success', function (): void {
    $driverClass = fakeExportSource();
    $channel = Channel::factory()->create(['name' => 'TestChannel']);
    $package = createShippedPackage($channel);

    config([
        'shipment-import.sources.test_source' => [
            'driver' => $driverClass,
            'export' => [
                'enabled' => true,
                'query' => 'UPDATE orders SET tracking = :tracking WHERE id = :ref',
                'field_mapping' => [
                    'tracking_number' => 'tracking',
                    'shipment_reference' => 'ref',
                ],
            ],
        ],
        'shipment-import.export_channel_map' => [
            'TestChannel' => ['test_source'],
        ],
    ]);

    $service = new PackageExportService;
    $service->exportPackage($package);

    expect($package->fresh()->exported)->toBeTrue();
});

it('does not mark package as exported when a destination fails', function (): void {
    $driverClass = fakeExportSource(exportError: 'Connection refused');
    $channel = Channel::factory()->create(['name' => 'TestChannel']);
    $package = createShippedPackage($channel);

    config([
        'shipment-import.sources.test_source' => [
            'driver' => $driverClass,
            'export' => [
                'enabled' => true,
                'query' => 'UPDATE orders SET tracking = :tracking WHERE id = :ref',
                'field_mapping' => [
                    'tracking_number' => 'tracking',
                    'shipment_reference' => 'ref',
                ],
            ],
        ],
        'shipment-import.export_channel_map' => [
            'TestChannel' => ['test_source'],
        ],
    ]);

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    expect($result->success)->toBeFalse();
    expect($result->hasErrors())->toBeTrue();
    expect($result->errors[0])->toContain('Connection refused');
    expect($package->fresh()->exported)->toBeFalse();
});

it('skips disabled export sources', function (): void {
    $driverClass = fakeExportSource();
    $channel = Channel::factory()->create(['name' => 'TestChannel']);
    $package = createShippedPackage($channel);

    config([
        'shipment-import.sources.test_source' => [
            'driver' => $driverClass,
            'export' => [
                'enabled' => false,
                'query' => 'UPDATE orders SET tracking = :tracking WHERE id = :ref',
                'field_mapping' => [
                    'tracking_number' => 'tracking',
                    'shipment_reference' => 'ref',
                ],
            ],
        ],
        'shipment-import.export_channel_map' => [
            'TestChannel' => ['test_source'],
        ],
    ]);

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    expect($result->success)->toBeTrue();
    expect($result->destinationsAttempted)->toBe(0);
});

it('exports unexported packages via exportUnexported', function (): void {
    $driverClass = fakeExportSource();
    $channel = Channel::factory()->create(['name' => 'TestChannel']);

    // Create shipped but not exported packages
    $shipment = Shipment::factory()->create(['channel_id' => $channel->id, 'shipment_reference' => 'REF-A']);
    $pkg1 = Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'exported' => false,
        'tracking_number' => 'TRACK-A',
    ]);

    $shipment2 = Shipment::factory()->create(['channel_id' => $channel->id, 'shipment_reference' => 'REF-B']);
    $pkg2 = Package::factory()->shipped()->create([
        'shipment_id' => $shipment2->id,
        'exported' => false,
        'tracking_number' => 'TRACK-B',
    ]);

    // Create already exported package (should be skipped)
    $shipment3 = Shipment::factory()->create(['channel_id' => $channel->id, 'shipment_reference' => 'REF-C']);
    Package::factory()->exported()->create([
        'shipment_id' => $shipment3->id,
        'tracking_number' => 'TRACK-C',
    ]);

    config([
        'shipment-import.sources.test_source' => [
            'driver' => $driverClass,
            'export' => [
                'enabled' => true,
                'query' => 'UPDATE orders SET tracking = :tracking WHERE id = :ref',
                'field_mapping' => [
                    'tracking_number' => 'tracking',
                    'shipment_reference' => 'ref',
                ],
            ],
        ],
        'shipment-import.export_channel_map' => [
            'TestChannel' => ['test_source'],
        ],
    ]);

    $service = new PackageExportService;
    $results = $service->exportUnexported();

    expect($results)->toHaveCount(2);
    expect($pkg1->fresh()->exported)->toBeTrue();
    expect($pkg2->fresh()->exported)->toBeTrue();
});

it('runs export command with dry-run option', function (): void {
    $channel = Channel::factory()->create(['name' => 'TestChannel']);
    $shipment = Shipment::factory()->create(['channel_id' => $channel->id, 'shipment_reference' => 'REF-CMD']);
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

it('runs export command with validate-only when no channel map configured', function (): void {
    config(['shipment-import.export_channel_map' => []]);

    $this->artisan('packages:export', ['--validate-only' => true])
        ->expectsOutputToContain('No export channel mappings configured')
        ->assertExitCode(0);
});

it('handles multiple destinations per channel', function (): void {
    $driverClass = fakeExportSource();
    $channel = Channel::factory()->create(['name' => 'MultiChannel']);
    $package = createShippedPackage($channel);

    config([
        'shipment-import.sources.dest_a' => [
            'driver' => $driverClass,
            'export' => [
                'enabled' => true,
                'query' => 'UPDATE a SET tracking = :tracking WHERE id = :ref',
                'field_mapping' => [
                    'tracking_number' => 'tracking',
                    'shipment_reference' => 'ref',
                ],
            ],
        ],
        'shipment-import.sources.dest_b' => [
            'driver' => $driverClass,
            'export' => [
                'enabled' => true,
                'query' => 'UPDATE b SET tracking = :tracking WHERE id = :ref',
                'field_mapping' => [
                    'tracking_number' => 'tracking',
                    'shipment_reference' => 'ref',
                ],
            ],
        ],
        'shipment-import.export_channel_map' => [
            'MultiChannel' => ['dest_a', 'dest_b'],
        ],
    ]);

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    expect($result->success)->toBeTrue();
    expect($result->destinationsAttempted)->toBe(2);
    expect($result->destinationsSucceeded)->toBe(2);
    // Both destinations used the same class, so static data has both exports
    expect($driverClass::$exportedData)->toHaveCount(2);
});
