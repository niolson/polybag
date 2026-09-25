<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which catalog service and carrier an offer is for — `carrier-catalog-reset/02`.
     *
     * Stored so a Ship-page purchase restores both from the server's copy
     * rather than the browser's, the same as its price and service code. The
     * carrier is the one expected to carry the parcel, and dates the purchase
     * by id, so a rename between quote and purchase changes nothing.
     *
     * Both null on delete, like the offer's other pointers: offers are
     * short-lived and must never be what stops a catalog row being removed.
     */
    public function up(): void
    {
        Schema::table('shipping_offers', function (Blueprint $table) {
            $table->foreignId('carrier_id')
                ->nullable()
                ->after('carrier')
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('carrier_service_id')
                ->nullable()
                ->after('service_code')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shipping_offers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('carrier_service_id');
            $table->dropConstrainedForeignId('carrier_id');
        });
    }
};
