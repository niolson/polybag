<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Somewhere to keep the `additionalInputs` schema a rate asked for.
     *
     * Amazon publishes the schema nowhere — it is fetched per rate from
     * `getAdditionalInputs`, and only a rate that set `requiresAdditionalInputs`
     * has one. No such rate has ever been quoted to this account, and the
     * sandbox cannot produce one (`amazon-buy-shipping/09`, `13`). So the
     * adapter records the schema the first time any tenant is quoted a rate
     * that asks, against the observed service it was asked for, and drops
     * the rate until someone has read the schema and built the payload.
     *
     * On the observation row rather than in a log line because a log rotates
     * and this is the one input the next piece of work needs. A JSON schema
     * carries no order data, so it can sit here indefinitely.
     */
    public function up(): void
    {
        Schema::table('observed_services', function (Blueprint $table) {
            $table->json('additional_inputs_schema')->nullable()->after('observation_count');
            $table->timestamp('additional_inputs_schema_seen_at')->nullable()->after('additional_inputs_schema');
        });
    }

    public function down(): void
    {
        Schema::table('observed_services', function (Blueprint $table) {
            $table->dropColumn(['additional_inputs_schema', 'additional_inputs_schema_seen_at']);
        });
    }
};
