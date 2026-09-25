<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Observations become a record of what was seen; the mapping lives in
     * `source_service_mappings` (`carrier-catalog-reset/14`).
     *
     * Dropped without copying: no install held a mapping when this shipped,
     * locally or on the demo tenants.
     */
    public function up(): void
    {
        Schema::table('observed_services', function (Blueprint $table) {
            $table->dropIndex('observed_services_mapping_index');
            $table->dropConstrainedForeignId('carrier_service_id');
        });
    }

    public function down(): void
    {
        Schema::table('observed_services', function (Blueprint $table) {
            $table->foreignId('carrier_service_id')->nullable()->after('external_service_name')->constrained()->nullOnDelete();
            $table->index(['source', 'environment', 'carrier_service_id'], 'observed_services_mapping_index');
        });
    }
};
