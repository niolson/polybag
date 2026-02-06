<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->index('shipment_reference');
            $table->index('shipped');
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->index('tracking_number');
            $table->index('shipped');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex(['shipment_reference']);
            $table->dropIndex(['shipped']);
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->dropIndex(['tracking_number']);
            $table->dropIndex(['shipped']);
        });
    }
};
