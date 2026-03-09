<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('shipments')
            ->whereNull('deliverability')
            ->update(['deliverability' => 'not_checked']);

        Schema::table('shipments', function (Blueprint $table) {
            $table->string('deliverability')->default('not_checked')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('deliverability')->nullable()->default(null)->change();
        });

        DB::table('shipments')
            ->where('deliverability', 'not_checked')
            ->update(['deliverability' => null]);
    }
};
