<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carriers', function (Blueprint $table) {
            $table->id();
            // A carrier's name is its key (ADR-0006 decision 1): a seeded carrier is
            // `is_system` and its name is fixed so the registry, the seeders, alias
            // matching and ship dates keep finding it. What an operator wants to
            // relabel goes in `display_name`.
            $table->string('name')->unique();
            $table->string('display_name')->nullable();
            $table->boolean('is_system')->default(false);
            $table->unsignedTinyInteger('pickup_cutoff_hour')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carriers');
    }
};
