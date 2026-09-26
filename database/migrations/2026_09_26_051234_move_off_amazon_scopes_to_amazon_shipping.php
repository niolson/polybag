<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Move the scopes that choose which Amazon connection sells Amazon
     * Shipping to other channels from the `Amazon` row to the Amazon Shipping
     * carrier — `carrier-catalog-reset/15`. The sale is direct and the
     * connection is its account, so its scope sits on the carrier it sells.
     *
     * Moved, not left to the `Amazon` row's cascade when
     * `carrier-catalog-reset/12` deletes it. Amazon Shipping is created here
     * when the reference-data sync has not yet seeded it, so an install that
     * skips a release still keeps its scopes; the sync adopts the row by name.
     * No `CarrierAccount` can sit on Amazon Shipping, so no scope already
     * there can collide with a moved one.
     *
     * Shipping methods and rules are not touched: whoever wants Amazon
     * Shipping for other channels lists Amazon Shipping Ground on a method.
     */
    public function up(): void
    {
        $amazonId = DB::table('carriers')->where('name', 'Amazon')->value('id');

        if ($amazonId === null
            || DB::table('carrier_account_scopes')->where('carrier_id', $amazonId)->whereNotNull('data_source_id')->doesntExist()) {
            return;
        }

        $amazonShippingId = DB::table('carriers')->where('name', 'Amazon Shipping')->value('id')
            ?? DB::table('carriers')->insertGetId([
                'name' => 'Amazon Shipping',
                'active' => true,
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('carrier_account_scopes')
            ->where('carrier_id', $amazonId)
            ->whereNotNull('data_source_id')
            ->update(['carrier_id' => $amazonShippingId]);
    }

    public function down(): void
    {
        $amazonId = DB::table('carriers')->where('name', 'Amazon')->value('id');
        $amazonShippingId = DB::table('carriers')->where('name', 'Amazon Shipping')->value('id');

        if ($amazonId === null || $amazonShippingId === null) {
            return;
        }

        DB::table('carrier_account_scopes')
            ->where('carrier_id', $amazonShippingId)
            ->whereNotNull('data_source_id')
            ->update(['carrier_id' => $amazonId]);
    }
};
