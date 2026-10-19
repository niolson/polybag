<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a postage source has told us exists — ADR-0003 decision 2.
     *
     * Deliberately not `carrier_services`. Observation must not rewrite
     * authored configuration, and `carrier_services.carrier_id` is a
     * non-nullable FK, so an offer naming a carrier we hold no row for could
     * not be stored there at all. The production `getRates` run returned
     * exactly that case: OnTrac, eligible and cheapest, with no `Carrier` row,
     * no account and no adapter.
     *
     * Durable, unlike `shipping_offers`: an identity keeps its first-seen date
     * for as long as the source keeps offering it. Nothing purges this table.
     * What an identity maps to lives in `source_service_mappings`, not here.
     */
    public function up(): void
    {
        Schema::create('observed_services', function (Blueprint $table) {
            $table->id();

            // The identity ADR-0003 names: (source, environment, marketplace,
            // carrierId, serviceId). `source` is the kind of postage source,
            // not one instance of it — two Amazon data sources quoting the same
            // marketplace are looking at the same catalog.
            $table->string('source', 32);
            $table->string('environment', 16);

            // Empty string rather than null for the marketplace a source does
            // not have one of: MySQL treats NULLs as distinct in a unique
            // index, so a nullable column here would let the same identity be
            // inserted without limit and silently defeat the deduplication
            // this table exists for.
            $table->string('marketplace', 32)->default('');

            // The source's own identifiers, meaningful only within that source
            // and environment. Kept verbatim; normalization is a separate act.
            $table->string('external_carrier_id', 64);
            $table->string('external_carrier_name')->nullable();
            $table->string('external_service_id', 128);
            $table->string('external_service_name')->nullable();

            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');

            // Separate from last_seen_at because most of what Amazon reports is
            // ineligible for the parcel at hand: a 102-entry `ineligibleRates`
            // array is the richest catalog view it offers, and identity is all
            // of it that is usable (every reason code came back UNKNOWN). A
            // service only ever seen ineligible is a real identity that we have
            // never actually been able to buy, and the mapping page should be
            // able to tell the two apart.
            $table->timestamp('last_eligible_at')->nullable();

            $table->unsignedInteger('observation_count')->default(0);
            $table->json('additional_inputs_schema')->nullable();
            $table->timestamp('additional_inputs_schema_seen_at')->nullable();
            $table->timestamps();

            $table->unique([
                'source',
                'environment',
                'marketplace',
                'external_carrier_id',
                'external_service_id',
            ], 'observed_services_identity_unique');

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observed_services');
    }
};
