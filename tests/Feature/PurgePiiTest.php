<?php

use App\Models\Package;
use App\Models\Shipment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Backdate a Shipment's last update, the clock for a void one.
 */
function lastUpdated(Shipment $shipment, Carbon $at): Shipment
{
    DB::table('shipments')->where('id', $shipment->id)->update(['updated_at' => $at]);

    return $shipment;
}

it('purges the customs form along with the label', function (): void {
    // A commercial invoice carries both parties' names, addresses, tax IDs and
    // EORI numbers. Leaving it behind when the label is purged would quietly
    // undo the purge.
    $shipment = Shipment::factory()->shipped()->create(['channel_id' => null]);

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
    $shipment = Shipment::factory()->shipped()->create(['channel_id' => null]);

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

it('purges a company-only shipment once, and marks it purged', function (): void {
    // With no first name, the purge used to take the Shipment for already
    // purged and keep its company, address and tax ID for good.
    $shipment = Shipment::factory()->shipped()->create([
        'channel_id' => null,
        'first_name' => null,
        'last_name' => null,
        'company' => 'Acme Importadora Ltda',
        'recipient_tax_id' => '12345678909',
    ]);

    $package = Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'shipped_at' => now()->subDays(120),
    ]);

    $this->artisan('shipments:purge-pii')
        ->expectsOutputToContain('1 shipment(s) purged')
        ->assertSuccessful();

    $shipment->refresh();

    expect($shipment->company)->toBeNull()
        ->and($shipment->address1)->toBeNull()
        ->and($shipment->recipient_tax_id)->toBeNull()
        ->and($shipment->pii_purged_at)->not->toBeNull()
        ->and($package->refresh()->label_data)->toBeNull();

    $this->artisan('shipments:purge-pii')
        ->expectsOutputToContain('0 shipment(s) purged')
        ->assertSuccessful();
});

it('purges a void shipment past retention from its last update', function (): void {
    $shipment = lastUpdated(
        Shipment::factory()->void()->create(['channel_id' => null, 'recipient_tax_id' => '12345678909']),
        now()->subDays(120),
    );

    $this->artisan('shipments:purge-pii')->assertSuccessful();

    $shipment->refresh();

    expect($shipment->first_name)->toBeNull()
        ->and($shipment->recipient_tax_id)->toBeNull()
        ->and($shipment->pii_purged_at)->not->toBeNull();
});

it('keeps a void shipment updated inside the retention period', function (): void {
    $shipment = lastUpdated(
        Shipment::factory()->void()->create(['channel_id' => null]),
        now()->subDays(5),
    );

    $this->artisan('shipments:purge-pii')->assertSuccessful();

    expect($shipment->refresh()->first_name)->not->toBeNull();
});

it('keeps a void shipment whose package shipped inside the retention period', function (): void {
    $shipment = lastUpdated(
        Shipment::factory()->void()->create(['channel_id' => null]),
        now()->subDays(120),
    );

    Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'shipped_at' => now()->subDays(5),
    ]);

    $this->artisan('shipments:purge-pii')->assertSuccessful();

    expect($shipment->refresh()->first_name)->not->toBeNull();
});

it('never purges an open shipment, however old', function (): void {
    // Still work: purging it would leave nothing to pack or ship to.
    $shipment = lastUpdated(
        Shipment::factory()->create(['channel_id' => null]),
        now()->subYears(2),
    );

    Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'shipped_at' => now()->subYears(2),
    ]);

    $this->artisan('shipments:purge-pii')->assertSuccessful();

    expect($shipment->refresh()->first_name)->not->toBeNull()
        ->and($shipment->pii_purged_at)->toBeNull();
});

