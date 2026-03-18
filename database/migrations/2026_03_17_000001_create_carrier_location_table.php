<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrier_location', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->json('pickup_days')->nullable();
            $table->dateTime('last_end_of_day_at')->nullable();
            $table->timestamps();
            $table->unique(['carrier_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_location');
    }
};
