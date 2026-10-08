<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ECB reference date a Shipment's customs thresholds are converted at
     * — `international-customs-terms/04`.
     *
     * Pinned by the first resolution that converts a value, and reused after,
     * so whether a registration is under its threshold cannot change because
     * a later day's rates were fetched in between. Not personal data, so not
     * purged with the address.
     */
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->date('customs_rate_date')->nullable()->after('export_itn');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropColumn('customs_rate_date');
        });
    }
};