it('purges a shipped shipment whose other package has no active label', function (): void {
    // A draft, or a package whose label was voided, has no label to wait for.
    $shipment = Shipment::factory()->shipped()->create(['channel_id' => null]);

    $shipped = Package::factory()->withCustomsForm()->create([
        'shipment_id' => $shipment->id,
        'shipped_at' => now()->subDays(120),
    ]);

    Package::factory()->create(['shipment_id' => $shipment->id]);

    $this->artisan('shipments:purge-pii')->assertSuccessful();

    expect($shipment->refresh()->first_name)->toBeNull()
        ->and($shipped->refresh()->label_data)->toBeNull()
        ->and($shipped->customs_form_data)->toBeNull();
});

it('times a shipped shipment by its latest shipped package', function (): void {
    $shipment = Shipment::factory()->shipped()->create(['channel_id' => null]);

    Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'shipped_at' => now()->subDays(120),
    ]);
    Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'shipped_at' => now()->subDays(5),
    ]);

    // Last updated long ago does not override a recent shipment.
    lastUpdated($shipment, now()->subDays(120));

    $this->artisan('shipments:purge-pii')->assertSuccessful();

    expect($shipment->refresh()->first_name)->not->toBeNull();
});

it('times a shipped shipment with no shipped package from its last update', function (): void {
    // A historical import arrives shipped, with no packages.
    $old = lastUpdated(Shipment::factory()->shipped()->create(['channel_id' => null]), now()->subDays(120));
    $recent = lastUpdated(Shipment::factory()->shipped()->create(['channel_id' => null]), now()->subDays(5));

    $this->artisan('shipments:purge-pii')->assertSuccessful();

    expect($old->refresh()->first_name)->toBeNull()
        ->and($recent->refresh()->first_name)->not->toBeNull();
});

it('counts every eligible shipment on a dry run without purging', function (): void {
    $shipped = Shipment::factory()->shipped()->create(['channel_id' => null, 'first_name' => null]);
    Package::factory()->shipped()->create([
        'shipment_id' => $shipped->id,
        'shipped_at' => now()->subDays(120),
    ]);
    $void = lastUpdated(Shipment::factory()->void()->create(['channel_id' => null]), now()->subDays(120));

    $this->artisan('shipments:purge-pii', ['--dry-run' => true])
        ->expectsOutputToContain('2 shipment(s) eligible (1 shipped, 1 void')
        ->expectsOutputToContain('2 shipment(s) would be purged')
        ->assertSuccessful();

    expect($shipped->refresh()->address1)->not->toBeNull()
        ->and($shipped->pii_purged_at)->toBeNull()
        ->and($void->refresh()->first_name)->not->toBeNull();
});

it('marks shipments an earlier purge cleared, so they are not purged again', function (): void {
    $purged = Shipment::factory()->shipped()->create();
    $kept = Shipment::factory()->shipped()->create();
    DB::table('shipments')->where('id', $purged->id)->update(['address1' => null, 'city' => null]);

    $migration = require database_path('migrations/2026_10_08_044316_add_pii_purged_at_to_shipments_table.php');
    $migration->down();
    $migration->up();

    expect($purged->refresh()->pii_purged_at)->not->toBeNull()
        ->and($kept->refresh()->pii_purged_at)->toBeNull();
});

it('restarts retention when a manager restores recipient data to a purged shipment', function (): void {
    $shipment = Shipment::factory()->shipped()->create(['channel_id' => null]);
    Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'shipped_at' => now()->subDays(120),
    ]);

    $this->artisan('shipments:purge-pii')->assertSuccessful();

    // An unrelated edit does not restart it.
    $shipment->refresh()->update(['shipment_reference' => 'RENAMED-1']);
    expect($shipment->refresh()->pii_purged_at)->not->toBeNull();

    $shipment->update(['address1' => '12 Restored St', 'city' => 'Austin']);
    expect($shipment->refresh()->pii_purged_at)->toBeNull();

    // Past retention still, so the next run purges it again.
    $this->artisan('shipments:purge-pii')->assertSuccessful();

    expect($shipment->refresh()->address1)->toBeNull()
        ->and($shipment->pii_purged_at)->not->toBeNull();
});
