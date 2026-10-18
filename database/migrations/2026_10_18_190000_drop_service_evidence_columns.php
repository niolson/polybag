<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A service is inferred exactly when the postage was bought through Shopify,
     * which reports none, so the evidence, the rung and the ruleset version said
     * nothing the postage source did not. `requested_service` stays: what was
     * asked for is audit metadata, not provenance.
     */
    public function up(): void
    {
        foreach (['packages', 'package_labels'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropColumn(['service_evidence', 'service_inference_method', 'service_ruleset_version']);
            });
        }
    }

    public function down(): void
    {
        foreach (['packages', 'package_labels'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->string('service_evidence', 16)->default('unknown');
                $table->string('service_inference_method', 64)->nullable();
                $table->string('service_ruleset_version', 32)->nullable();
            });
        }
    }
};
