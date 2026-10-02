<?php

use App\Enums\PackageStatus;
use App\Events\PackageShipped;
use App\Listeners\ExportShippedPackage;
use App\Models\Package;
use App\Models\Shipment;
use App\Services\ShipmentImport\ExportResult;
use App\Services\ShipmentImport\PackageExportService;
use Illuminate\Contracts\Queue\ShouldQueue;

it('implements ShouldQueue', function (): void {
    $listener = new ExportShippedPackage;

    expect($listener)->toBeInstanceOf(ShouldQueue::class)
        ->and($listener->afterCommit)->toBeTrue()
        ->and($listener->tries)->toBe(3)
        ->and($listener->backoff)->toBe([300, 600, 1200]);
});

it('calls exportPackage on the PackageExportService', function (): void {
    $shipment = Shipment::factory()->create();
    $package = Package::factory()->shipped()->create(['shipment_id' => $shipment->id]);

    $mock = Mockery::mock(PackageExportService::class);
    $mock->shouldReceive('exportPackage')
        ->once()
        ->with(Mockery::on(fn ($p): bool => $p->id === $package->id))
        ->andReturn(new ExportResult(success: true));

    app()->instance(PackageExportService::class, $mock);

    $event = new PackageShipped($package, $shipment);
    $listener = new ExportShippedPackage;
    $listener->handle($event);
});

it('throws when export fails so the queued listener retries', function (): void {
    $shipment = Shipment::factory()->create();
    $package = Package::factory()->shipped()->create(['shipment_id' => $shipment->id]);

    $mock = Mockery::mock(PackageExportService::class);
    $mock->shouldReceive('exportPackage')
        ->once()
        ->andReturn(new ExportResult(
            success: false,
            errors: ['Amazon: throttled'],
            retryableErrors: ['Amazon: throttled'],
        ));

    app()->instance(PackageExportService::class, $mock);

    expect(fn () => (new ExportShippedPackage)->handle(new PackageShipped($package, $shipment)))
        ->toThrow(RuntimeException::class, 'Amazon: throttled');
});

it('does not retry permanent export failures', function (): void {
    $shipment = Shipment::factory()->create();
    $package = Package::factory()->shipped()->create(['shipment_id' => $shipment->id]);

    $mock = Mockery::mock(PackageExportService::class);
    $mock->shouldReceive('exportPackage')
        ->once()
        ->andReturn(new ExportResult(success: false, errors: ['Amazon: missing order ID']));

    app()->instance(PackageExportService::class, $mock);

    (new ExportShippedPackage)->handle(new PackageShipped($package, $shipment));

    expect(true)->toBeTrue();
});

it('does not export a package whose label was voided before the job ran', function (): void {
    $shipment = Shipment::factory()->create();
    $package = Package::factory()->create(['shipment_id' => $shipment->id, 'status' => PackageStatus::Unshipped]);

    $mock = Mockery::mock(PackageExportService::class);
    $mock->shouldNotReceive('exportPackage');
    app()->instance(PackageExportService::class, $mock);

    (new ExportShippedPackage)->handle(new PackageShipped($package, $shipment));
});
