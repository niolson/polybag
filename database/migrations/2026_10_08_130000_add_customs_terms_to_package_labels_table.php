<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a Label declared at purchase — ADR-0008 decision 6,
     * `international-customs-terms/06`.
     *
     * `customs_terms` is a snapshot of the resolved duties term, seller
     * registration and ITN, written by the shipping workflow. It never holds
     * the recipient's tax ID. `duties_cost` is the prepaid duties amount a
     * carrier reports, null until `08` fills it.
     */
    public function up(): void
    {
        Schema::table('package_labels', function (Blueprint $table): void {
            $table->json('customs_terms')->nullable();
            $table->decimal('duties_cost', 10, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('package_labels', function (Blueprint $table): void {
            $table->dropColumn(['customs_terms', 'duties_cost']);
        });
    }
};
