<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The SKUs an Amazon catalog sync listed, with their ASINs, waiting for the
 * catalog look-up that follows the listings (`product-catalog-sync/04`).
 * A row is deleted once its ASIN is looked up, or found not to need it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amazon_catalog_lookups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_source_id')->constrained()->cascadeOnDelete();
            $table->string('sku');
            $table->string('asin', 20);

            $table->unique(['data_source_id', 'sku']);
            $table->index(['data_source_id', 'asin']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amazon_catalog_lookups');
    }
};
