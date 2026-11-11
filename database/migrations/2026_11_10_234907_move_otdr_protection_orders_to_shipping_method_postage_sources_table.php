<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The OTDR requirement moves from the shipping method to its Amazon Buy
 * Shipping row (`amazon-buy-shipping/17`). Only a Buy Shipping offer can be
 * protected, so the requirement means nothing on a method that does not let
 * Amazon sell, and there it held every ticked Amazon order for a person.
 * A method with no Amazon row loses the setting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_method_postage_sources', function (Blueprint $table) {
            $table->json('otdr_protection_orders')->nullable()->after('unlisted_services');
        });

        DB::table('shipping_methods')
            ->whereNotNull('otdr_protection_orders')
            ->get(['id', 'otdr_protection_orders'])
            ->each(function (object $method): void {
                DB::table('shipping_method_postage_sources')
                    ->where('shipping_method_id', $method->id)
                    ->where('source_kind', 'amazon')
                    ->update(['otdr_protection_orders' => $method->otdr_protection_orders]);
            });

        Schema::table('shipping_methods', function (Blueprint $table) {
            $table->dropColumn('otdr_protection_orders');
        });
    }

    public function down(): void
    {
        Schema::table('shipping_methods', function (Blueprint $table) {
            $table->json('otdr_protection_orders')->nullable()->after('excludes_late_rates');
        });

        DB::table('shipping_method_postage_sources')
            ->where('source_kind', 'amazon')
            ->whereNotNull('otdr_protection_orders')
            ->get(['shipping_method_id', 'otdr_protection_orders'])
            ->each(function (object $row): void {
                DB::table('shipping_methods')
                    ->where('id', $row->shipping_method_id)
                    ->update(['otdr_protection_orders' => $row->otdr_protection_orders]);
            });

        Schema::table('shipping_method_postage_sources', function (Blueprint $table) {
            $table->dropColumn('otdr_protection_orders');
        });
    }
};
