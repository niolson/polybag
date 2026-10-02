<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_shipping_stats', function (Blueprint $table): void {
            // How many of `package_count` actually carried a cost. Shopify
            // Shipping reports no price, so `packages.cost` is null for its
            // labels and they contribute nothing to `total_cost` — the rollup
            // cannot tell afterwards how many of its packages were priced, and
            // every consumer reads the rollup rather than `packages`.
            //
            // Nullable rather than `default(0)`: zero would claim every package
            // in the row was unpriced, which is the same species of lie as
            // storing 0.00 on an unpriced package. Null means "never computed
            // for this row", and readers fall back to `package_count`, which is
            // the behavior that predates this column.
            $table->unsignedInteger('costed_package_count')
                ->nullable()
                ->after('package_count');
        });
    }

    public function down(): void
    {
        Schema::table('daily_shipping_stats', function (Blueprint $table): void {
            $table->dropColumn('costed_package_count');
        });
    }
};
