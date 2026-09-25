<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The catalog service a Label was bought as — `carrier-catalog-reset/02`.
     *
     * Beside `normalized_carrier_id`, and restricting deletion as it does: a
     * Label is history, and a report that names USPS Ground Advantage once,
     * whatever sold it, needs the row it points at to stay. Recorded on the
     * Label only, not projected onto `packages`.
     *
     * Null for a blind purchase. What Shopify was asked for stays the
     * requested preference (ADR-0003 decision 7), never the service bought.
     */
    public function up(): void
    {
        Schema::table('package_labels', function (Blueprint $table) {
            $table->foreignId('carrier_service_id')
                ->nullable()
                ->after('normalized_carrier_id')
                ->constrained()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('package_labels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('carrier_service_id');
        });
    }
};
