<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('shipping_method_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('priority')->default(0);
            $table->json('conditions')->nullable();
            $table->string('action');
            $table->foreignId('carrier_service_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->index('priority');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_rules');
    }
};
