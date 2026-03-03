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
        Schema::table('packages', function (Blueprint $table) {
            $table->boolean('weight_mismatch')->default(false)->after('weight');
            $table->index(['shipped', 'weight_mismatch', 'shipped_at'], 'packages_weight_mismatch_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropIndex('packages_weight_mismatch_index');
            $table->dropColumn('weight_mismatch');
        });
    }
};
