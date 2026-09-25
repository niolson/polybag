<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The one table every postage source's codes map through — ADR-0006
     * decision 2, `carrier-catalog-reset/14`.
     *
     * One row per mapping, where `observed_services.carrier_service_id` copied a
     * mapping onto every sighting and needed a lock to keep the copies agreeing.
     * Seeded rows (Shopify in `09`, Amazon in `11`) are written once by their own
     * migrations, never by the reference-data sync, which would restore a mapping
     * an Admin had removed.
     */
    public function up(): void
    {
        Schema::create('source_service_mappings', function (Blueprint $table) {
            $table->id();

            // A `PostageSourceKind`: the kind of source, not one connection.
            $table->string('source_kind', 32);

            // The source's own identifiers, kept verbatim.
            $table->string('external_carrier_id', 64);
            $table->string('external_service_id', 128);

            // Restricts deletion, as a shipping rule's does: deleting a service
            // would silently unmap it. Deactivate the service instead.
            $table->foreignId('carrier_service_id')->constrained()->restrictOnDelete();

            // A Shopify purchase sends exactly one code for a service, so a
            // service has at most one Shopify row. MySQL has no partial unique
            // index, so this column is null for every other kind and carries the
            // constraint; NULLs never collide in a unique index.
            $table->unsignedBigInteger('shopify_carrier_service_id')
                ->nullable()
                ->virtualAs("case when source_kind = 'shopify' then carrier_service_id end");

            $table->timestamps();

            $table->unique(
                ['source_kind', 'external_carrier_id', 'external_service_id'],
                'source_service_mappings_external_unique',
            );
            $table->unique('shopify_carrier_service_id', 'source_service_mappings_shopify_service_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_service_mappings');
    }
};
