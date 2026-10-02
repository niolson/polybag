<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remember the Shopify fulfillment an export created for a Label.
     *
     * A void cancels that fulfillment so Shopify stops showing the dead
     * tracking number and reopens the order (`project-review/09`). It lives on
     * the Label row because the void deletes the Package's export rows, and the
     * fulfillment belongs to one Label, not to the Package's next one.
     *
     * Nothing is backfilled: earlier exports threw the fulfillment ID away.
     */
    public function up(): void
    {
        Schema::table('package_labels', function (Blueprint $table) {
            $table->string('shopify_fulfillment_id')->nullable()->after('source_label_reference');
        });
    }

    public function down(): void
    {
        Schema::table('package_labels', function (Blueprint $table) {
            $table->dropColumn('shopify_fulfillment_id');
        });
    }
};
