<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('box_sizes', function (Blueprint $table) {
            $table->id();
            $table->decimal('height', 8, 2);
            $table->decimal('width', 8, 2);
            $table->decimal('length', 8, 2);
            $table->decimal('max_weight', 8, 2);
            $table->decimal('empty_weight', 8, 2);
            $table->decimal('materials_cost', 8, 2)->nullable();
            $table->string('label');
            $table->string('code')->unique();
            // An inactive Box Size stays in the catalog but leaves the barcode sheet,
            // the Pack lookup and the box pickers.
            $table->boolean('active')->default(true);
            $table->string('type');
            $table->string('carrier_packaging')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('box_sizes');
    }
};
