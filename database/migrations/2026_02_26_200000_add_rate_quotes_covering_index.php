<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rate_quotes', function (Blueprint $table) {
            // Covering index for the Rate Comparison report's GROUP BY aggregation.
            // Includes all columns needed so the query runs entirely from the index.
            $table->index(
                ['package_id', 'selected', 'quoted_price', 'carrier'],
                'rate_quotes_comparison_covering'
            );
        });
    }

    public function down(): void
    {
        Schema::table('rate_quotes', function (Blueprint $table) {
            $table->dropIndex('rate_quotes_comparison_covering');
        });
    }
};
