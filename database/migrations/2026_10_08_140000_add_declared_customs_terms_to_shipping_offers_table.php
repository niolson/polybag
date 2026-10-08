<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the carrier was sent for this Offer's purchase, recorded at claim
     * time (`international-customs-terms/06`).
     *
     * A purchase whose reply never arrived is recorded later — by recovery or
     * by hand — and the Shipment may have been edited in between, so the Label's
     * `customs_terms` snapshot is read back from here rather than rebuilt from
     * the Shipment as it is then. Holds no recipient tax ID, only its type.
     */
    public function up(): void
    {
        Schema::table('shipping_offers', function (Blueprint $table): void {
            $table->json('declared_customs_terms')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shipping_offers', function (Blueprint $table): void {
            $table->dropColumn('declared_customs_terms');
        });
    }
};
