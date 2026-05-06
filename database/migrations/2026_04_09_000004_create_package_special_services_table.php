<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_special_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('special_service_id')->constrained()->cascadeOnDelete();
            $table->string('source'); // shipping_method, product, manual, system, rule
            $table->string('source_reference')->nullable(); // e.g. "product_id:42"
            $table->json('config')->nullable(); // service-specific values (dry ice weight, emails, etc.)
            $table->timestamp('applied_at');
            $table->timestamps();

            $table->unique(['package_id', 'special_service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_special_services');
    }
};
