<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->string('carrier');
            $table->string('service_code');
            $table->string('service_name');
            $table->decimal('quoted_price', 8, 2);
            $table->string('quoted_delivery_date')->nullable();
            $table->string('transit_time')->nullable();
            $table->boolean('selected')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
            $table->index('carrier');
            $table->index(['package_id', 'selected', 'quoted_price', 'carrier'], 'rate_quotes_comparison_covering');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_quotes');
    }
};
