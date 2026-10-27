<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A connection's postage setting becomes a yes or no (ADR-0006 decision 6,
 * 2026-10-26 amendment). *Packer only* is gone: the shipping method's allowance
 * already decides what automation buys, so a connection that sold to a packer
 * now sells.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            $table->boolean('sells_postage')->default(false)->after('offers_off_amazon_shipping');
        });

        DB::table('data_sources')
            ->whereIn('postage_setting', ['packer', 'automation'])
            ->update(['sells_postage' => true]);

        Schema::table('data_sources', function (Blueprint $table) {
            $table->dropColumn('postage_setting');
        });
    }

    public function down(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            $table->string('postage_setting', 16)->nullable()->after('offers_off_amazon_shipping');
        });

        DB::table('data_sources')
            ->where('sells_postage', true)
            ->update(['postage_setting' => 'automation']);

        DB::table('data_sources')
            ->where('sells_postage', false)
            ->whereIn('source_type', [
                'App\Services\ShipmentImport\Sources\ShopifySource',
                'App\Services\ShipmentImport\Sources\AmazonSource',
            ])
            ->update(['postage_setting' => 'none']);

        Schema::table('data_sources', function (Blueprint $table) {
            $table->dropColumn('sells_postage');
        });
    }
};
