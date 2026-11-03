<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What scanned contents are worth (`manual-ship-contents/01`): a Product's unit
 * customs value, in its Client's currency, and the unit value a packed line
 * declares, in its Shipment's currency, which wins over its Shipment item's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->char('currency', 3)->default('USD')->after('name');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->decimal('customs_value', 8, 2)->nullable()->after('weight');
        });

        Schema::table('package_items', function (Blueprint $table): void {
            $table->decimal('value', 8, 2)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('package_items', function (Blueprint $table): void {
            $table->dropColumn('value');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('customs_value');
        });

        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('currency');
        });
    }
};
