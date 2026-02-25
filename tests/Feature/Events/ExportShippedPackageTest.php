<?php

use App\Events\PackageShipped;
use App\Listeners\ExportShippedPackage;
use App\Models\Package;
use App\Models\Shipment;
use App\Services\ShipmentImport\PackageExportService;
use Illuminate\Contracts\Queue\ShouldQueue;

it('implements ShouldQueue', function (): void {
    $listener = new ExportShippedPackage;

    expect($listener)->toBeInstanceOf(ShouldQueue::class)
        ->and($listener->afterCommit)->toBeTrue();
});

it('calls exportPackage on the PackageExportService', function (): void {
    $shipment = Shipment::factory()->create();
    $package = Package::factory()->shipped()->create(['shipment_id' => $shipment->id]);

    $mock = Mockery::mock(PackageExportService::class);
    $mock->shouldReceive('exportPackage')
        ->once()
        ->with(Mockery::on(fn ($p) => $p->id === $package->id));

    app()->instance(PackageExportService::class, $mock);

    $event = new PackageShipped($package, $shipment);
    $listener = new ExportShippedPackage;
    $listener->handle($event);
});
