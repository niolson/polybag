<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Carry the interim Shopify cutoff off the constant in ShipDateService and onto
     * the carrier row, where every other carrier's cutoff already lives. The value
     * is unchanged, so no shipped install changes behavior; what changes is that
     * the policy is now data rather than a branch in the service.
     *
     * Matching by name or alias is right *here* and nowhere else: a migration acts
     * on the data as it stands the moment it runs. Rows that already carry a cutoff
     * are left alone rather than overwritten.
     */
    public function up(): void
    {
        $shopifyCarrierIds = DB::table('carriers')
            ->whereRaw('LOWER(TRIM(name)) = ?', ['shopify'])
            ->pluck('id')
            ->merge(DB::table('carrier_aliases')->where('lookup_key', 'shopify')->pluck('carrier_id'))
            ->unique();

        if ($shopifyCarrierIds->isEmpty()) {
            return;
        }

        DB::table('carriers')
            ->whereIn('id', $shopifyCarrierIds)
            ->whereNull('pickup_cutoff_hour')
            ->update(['pickup_cutoff_hour' => 20]); // 8 PM local time
    }

    /**
     * Deliberately irreversible in effect. Clearing the cutoff would re-date every
     * subsequent Shopify label, and the column itself is dropped by the migration
     * that added it.
     */
    public function down(): void {}
};
