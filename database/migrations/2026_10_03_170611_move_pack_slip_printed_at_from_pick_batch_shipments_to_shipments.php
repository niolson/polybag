<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Move pack slip printed state from pick-batch membership onto the Shipment.
     *
     * Each Shipment takes the latest time any of its batches printed it, unless the
     * Shipment already records a newer print from its own page. A carried-over print is
     * taken as drawn from the Shipment's current items, with no issue time and no user:
     * membership never recorded either, and a null issue time is what lets the first
     * real receipt of the same version replace it.
     */
    public function up(): void
    {
        DB::table('pick_batch_shipments')
            ->whereNotNull('pack_slip_printed_at')
            ->groupBy('shipment_id')
            ->select('shipment_id', DB::raw('MAX(pack_slip_printed_at) as printed_at'))
            ->orderBy('shipment_id')
            ->each(function (object $row): void {
                DB::table('shipments')
                    ->where('id', $row->shipment_id)
                    ->where(fn (Builder $query) => $query
                        ->whereNull('pack_slip_printed_at')
                        ->orWhere('pack_slip_printed_at', '<', $row->printed_at))
                    ->update([
                        'pack_slip_items_version' => DB::raw('items_version'),
                        'pack_slip_receipt_issued_at' => null,
                        'pack_slip_printed_at' => $row->printed_at,
                        'pack_slip_printed_by_user_id' => null,
                    ]);
            });

        Schema::table('pick_batch_shipments', function (Blueprint $table) {
            $table->dropColumn('pack_slip_printed_at');
        });
    }

    /**
     * Restore the column, marking each membership with its Shipment's printed time.
     * Which batch printed it is not recoverable, so every batch the Shipment is in
     * gets it.
     */
    public function down(): void
    {
        Schema::table('pick_batch_shipments', function (Blueprint $table) {
            $table->timestamp('pack_slip_printed_at')->nullable()->after('picked_at');
        });

        DB::table('shipments')
            ->whereNotNull('pack_slip_printed_at')
            ->whereIn('id', DB::table('pick_batch_shipments')->select('shipment_id'))
            ->select('id', 'pack_slip_printed_at')
            ->orderBy('id')
            ->each(function (object $shipment): void {
                DB::table('pick_batch_shipments')
                    ->where('shipment_id', $shipment->id)
                    ->update(['pack_slip_printed_at' => $shipment->pack_slip_printed_at]);
            });
    }
};
