<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('company')->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('address1');
            $table->string('address2')->nullable();
            $table->string('city');
            $table->string('state_or_province');
            $table->string('postal_code');
            $table->string('country', 2)->default('US');
            $table->string('phone')->nullable();
            $table->string('timezone', 50)->default('America/New_York');
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index('is_default');
            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
