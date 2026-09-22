<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Separate "this connection imports orders" from `active`, which now means
     * only "this connection may be used at all". The default backfills every
     * existing row on, since every row was an importer until now.
     */
    public function up(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            $table->boolean('import_enabled')->default(true)->after('active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            $table->dropColumn('import_enabled');
        });
    }
};
