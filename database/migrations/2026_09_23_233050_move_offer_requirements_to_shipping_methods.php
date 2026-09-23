<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Move what automation insists on before buying a Label from the Amazon
     * connection to the shipping method (`amazon-buy-shipping/17`), so a
     * Seller Fulfilled Prime method can insist on protection that an Amazon
     * Standard method does not.
     *
     * Every method takes the defaults: late rates excluded, no OTDR protection
     * required. A connection that required protection cannot be mapped onto
     * methods, because a method serves several connections, so each one is
     * logged for the seller to set on the methods it meant.
     */
    public function up(): void
    {
        Schema::table('shipping_methods', function (Blueprint $table) {
            $table->boolean('excludes_late_rates')->default(true)->after('is_expedited');
            $table->json('otdr_protection_orders')->nullable()->after('excludes_late_rates');
        });

        DB::table('data_sources')
            ->where('requires_otdr_protected_offers', true)
            ->orderBy('id')
            ->get(['id', 'name'])
            ->each(fn (object $connection) => logger()->warning('Dropped a connection\'s OTDR protection requirement; set it on the shipping methods it applies to', [
                'data_source_id' => $connection->id,
                'name' => $connection->name,
            ]));

        Schema::table('data_sources', function (Blueprint $table) {
            $table->dropColumn(['requires_on_time_offers', 'requires_otdr_protected_offers']);
        });
    }

    public function down(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            $table->boolean('requires_on_time_offers')->default(true)->after('off_amazon_shipping_checked_at');
            $table->boolean('requires_otdr_protected_offers')->default(false)->after('requires_on_time_offers');
        });

        Schema::table('shipping_methods', function (Blueprint $table) {
            $table->dropColumn(['excludes_late_rates', 'otdr_protection_orders']);
        });
    }
};
