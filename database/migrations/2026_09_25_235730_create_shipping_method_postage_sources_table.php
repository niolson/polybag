<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A shipping method's source policy — ADR-0006 decision 5, option O,
     * `carrier-catalog-reset/09`.
     *
     * One row per source kind the method allows. A row means the source may sell
     * for the method, and no row means it may not. `unlisted_services` says
     * whether it may sell beyond the method's listed services: Shopify's `auto`,
     * or Amazon Buy Shipping's *any service*.
     *
     * No existing method is given a row. There are no production tenants, and a
     * method made before this table has no `direct` row until someone adds one.
     */
    public function up(): void
    {
        Schema::create('shipping_method_postage_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipping_method_id')->constrained()->cascadeOnDelete();

            // A `PostageSourceKind`.
            $table->string('source_kind', 32);

            // An `UnlistedServices`, restricted per kind by the model.
            $table->string('unlisted_services', 16)->default('none');

            $table->timestamps();

            $table->unique(['shipping_method_id', 'source_kind'], 'shipping_method_postage_sources_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_method_postage_sources');
    }
};
