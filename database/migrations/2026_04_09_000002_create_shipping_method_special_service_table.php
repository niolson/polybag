<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_method_special_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipping_method_id')->constrained()->cascadeOnDelete();
            $table->foreignId('special_service_id')->constrained()->cascadeOnDelete();
            $table->string('mode')->default('available'); // available, default, required
            $table->json('config')->nullable(); // service-specific defaults for this method
            $table->timestamps();

            $table->unique(['shipping_method_id', 'special_service_id'], 'smss_method_service_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_method_special_service');
    }
};
