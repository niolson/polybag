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
            $table->string('name')->nullable();
            $table->string('sku')->nullable()->index();
            $table->string('barcode')->nullable()->index();
            $table->string('description')->nullable();
            $table->decimal('weight', 8, 2)->nullable();
            $table->boolean('active')->default(true);
            $table->string('hs_code')->nullable(); // Harmonized System Code
            $table->string('country_of_origin')->nullable(); // ISO Alpha-2 country code
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
