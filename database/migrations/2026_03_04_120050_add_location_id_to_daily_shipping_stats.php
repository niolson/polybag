<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop the old unique index before adding the new column
        Schema::table('daily_shipping_stats', function (Blueprint $table) {
            $table->dropUnique('daily_stats_composite_unique');
        });

        Schema::table('daily_shipping_stats', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('shipping_method_id')->constrained()->nullOnDelete();

            $table->unique(
                ['date', 'carrier', 'service', 'channel_id', 'shipping_method_id', 'location_id'],
                'daily_stats_composite_unique'
            );
        });

        // Backfill existing rows with the default location
        $defaultLocationId = DB::table('locations')->where('is_default', true)->value('id');

        if ($defaultLocationId) {
            DB::table('daily_shipping_stats')->whereNull('location_id')->update(['location_id' => $defaultLocationId]);
        }
    }

    public function down(): void
    {
        Schema::table('daily_shipping_stats', function (Blueprint $table) {
            $table->dropUnique('daily_stats_composite_unique');
            $table->dropConstrainedForeignId('location_id');

            $table->unique(
                ['date', 'carrier', 'service', 'channel_id', 'shipping_method_id'],
                'daily_stats_composite_unique'
            );
        });
    }
};
