<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Box Size is scanned and typed as its PolyBag code, `%B<id>`. The
     * operator's own code was a second identity that could collide with a
     * product barcode (ADR-0007, as amended 2026-11-24).
     */
    public function up(): void
    {
        Schema::table('box_sizes', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }

    public function down(): void
    {
        Schema::table('box_sizes', function (Blueprint $table) {
            $table->string('code')->nullable()->unique()->after('label');
        });
    }
};
