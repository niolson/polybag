<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('contains_alcohol')->default(false)->after('active');
            $table->string('hazmat_class')->nullable()->after('contains_alcohol');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['contains_alcohol', 'hazmat_class']);
        });
    }
};
