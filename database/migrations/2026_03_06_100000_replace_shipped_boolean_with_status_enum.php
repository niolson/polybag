<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // --- Packages ---

        Schema::table('packages', function (Blueprint $table) {
            $table->string('status', 16)->default('unshipped')->after('weight_mismatch');
        });

        DB::table('packages')->where('shipped', true)->update(['status' => 'shipped']);

        Schema::table('packages', function (Blueprint $table) {
            // Drop old composite indexes that start with 'shipped'
            $table->dropIndex('packages_shipped_shipped_at_index');
            $table->dropIndex('packages_shipped_date_cost_index');
            $table->dropIndex('packages_shipped_date_carrier_index');
            $table->dropIndex('packages_shipped_date_service_index');
            $table->dropIndex('packages_weight_mismatch_index');

            $table->dropColumn('shipped');

            // New composite indexes using status
            $table->index(['status', 'shipped_at'], 'packages_status_shipped_at_index');
            $table->index(['status', 'shipped_date', 'cost'], 'packages_status_date_cost_index');
            $table->index(['status', 'shipped_date', 'carrier'], 'packages_status_date_carrier_index');
            $table->index(['status', 'shipped_date', 'service'], 'packages_status_date_service_index');
            $table->index(['status', 'weight_mismatch', 'shipped_at'], 'packages_status_weight_mismatch_index');
        });

        // --- Shipments ---

        Schema::table('shipments', function (Blueprint $table) {
            $table->string('status', 16)->default('open')->after('metadata');
        });

        DB::table('shipments')->where('shipped', true)->update(['status' => 'shipped']);

        Schema::table('shipments', function (Blueprint $table) {
            // Drop indexes that reference shipped
            $table->dropIndex('shipments_shipped_updated_date_index');
            $table->dropIndex(['shipped']);

            $table->dropColumn('shipped');

            // New indexes
            $table->index('status', 'shipments_status_index');
            $table->index(['status', 'updated_date'], 'shipments_status_updated_date_index');
        });
    }

    public function down(): void
    {
        // --- Shipments ---

        Schema::table('shipments', function (Blueprint $table) {
            $table->boolean('shipped')->default(false)->after('metadata');
        });

        DB::table('shipments')->where('status', 'shipped')->update(['shipped' => true]);

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex('shipments_status_index');
            $table->dropIndex('shipments_status_updated_date_index');

            $table->dropColumn('status');

            $table->index('shipped');
            $table->index(['shipped', 'updated_date'], 'shipments_shipped_updated_date_index');
        });

        // --- Packages ---

        Schema::table('packages', function (Blueprint $table) {
            $table->boolean('shipped')->default(false)->after('weight_mismatch');
        });

        DB::table('packages')->where('status', 'shipped')->update(['shipped' => true]);

        Schema::table('packages', function (Blueprint $table) {
            $table->dropIndex('packages_status_shipped_at_index');
            $table->dropIndex('packages_status_date_cost_index');
            $table->dropIndex('packages_status_date_carrier_index');
            $table->dropIndex('packages_status_date_service_index');
            $table->dropIndex('packages_status_weight_mismatch_index');

            $table->dropColumn('status');

            $table->index(['shipped', 'shipped_at'], 'packages_shipped_shipped_at_index');
            $table->index(['shipped', 'shipped_date', 'cost'], 'packages_shipped_date_cost_index');
            $table->index(['shipped', 'shipped_date', 'carrier'], 'packages_shipped_date_carrier_index');
            $table->index(['shipped', 'shipped_date', 'service'], 'packages_shipped_date_service_index');
            $table->index(['shipped', 'weight_mismatch', 'shipped_at'], 'packages_weight_mismatch_index');
        });
    }
};
