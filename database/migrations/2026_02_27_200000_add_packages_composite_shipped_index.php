<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            // Composite index for the most common report filter pattern:
            // WHERE shipped = 1 AND shipped_at >= X
            // Replaces separate single-column indexes for this use case.
            $table->index(['shipped', 'shipped_at'], 'packages_shipped_shipped_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropIndex('packages_shipped_shipped_at_index');
        });
    }
};
