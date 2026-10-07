<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A client's seller tax registrations — ADR-0008 decision 3,
     * `international-customs-terms/03`.
     *
     * At most one per regime: the regime fixes the destinations a number
     * covers, its format and its low-value threshold, so a second number for
     * the same regime would leave nothing to choose between them.
     */
    public function up(): void
    {
        Schema::create('client_tax_registrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();

            // A `TaxRegistrationRegime`.
            $table->string('regime', 16);

            $table->string('number', 20);
            $table->timestamps();

            $table->unique(['client_id', 'regime']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_tax_registrations');
    }
};
