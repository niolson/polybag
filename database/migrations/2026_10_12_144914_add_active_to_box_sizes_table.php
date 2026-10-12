<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An inactive Box Size stays in the catalog but leaves the barcode sheet,
     * the Pack lookup and the box pickers — `box-size-seed-data/02`.
     */
    public function up(): void
    {
        Schema::table('box_sizes', function (Blueprint $table) {
            $table->boolean('active')->default(true)->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('box_sizes', function (Blueprint $table) {
            $table->dropColumn('active');
        });
    }
};
