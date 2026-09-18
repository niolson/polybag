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
     * make that row trustworthy as the price for *this* package on *this*
     * account:
     *
     * - `rate_quote_id` ties the offer to its `rate_quotes` row, so marking the
     *   selected quote is an update by primary key rather than a match on
     *   carrier and service code — which cannot tell two USPS variants of one
     *   mail class apart. The two tables stay separate on purpose: one is the
     *   long-retention analytics log, the other the short-lived transactional
     *   record, and folding them would put one retention policy on both.
     * - `quote_fingerprint` is a digest of the rate request the price was
     *   quoted for (`RateRequest::fingerprint()`). The offer store recomputes
     *   it from the package at redemption and refuses on a mismatch, so a tab
     *   left open across an edit cannot buy a price quoted for a different
     *   parcel. A digest of the inputs rather than the parents' `updated_at`:
     *   neither `PackageItem` nor `ShipmentItem` touches its parent, so a
     *   quantity or declared-value edit would have moved nothing, while any
     *   parent save would have retired every offer.
     * - `carrier_account_fingerprint` is a digest of the account's billing
     *   identity (`CarrierAccount::fingerprint()`): the carrier and the
     *   non-secret credentials the adapters read at purchase. The same row
     *   with a different account number is a different payer. Secrets are
     *   left out so a refreshed token does not retire a quote.
     */
    public function up(): void
    {
        Schema::table('shipping_offers', function (Blueprint $table) {
            $table->foreignId('rate_quote_id')
                ->nullable()
                ->after('postage_data_source_id')
                ->constrained('rate_quotes')
                ->nullOnDelete();

            $table->string('quote_fingerprint', 64)->nullable()->after('expires_at');
            $table->string('carrier_account_fingerprint', 64)->nullable()->after('quote_fingerprint');
        });
    }

    public function down(): void
    {
        Schema::table('shipping_offers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rate_quote_id');
            $table->dropColumn(['quote_fingerprint', 'carrier_account_fingerprint']);
        });
    }
};
