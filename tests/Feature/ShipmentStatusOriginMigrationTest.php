<?php

use App\Models\DataSource;
use App\Models\Package;
use App\Models\Shipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->migration = require database_path('migrations/2026_10_12_160000_add_status_origin_and_review_flag_to_shipments_table.php');
    $this->dropReviewFlag = require database_path('migrations/2026_10_13_035607_drop_review_flag_from_shipments_table.php');

    // Newest first: the later drop of the review flag, then this migration.
    $this->dropReviewFlag->down();
    $this->migration->down();
});

afterEach(function (): void {
    if (! Schema::hasColumn('shipments', 'status_origin')) {
        $this->migration->up();
    }

    if (Schema::hasColumn('shipments', 'review_reason')) {
        $this->dropReviewFlag->up();
    }
});

/**
 * A Shipment as it stood before `order-reconciliation/02`, with no origin columns.
 */
function legacyShipment(string $status, ?int $dataSourceId = null, bool $shippedPackage = false): int
{
    $shipment = Shipment::withoutEvents(fn (): Shipment => Shipment::factory()->create(['data_source_id' => $dataSourceId]));
    DB::table('shipments')->where('id', $shipment->id)->update(['status' => $status]);

    if ($shippedPackage) {
        $package = Package::withoutEvents(fn (): Package => Package::factory()->for($shipment)->create());
        DB::table('packages')->where('id', $package->id)->update(['status' => 'shipped']);
    }

    return $shipment->id;
}

it('backfills who set each status', function (): void {
    $connection = DataSource::factory()->create();

    $void = legacyShipment('void');
    $shippedHere = legacyShipment('shipped', $connection->id, shippedPackage: true);
    $shippedBySource = legacyShipment('shipped', $connection->id);
    $markedByHand = legacyShipment('shipped');
    $open = legacyShipment('open', $connection->id);

    $this->migration->up();

    $origins = DB::table('shipments')->pluck('status_origin', 'id');
    $statuses = DB::table('shipments')->pluck('status', 'id');

    expect($statuses[$void])->toBe('cancelled')
        ->and($origins[$void])->toBe('manager')
        ->and($origins[$shippedHere])->toBe('polybag')
        ->and($origins[$shippedBySource])->toBe('source')
        ->and($origins[$markedByHand])->toBe('manager')
        ->and($origins[$open])->toBeNull();
});
