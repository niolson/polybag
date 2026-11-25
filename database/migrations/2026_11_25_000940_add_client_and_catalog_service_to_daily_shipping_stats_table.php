<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * `reports-review/04`: the rollup keyed by client and by the catalog service
 * the active Label was bought as, so Volume can group by both. The existing
 * rows are rebuilt with the new keys, since no row can be split after the fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_shipping_stats', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('location_id')->constrained()->nullOnDelete();
            $table->foreignId('carrier_service_id')->nullable()->after('service')->constrained()->nullOnDelete();

            $table->dropUnique('daily_stats_composite_unique');
            $table->unique(['date', 'carrier', 'service', 'carrier_service_id', 'channel_id', 'shipping_method_id', 'location_id', 'client_id'], 'daily_stats_composite_unique');
        });

        Artisan::call('stats:aggregate', ['--from' => '2000-01-01']);
    }

    public function down(): void
    {
        Schema::table('daily_shipping_stats', function (Blueprint $table) {
            $table->dropUnique('daily_stats_composite_unique');
            $table->dropConstrainedForeignId('client_id');
            $table->dropConstrainedForeignId('carrier_service_id');
            $table->unique(['date', 'carrier', 'service', 'channel_id', 'shipping_method_id', 'location_id'], 'daily_stats_composite_unique');
        });
    }
};
