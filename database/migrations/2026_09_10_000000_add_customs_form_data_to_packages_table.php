<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Somewhere to keep the second document an international purchase returns.
     *
     * Carrier-neutral rather than Shopify-specific, because every source
     * PolyBag buys through already discards one: Amazon filters
     * `packageDocuments` to `LABEL`, FedEx reads `packageDocuments[0]` and never
     * reads `shipmentDocuments`, UPS never reads `ShipmentResults.Form.Image`
     * for the `InternationalForms` it sends, and Shopify kept only the URL.
     *
     * Beside `label_data` and shaped like it — a base64 document — so the same
     * three write sites cover both: `Package::markShipped()` stores it,
     * `clearShipping()` nulls it on a void, and `PurgePiiCommand` nulls it after
     * the retention period. A commercial invoice carries both parties' names,
     * addresses, tax IDs and EORI numbers, so leaving it behind when the label
     * is purged would quietly undo the purge.
     *
     * No format column. The one document that has been observed is PDF, and
     * `docs/issues/shopify-shipping-carrier/issues/23-*` is what establishes what
     * the other sources return; a format guessed ahead of that observation would
     * be a column nothing can be trusted to have filled in correctly.
     */
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->longText('customs_form_data')->nullable()->after('label_data');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('customs_form_data');
        });
    }
};
