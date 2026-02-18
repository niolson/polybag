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
            $table->boolean('manifested')->default(false)->after('manifest_id');
        });

        // Backfill: packages already linked to a manifest are manifested
        DB::table('packages')
            ->whereNotNull('manifest_id')
            ->update(['manifested' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('manifested');
        });
    }
};
