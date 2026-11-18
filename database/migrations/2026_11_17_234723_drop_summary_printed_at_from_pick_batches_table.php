<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The column was stamped when the summary PDF rendered, not when it printed, and
     * nothing read it.
     */
    public function up(): void
    {
        Schema::table('pick_batches', function (Blueprint $table) {
            $table->dropColumn('summary_printed_at');
        });
    }

    public function down(): void
    {
        Schema::table('pick_batches', function (Blueprint $table) {
            $table->timestamp('summary_printed_at')->nullable();
        });
    }
};
