<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->index('channel_id', 'shipments_channel_id_index');

            $table->foreignId('import_source_id')
                ->nullable()
                ->after('channel_id')
                ->constrained('import_sources')
                ->nullOnDelete();
            $table->string('source_record_id')
                ->nullable()
                ->after('shipment_reference');

            $table->dropUnique('shipments_channel_id_shipment_reference_unique');
            $table->unique(['import_source_id', 'source_record_id'], 'shipments_import_source_record_unique');
            $table->index(['import_source_id', 'source_record_id'], 'shipments_import_source_record_index');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropUnique('shipments_import_source_record_unique');
            $table->dropIndex('shipments_import_source_record_index');
            $table->dropConstrainedForeignId('import_source_id');
            $table->dropIndex('shipments_channel_id_index');
            $table->dropColumn('source_record_id');

            $table->unique(['channel_id', 'shipment_reference']);
        });
    }
};
