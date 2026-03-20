<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrier_service_shipping_method', function (Blueprint $table) {
            $table->foreignId('carrier_service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipping_method_id')->constrained()->cascadeOnDelete();
            $table->primary(['carrier_service_id', 'shipping_method_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_service_shipping_method');
    }
};
