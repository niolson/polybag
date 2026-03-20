<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('box_size_carrier_service', function (Blueprint $table) {
            $table->foreignId('box_size_id')->constrained()->cascadeOnDelete();
            $table->foreignId('carrier_service_id')->constrained()->cascadeOnDelete();
            $table->primary(['box_size_id', 'carrier_service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('box_size_carrier_service');
    }
};
