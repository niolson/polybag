<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('label_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('label_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('package_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('pending');
            $table->string('tracking_number')->nullable();
            $table->string('carrier')->nullable();
            $table->string('service')->nullable();
            $table->decimal('cost', 8, 2)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('label_batch_items');
    }
};
