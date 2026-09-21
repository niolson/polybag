<?php

use App\Models\Shipment;
use Illuminate\Support\Facades\DB;

it('backfills unknown classification before restoring the historical non-null constraint', function (): void {
    $shipment = Shipment::factory()->create(['residential' => null]);
    $migration = require database_path('migrations/2026_09_20_213332_make_shipments_residential_nullable.php');

    $migration->down();

    try {
        expect(DB::table('shipments')->where('id', $shipment->id)->value('residential'))
            ->toBe(1);
    } finally {
        $migration->up();
    }
});
