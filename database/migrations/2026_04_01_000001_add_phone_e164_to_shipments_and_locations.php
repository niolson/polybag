<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('phone_e164')->nullable()->after('phone');
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->string('phone_e164')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn('phone_e164');
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('phone_e164');
        });
    }
};
