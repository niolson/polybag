<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained();
            $table->string('name')->nullable();
            $table->string('sku')->nullable()->index();
            $table->string('barcode')->nullable()->index();
            $table->string('description')->nullable();
            $table->decimal('weight', 8, 2)->nullable();
            $table->decimal('handling_surcharge', 8, 2)->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('contains_alcohol')->default(false);
            // The seller's declaration that a product qualifies as media (ADR-0006
            // decision 11). Off by default and never inferred: the seller is liable
            // for misdeclared Media Mail.
            $table->boolean('is_media')->default(false);
            $table->string('hazmat_class')->nullable();
            $table->string('hs_tariff_number')->nullable();
            $table->string('country_of_origin')->nullable();
            // The two EU product identifiers. `manufacturer_part_number` is the NS-PID,
            // capped at UPS's 100-character `ProductID`; `gtin` is the S-PID, kept apart
            // from `barcode` because the scan barcode may be an internal Code 128 label.
            $table->string('manufacturer_part_number', 100)->nullable();
            $table->string('gtin', 14)->nullable();
            // When the Amazon catalog last answered for the ASIN, so a SKU it holds
            // nothing for is not looked up again on every import.
            $table->timestamp('identifiers_checked_at')->nullable();
            $table->string('bin_location')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
