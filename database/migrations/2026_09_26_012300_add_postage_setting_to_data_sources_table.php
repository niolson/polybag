<?php

use App\Services\ShipmentImport\Sources\AmazonSource;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A connection's postage setting replaces the client blind-purchase opt-in
     * — ADR-0006 decision 6, `carrier-catalog-reset/10`.
     *
     * A `PostageSetting`, null for a driver that sells no postage. Existing
     * connections are set from what they did before, so nothing an install
     * buys changes on deploy. The defaults new connections get are the model's.
     *
     * - An Amazon connection gets *packer and automation*: approvals were the
     *   only gate, and *packer only* would stop approved automation.
     * - A Shopify connection whose client opted in gets *packer and
     *   automation*, since the opt-in allowed the Ship page and automation
     *   both. Every other Shopify connection with a client gets *does not
     *   sell postage*.
     * - A shared Shopify connection, with no client, had its consent read from
     *   each shipment's client. It gets *packer and automation* when every
     *   client with orders from it opted in. Otherwise it gets *does not sell
     *   postage*, and is logged if some of those clients had opted in: selling
     *   for them would spend for the clients who had not.
     */
    public function up(): void
    {
        Schema::table('data_sources', function (Blueprint $table): void {
            $table->string('postage_setting', 16)->nullable()->after('offers_off_amazon_shipping');
        });

        DB::table('data_sources')
            ->where('source_type', AmazonSource::class)
            ->update(['postage_setting' => 'automation']);

        DB::table('data_sources')
            ->where('source_type', ShopifySource::class)
            ->update(['postage_setting' => 'none']);

        DB::table('data_sources')
            ->where('source_type', ShopifySource::class)
            ->whereIn('client_id', DB::table('clients')->where('blind_purchase_enabled', true)->select('id'))
            ->update(['postage_setting' => 'automation']);

        $this->carryOverSharedShopifyConnections();

        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('blind_purchase_enabled');
        });
    }

    private function carryOverSharedShopifyConnections(): void
    {
        $optedIn = DB::table('clients')->where('blind_purchase_enabled', true)->pluck('id')->all();

        $shared = DB::table('data_sources')
            ->where('source_type', ShopifySource::class)
            ->whereNull('client_id')
            ->get(['id', 'name']);

        foreach ($shared as $connection) {
            $clients = DB::table('shipments')
                ->where('data_source_id', $connection->id)
                ->distinct()
                ->pluck('client_id')
                ->all();

            $consenting = array_values(array_intersect($clients, $optedIn));

            if ($clients !== [] && count($consenting) === count($clients)) {
                DB::table('data_sources')->where('id', $connection->id)->update(['postage_setting' => 'automation']);

                continue;
            }

            if ($consenting !== []) {
                logger()->warning('A shared Shopify connection serves clients that had not opted into blind purchase, so it no longer sells postage. Set its postage setting to sell for everyone, or give each client its own connection.', [
                    'data_source_id' => $connection->id,
                    'data_source' => $connection->name,
                    'opted_in_client_ids' => $consenting,
                    'client_ids' => $clients,
                ]);
            }
        }
    }

    /**
     * A client is opted back in when any of its Shopify connections sold to
     * automation. A connection with no client, or an Amazon setting, has no
     * client column to go back to.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->boolean('blind_purchase_enabled')
                ->default(false)
                ->after('active');
        });

        DB::table('clients')
            ->whereIn('id', DB::table('data_sources')
                ->where('source_type', ShopifySource::class)
                ->where('postage_setting', 'automation')
                ->whereNotNull('client_id')
                ->select('client_id'))
            ->update(['blind_purchase_enabled' => true]);

        Schema::table('data_sources', function (Blueprint $table): void {
            $table->dropColumn('postage_setting');
        });
    }
};
