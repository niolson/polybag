<?php

use App\Models\Package;
use App\Models\Shipment;
use Illuminate\Support\Facades\DB;

it('purges the customs form along with the label', function (): void {
    // A commercial invoice carries both parties' names, addresses, tax IDs and
    // EORI numbers. Leaving it behind when the label is purged would quietly
    // undo the purge.
    $shipment = Shipment::factory()->create(['channel_id' => null]);

    $package = Package::factory()->withCustomsForm()->create([
        'shipment_id' => $shipment->id,
        'shipped_at' => now()->subDays(120),
    ]);

    $this->artisan('shipments:purge-pii')->assertSuccessful();

    $package->refresh();

    expect($package->label_data)->toBeNull()
        ->and($package->customs_form_data)->toBeNull()
        ->and($shipment->refresh()->first_name)->toBeNull();
});

it('leaves a customs form inside the retention period alone', function (): void {
    $shipment = Shipment::factory()->create(['channel_id' => null]);

    $package = Package::factory()->withCustomsForm()->create([
        'shipment_id' => $shipment->id,
        'shipped_at' => now()->subDays(5),
    ]);

    $this->artisan('shipments:purge-pii')->assertSuccessful();

    expect($package->refresh()->customs_form_data)->not->toBeNull();
});

it('rolls the city constraint back over shipments the purge has already nulled', function (): void {
    // Rolling back is the one direction that meets rows the purge forgot, and a
    // NOT NULL column has nowhere to put a null — without the backfill, the
    // rollback fails instead of reversing.
    $shipment = Shipment::factory()->create();
    DB::table('shipments')->where('id', $shipment->id)->update(['city' => null]);

    $migration = require database_path('migrations/2026_09_10_000100_make_shipment_city_nullable.php');

    $migration->down();

    expect(DB::table('shipments')->where('id', $shipment->id)->value('city'))->toBe('');

    // Leave the schema as the rest of the suite expects to find it.
    $migration->up();
});
