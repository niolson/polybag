<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The seller's declaration that a product qualifies as media — ADR-0006
     * decision 11. Off by default and never inferred: the seller is liable for
     * misdeclared Media Mail, so nothing but the seller turns it on.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_media')->default(false)->after('contains_alcohol');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('is_media');
        });
    }
};
