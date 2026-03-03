<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    /**
     * The Shipments list page sorts by created_at desc by default.
     * Without an index, MySQL does a filesort on every page load.
     */
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
