<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Approvals for Amazon orders and for orders from other channels are
     * separate — `amazon-shipping-external-orders/07`.
     *
     * Off-Amazon Amazon Shipping has its own prices and none of Buy Shipping's
     * protections, so an approval given for Amazon orders is not consent to
     * buy the same service for a Shopify order. `channel_type` is Shipping
     * v2's `channelType`, lowercased.
     *
     * Every existing row becomes an `amazon` approval, which is the narrower
     * reading of what it was granted for. Before this, one row covered both
     * channels, so automation stops buying off-Amazon until someone approves
     * it for other channels. No production account sells off-Amazon yet.
     *
     * The lookup index is rebuilt before the old one is dropped: on MySQL it is
     * the index `client_id`'s foreign key uses.
     */
    public function up(): void
    {
        Schema::table('service_approvals', function (Blueprint $table) {
            $table->string('channel_type', 16)->default('amazon')->after('environment');

            $table->index(['client_id', 'source', 'environment', 'channel_type'], 'service_approvals_channel_lookup_index');
        });

        Schema::table('service_approvals', function (Blueprint $table) {
            $table->dropIndex('service_approvals_lookup_index');
            $table->dropUnique('service_approvals_scope_unique');

            $table->unique([
                'source',
                'environment',
                'channel_type',
                'external_carrier_id',
                'external_service_id',
                'effect',
                'client_id',
            ], 'service_approvals_scope_unique');
        });
    }

    public function down(): void
    {
        // Without the column every row reads as covering both channels, so an
        // approval for other channels would become one for Amazon orders too,
        // and an exception for other channels would also carve services out of
        // Amazon orders. Dropped rather than widened.
        DB::table('service_approvals')->where('channel_type', '!=', 'amazon')->delete();

        Schema::table('service_approvals', function (Blueprint $table) {
            $table->index(['client_id', 'source', 'environment'], 'service_approvals_lookup_index');
        });

        Schema::table('service_approvals', function (Blueprint $table) {
            $table->dropUnique('service_approvals_scope_unique');
            $table->dropIndex('service_approvals_channel_lookup_index');
            $table->dropColumn('channel_type');

            $table->unique([
                'source',
                'environment',
                'external_carrier_id',
                'external_service_id',
                'effect',
                'client_id',
            ], 'service_approvals_scope_unique');
        });
    }
};
