<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_shipping_stats', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('carrier')->nullable();
            $table->string('service')->nullable();
            $table->foreignId('channel_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shipping_method_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('package_count')->default(0);
            $table->decimal('total_cost', 12, 2)->default(0);
            $table->decimal('total_weight', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['date', 'carrier', 'service', 'channel_id', 'shipping_method_id', 'location_id'], 'daily_stats_composite_unique');
            $table->index(['date', 'channel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_shipping_stats');
    }
};
