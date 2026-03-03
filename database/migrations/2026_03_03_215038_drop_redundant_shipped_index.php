<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the standalone shipped index — now redundant since all composite
     * indexes on packages start with the shipped column.
     */
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropIndex('packages_shipped_index');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->index('shipped', 'packages_shipped_index');
        });
    }
};
