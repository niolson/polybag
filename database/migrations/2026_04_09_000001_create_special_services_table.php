<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('special_services', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('scope'); // shipment, package
            $table->string('category'); // delivery, compliance, pickup, returns, etc.
            $table->boolean('requires_value')->default(false);
            $table->json('config_schema')->nullable(); // describes additional data fields needed
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('special_services');
    }
};
