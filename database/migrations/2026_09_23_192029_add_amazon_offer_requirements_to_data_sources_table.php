<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What automation insists on before buying a Label for one of this Amazon
     * connection's orders (`amazon-buy-shipping/16`). Columns rather than
     * `settings` keys so the defaults hold for every existing connection
     * without a backfill.
     */
    public function up(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            $table->boolean('requires_on_time_offers')->default(true)->after('off_amazon_shipping_checked_at');
            $table->boolean('requires_otdr_protected_offers')->default(false)->after('requires_on_time_offers');
        });
    }

    public function down(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            $table->dropColumn(['requires_on_time_offers', 'requires_otdr_protected_offers']);
        });
    }
};
