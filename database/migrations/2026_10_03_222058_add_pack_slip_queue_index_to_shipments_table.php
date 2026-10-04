<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Supports the pack slip queue: open Shipments, by the version of their
     * latest recorded slip (null for never printed).
     */
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->index(['status', 'pack_slip_items_version'], 'shipments_pack_slip_queue_index');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex('shipments_pack_slip_queue_index');
        });
    }
};
