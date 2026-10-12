<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shipment status `void` becomes `cancelled`, and who set the status, a
     * shipped-elsewhere tracking number and a review flag join it
     * (`order-reconciliation/02`).
     */
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('status_origin', 16)->nullable()->after('status');
            $table->timestamp('status_changed_at')->nullable()->after('status_origin');
            $table->string('external_carrier')->nullable()->after('status_changed_at');
            $table->string('external_tracking_number')->nullable()->after('external_carrier');
            $table->string('review_reason', 32)->nullable()->after('external_tracking_number');
            $table->timestamp('review_flagged_at')->nullable()->after('review_reason');

            $table->index('external_tracking_number');
            $table->index('review_reason');
        });

        DB::table('shipments')->where('status', 'void')->update(['status' => 'cancelled']);

        // Only the Shipment form ever set `void`.
        DB::table('shipments')->where('status', 'cancelled')->update(['status_origin' => 'manager']);

        // A shipped Shipment with no shipped Package came from a historical
        // Amazon import, which reports orders already shipped elsewhere.
        DB::table('shipments')
            ->where('status', 'shipped')
            ->whereExists(fn ($query) => $query->select(DB::raw(1))
                ->from('packages')
                ->whereColumn('packages.shipment_id', 'shipments.id')
                ->where('packages.status', 'shipped'))
            ->update(['status_origin' => 'polybag']);

        DB::table('shipments')
            ->where('status', 'shipped')
            ->whereNull('status_origin')
            ->update(['status_origin' => 'source']);
    }

    public function down(): void
    {
        DB::table('shipments')->where('status', 'cancelled')->update(['status' => 'void']);

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex(['external_tracking_number']);
            $table->dropIndex(['review_reason']);
            $table->dropColumn([
                'status_origin',
                'status_changed_at',
                'external_carrier',
                'external_tracking_number',
                'review_reason',
                'review_flagged_at',
            ]);
        });
    }
};
