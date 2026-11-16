<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The sidebar counts remaining work on every page load (cached for a minute),
 * so each count must read an index rather than scan its table as history grows.
 *
 * - `packages (status, label_printed_at)`: shipped Labels not yet printed, for
 *   Label Batches.
 * - `packages (normalized_carrier_id, ship_date)`: one carrier's Labels over a
 *   day or two, for End of Day.
 * - `label_batches (status)`: batches still in flight, to tell a stalled one.
 * - `shipping_offers (purchase_reference, purchase_failed_at, consumed_at)`:
 *   consumed purchases with no reply, for Packages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->index(['status', 'label_printed_at'], 'packages_status_label_printed_at_index');
            $table->index(['normalized_carrier_id', 'ship_date'], 'packages_carrier_ship_date_index');
        });

        Schema::table('label_batches', function (Blueprint $table) {
            $table->index('status');
        });

        Schema::table('shipping_offers', function (Blueprint $table) {
            $table->index(['purchase_reference', 'purchase_failed_at', 'consumed_at'], 'shipping_offers_unanswered_index');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            // MySQL dropped the foreign key's own index once the composite
            // could serve it, and refuses to drop the composite without one.
            $table->index('normalized_carrier_id');
            $table->dropIndex('packages_status_label_printed_at_index');
            $table->dropIndex('packages_carrier_ship_date_index');
        });

        Schema::table('label_batches', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });

        Schema::table('shipping_offers', function (Blueprint $table) {
            $table->dropIndex('shipping_offers_unanswered_index');
        });
    }
};
