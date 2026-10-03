<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record a Shipment's latest pack slip on the Shipment itself.
     *
     * `items_version` moves whenever the Shipment's items change. A slip is out of
     * date when it has moved past `pack_slip_items_version`, the version the latest
     * recorded slip was drawn from; null means no slip was ever recorded. The
     * receipt's issue time, to the microsecond, orders two receipts of the same
     * version, so a late acknowledgment of an older document cannot overwrite a
     * newer print. The printed time and user are for display only.
     */
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->unsignedInteger('items_version')->default(0)->after('picking_status');
            $table->unsignedInteger('pack_slip_items_version')->nullable()->after('items_version');
            $table->dateTime('pack_slip_receipt_issued_at', 6)->nullable()->after('pack_slip_items_version');
            $table->timestamp('pack_slip_printed_at')->nullable()->after('pack_slip_receipt_issued_at');
            $table->foreignId('pack_slip_printed_by_user_id')->nullable()->after('pack_slip_printed_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pack_slip_printed_by_user_id');
            $table->dropColumn([
                'items_version',
                'pack_slip_items_version',
                'pack_slip_receipt_issued_at',
                'pack_slip_printed_at',
            ]);
        });
    }
};
