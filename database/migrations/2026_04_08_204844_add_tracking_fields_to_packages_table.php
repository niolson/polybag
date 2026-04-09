<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->string('tracking_status')->nullable()->after('status');
            $table->timestamp('tracking_updated_at')->nullable()->after('tracking_status');
            $table->timestamp('delivered_at')->nullable()->after('tracking_updated_at');
            $table->json('tracking_details')->nullable()->after('delivered_at');
            $table->timestamp('tracking_checked_at')->nullable()->after('tracking_details');

            $table->index('tracking_status');
            $table->index('tracking_checked_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropIndex(['tracking_status']);
            $table->dropIndex(['tracking_checked_at']);
            $table->dropColumn([
                'tracking_status',
                'tracking_updated_at',
                'delivered_at',
                'tracking_details',
                'tracking_checked_at',
            ]);
        });
    }
};
