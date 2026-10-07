<?php

use App\Enums\Deliverability;
use App\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function runDeliverabilityBackfill(): void
{
    $migration = require database_path('migrations/2026_10_06_233437_split_deliverability_values_on_shipments.php');
    $migration->up();
}

/**
 * Writes a raw value, since `maybe` is no longer an enum case.
 */
function shipmentWithRawDeliverability(string $deliverability, string $country): int
{
    $shipment = Shipment::factory()->create(['country' => $country]);

    DB::table('shipments')->where('id', $shipment->id)->update(['deliverability' => $deliverability]);

    return $shipment->id;
}

it('moves each row to the value its evidence supports', function (string $before, string $country, Deliverability $after): void {
    $id = shipmentWithRawDeliverability($before, $country);

    runDeliverabilityBackfill();

    expect(Shipment::find($id)->deliverability)->toBe($after);
})->with([
    'maybe becomes partial' => ['maybe', 'US', Deliverability::Partial],
    'international maybe becomes partial' => ['maybe', 'DE', Deliverability::Partial],
    'international yes becomes verified' => ['yes', 'DE', Deliverability::Verified],
    'Canadian yes becomes verified' => ['yes', 'CA', Deliverability::Verified],
    'US yes stays' => ['yes', 'US', Deliverability::Yes],
    // Google returns USPS delivery-point data for the territories, so a yes
    // there may be a real DPV confirmation.
    'Puerto Rico yes stays' => ['yes', 'PR', Deliverability::Yes],
    'Guam yes stays' => ['yes', 'GU', Deliverability::Yes],
    'Palau yes stays' => ['yes', 'PW', Deliverability::Yes],
    'international no is left alone' => ['no', 'DE', Deliverability::No],
    'not checked is left alone' => ['not_checked', 'DE', Deliverability::NotChecked],
]);
