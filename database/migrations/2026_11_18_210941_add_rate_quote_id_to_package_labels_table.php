<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The quote a Label was bought from, so Rate Comparison can read the active
 * Label's quote and its quote session after a void and re-buy
 * (`release-polish/03`).
 *
 * On the Label rather than read through the Offer: a bought Offer is purchase
 * authority and is purged after days, while the quote log is kept for months.
 * Null for a blind purchase, which logs no quote, and once the quote is purged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('package_labels', function (Blueprint $table) {
            $table->foreignId('rate_quote_id')->nullable()->after('carrier_service_id')->constrained('rate_quotes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('package_labels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rate_quote_id');
        });
    }
};
