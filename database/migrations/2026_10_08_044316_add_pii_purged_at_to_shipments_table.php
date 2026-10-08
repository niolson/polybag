<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When `PurgePiiCommand` cleared a Shipment's recipient data —
     * `pii-retention/02`.
     *
     * The purge used to treat a null `first_name` as "already purged", so a
     * company-only Shipment, which never has one, was never purged at all.
     *
     * Backfilled for Shipments an earlier purge already cleared: no address
     * line and no city. Every import supplies a city, so an unpurged Shipment
     * never matches. Without the backfill they would be counted again, and
     * purged a second time, by the first run after deploy.
     */
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->timestamp('pii_purged_at')->nullable()->after('customs_rate_date');
        });

        DB::table('shipments')
            ->whereNull('address1')
            ->whereNull('city')
            ->update(['pii_purged_at' => DB::raw('COALESCE(updated_at, CURRENT_TIMESTAMP)')]);
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropColumn('pii_purged_at');
        });
    }
};
