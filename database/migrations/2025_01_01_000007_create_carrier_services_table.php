<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrier_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrier_id')->constrained()->cascadeOnDelete();
            $table->string('service_code');
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->boolean('can_ship_to_po_boxes')->default(false);
            $table->boolean('can_ship_to_military_addresses')->default(false);
            // The contents a service is valid for, as a `ContentClass`. Null means no
            // requirement. A property of the service, so it binds every source that
            // sells it (ADR-0006 decision 10).
            $table->string('required_contents', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_services');
    }
};
