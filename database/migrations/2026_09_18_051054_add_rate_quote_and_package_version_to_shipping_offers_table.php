<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Direct-carrier rates become offers too — `postage-source-split/14`.
     *
     * A USPS, FedEx or UPS rate quoted from a carrier account used to cross the
     * Ship page as Livewire state and come back restated by the browser: price,
     * service and the metadata the adapter buys with. Now every rate the rate
     * service returns gets a row here, with `postage_source = CarrierAccount`
     * and no `purchase_context`, and the browser names the row. Three columns
     * make that row trustworthy as the price for *this* package:
     *
     * - `rate_quote_id` ties the offer to its `rate_quotes` row, so marking the
     *   selected quote is an update by primary key rather than a match on
     *   carrier and service code — which cannot tell two USPS variants of one
     *   mail class apart. The two tables stay separate on purpose: one is the
     *   long-retention analytics log, the other the short-lived transactional
     *   record, and folding them would put one retention policy on both.
     * - `package_updated_at` / `shipment_updated_at` record the package as it
     *   stood when quoted. The Ship page already keys its rate cache on this
     *   pair so that an edit re-quotes; the offer store checks the same pair
     *   at redemption so that a tab left open across an edit cannot buy a
     *   price quoted for a different parcel.
     */
    public function up(): void
    {
        Schema::table('shipping_offers', function (Blueprint $table) {
            $table->foreignId('rate_quote_id')
                ->nullable()
                ->after('postage_data_source_id')
                ->constrained('rate_quotes')
                ->nullOnDelete();

            $table->timestamp('package_updated_at')->nullable()->after('expires_at');
            $table->timestamp('shipment_updated_at')->nullable()->after('package_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('shipping_offers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rate_quote_id');
            $table->dropColumn(['package_updated_at', 'shipment_updated_at']);
        });
    }
};
