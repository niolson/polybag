<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // packages: stored generated date column from shipped_at
        Schema::table('packages', function (Blueprint $table) {
            $table->date('shipped_date')
                ->nullable()
                ->storedAs('DATE(shipped_at)')
                ->after('shipped_at');
        });

        // Covering index for daily cost queries (CostPerPackageTrend, ShippingCostAnalysis, StatsOverview)
        // Covers: GROUP BY shipped_date with AVG/SUM/COUNT on cost, filtered by shipped
        Schema::table('packages', function (Blueprint $table) {
            $table->index(['shipped', 'shipped_date', 'cost'], 'packages_shipped_date_cost_index');
        });

        // Covering index for carrier breakdown queries
        Schema::table('packages', function (Blueprint $table) {
            $table->index(['shipped', 'shipped_date', 'carrier'], 'packages_shipped_date_carrier_index');
        });

        // Covering index for service breakdown queries
        Schema::table('packages', function (Blueprint $table) {
            $table->index(['shipped', 'shipped_date', 'service'], 'packages_shipped_date_service_index');
        });

        // shipments: stored generated date column from updated_at
        Schema::table('shipments', function (Blueprint $table) {
            $table->date('updated_date')
                ->nullable()
                ->storedAs('DATE(updated_at)')
                ->after('updated_at');
        });

        // Covering index for ShippedShipmentsChart
        Schema::table('shipments', function (Blueprint $table) {
            $table->index(['shipped', 'updated_date'], 'shipments_shipped_updated_date_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropIndex('packages_shipped_date_cost_index');
            $table->dropIndex('packages_shipped_date_carrier_index');
            $table->dropIndex('packages_shipped_date_service_index');
            $table->dropColumn('shipped_date');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex('shipments_shipped_updated_date_index');
            $table->dropColumn('updated_date');
        });
    }
};
