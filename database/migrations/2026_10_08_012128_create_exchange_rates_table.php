<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ECB's daily euro reference rates, one row per currency per day —
     * `international-customs-terms/04`.
     *
     * A seller tax registration applies only below its regime's low-value
     * threshold, which is set in EUR, GBP, NOK or AUD, while customs values
     * are USD. The EU tests IOSS at the ECB rate on the day payment was
     * accepted, so each published day is kept rather than only the latest.
     */
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table): void {
            $table->id();

            // The ECB reference date the rate was published for.
            $table->date('rate_date');

            // ISO 4217; the currency one euro is quoted in.
            $table->char('currency', 3);

            // Units of `currency` per one euro, as the ECB publishes it.
            $table->decimal('rate', 18, 6);

            $table->timestamps();

            $table->unique(['rate_date', 'currency']);
            $table->index(['currency', 'rate_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
