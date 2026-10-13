<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The review flag `order-reconciliation/02` added goes again: the cut-back
     * PRD closes an order its source closed and flags nothing, so nothing
     * would ever set it. No code set it before either.
     */
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            // The index first: SQLite cannot drop an indexed column.
            $table->dropIndex(['review_reason']);
            $table->dropColumn(['review_reason', 'review_flagged_at']);
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->string('review_reason', 32)->nullable()->after('external_tracking_number');
            $table->timestamp('review_flagged_at')->nullable()->after('review_reason');

            $table->index('review_reason');
        });
    }
};
