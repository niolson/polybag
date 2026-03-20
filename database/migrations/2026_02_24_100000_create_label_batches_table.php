<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('label_batches', function (Blueprint $table) {
            $table->id();
            $table->string('bus_batch_id')->nullable()->index();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('box_size_id')->constrained();
            $table->string('label_format', 10)->default('pdf');
            $table->unsignedSmallInteger('label_dpi')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedInteger('total_shipments');
            $table->unsignedInteger('successful_shipments')->default(0);
            $table->unsignedInteger('failed_shipments')->default(0);
            $table->decimal('total_cost', 10, 2)->default('0.00');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('label_batches');
    }
};
