<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `reports-review/04`: the rollup keyed by client and by the catalog service
 * the active Label was bought as, so Volume can group by both.
 *
 * No existing row can be split after the fact, so the rows are rebuilt here.
 * The rebuild is this migration's own SQL, frozen as it was written, not a
 * call to `stats:aggregate`: the command will change with the schema, and a
 * migration that ran whatever it became could break a fresh install. SQLite
 * only ever holds a fresh test database, with nothing to rebuild.
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

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::transaction(function (): void {
            DB::table('daily_shipping_stats')->delete();

            DB::statement('
                INSERT INTO daily_shipping_stats
                    (date, carrier, service, carrier_service_id, channel_id, shipping_method_id, location_id, client_id, package_count, costed_package_count, total_cost, total_weight, created_at, updated_at)
                SELECT
                    p.ship_date, p.carrier, p.service, active_label.carrier_service_id,
                    s.channel_id, s.shipping_method_id, p.location_id, s.client_id,
                    COUNT(*), COUNT(p.cost), COALESCE(SUM(p.cost), 0), COALESCE(SUM(p.weight), 0),
                    NOW(), NOW()
                FROM packages p
                JOIN shipments s ON p.shipment_id = s.id
                LEFT JOIN package_labels active_label ON active_label.active_package_id = p.id
                WHERE p.status = "shipped" AND p.ship_date IS NOT NULL
                GROUP BY p.ship_date, p.carrier, p.service, active_label.carrier_service_id, s.channel_id, s.shipping_method_id, p.location_id, s.client_id
            ');
        });
    }

    /**
     * The rows keyed by client or service would collide on the narrower key,
     * and MySQL's DDL is not transactional, so a failed re-add would leave
     * the table with no unique key at all. The rollup is emptied first;
     * `php artisan stats:aggregate --from=2000-01-01` rebuilds it after the
     * code it matches is back.
     */
    public function down(): void
    {
        DB::table('daily_shipping_stats')->delete();

        Schema::table('daily_shipping_stats', function (Blueprint $table) {
            $table->dropUnique('daily_stats_composite_unique');
            $table->dropConstrainedForeignId('client_id');
            $table->dropConstrainedForeignId('carrier_service_id');
            $table->unique(['date', 'carrier', 'service', 'channel_id', 'shipping_method_id', 'location_id'], 'daily_stats_composite_unique');
        });
    }
};
