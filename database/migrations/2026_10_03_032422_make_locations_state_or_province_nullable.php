<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let a Location have no state or province.
     *
     * The address form already asks for one only where the country uses one,
     * so a London location passed validation and then failed the insert.
     * Shipments and client return addresses were nullable from the start.
     */
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->string('state_or_province')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->string('state_or_province')->nullable(false)->change();
        });
    }
};
