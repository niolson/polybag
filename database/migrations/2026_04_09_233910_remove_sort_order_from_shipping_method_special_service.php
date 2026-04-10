<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('shipping_method_special_service', 'sort_order')) {
            Schema::table('shipping_method_special_service', function (Blueprint $table) {
                $table->dropColumn('sort_order');
            });
        }
    }

    public function down(): void
    {
        Schema::table('shipping_method_special_service', function (Blueprint $table) {
            $table->unsignedSmallInteger('sort_order')->default(0);
        });
    }
};
