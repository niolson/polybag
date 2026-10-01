<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hold the two EU product identifiers the app has no column for
     * (`eu-product-identifiers/01`).
     *
     * `manufacturer_part_number` is the NS-PID, capped at UPS's 100-character
     * `ProductID`. `gtin` is the S-PID, kept apart from `barcode` because the
     * scan barcode may be an internal Code 128 label rather than a GTIN.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('manufacturer_part_number', 100)->nullable()->after('country_of_origin');
            $table->string('gtin', 14)->nullable()->after('manufacturer_part_number');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['manufacturer_part_number', 'gtin']);
        });
    }
};
