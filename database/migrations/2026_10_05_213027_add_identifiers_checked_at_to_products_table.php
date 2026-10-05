<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record when the Amazon catalog last answered for a Product's ASIN
     * (`product-identifier-import/05`), so a SKU the catalog holds nothing for
     * is not looked up again on every import.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->timestamp('identifiers_checked_at')->nullable()->after('gtin');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('identifiers_checked_at');
        });
    }
};
