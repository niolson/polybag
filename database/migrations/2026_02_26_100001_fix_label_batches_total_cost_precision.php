<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('label_batches', function (Blueprint $table) {
            $table->decimal('total_cost', 8, 2)->default('0.00')->change();
        });
    }

    public function down(): void
    {
        Schema::table('label_batches', function (Blueprint $table) {
            $table->decimal('total_cost', 10, 2)->default('0.00')->change();
        });
    }
};
