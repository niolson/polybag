<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The format of the customs document beside it.
     *
     * `customs_form_data` landed without one on purpose: the two documents
     * observed then, Shopify's and UPS's, were both PDF, and a format guessed
     * ahead of the next observation would be a column nothing could be
     * trusted to have filled in. Amazon changes that — it reports a format on
     * every package document it returns, and a `CUSTOM_FORM` can come back as
     * PDF, PNG or ZPL (`amazon-buy-shipping/13`). So the format is now carried
     * from the source that stated it, in the vocabulary `label_format` already
     * speaks, and defaults to PDF for the sources whose document is only ever
     * that.
     */
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->string('customs_form_format', 10)->default('pdf')->after('customs_form_data');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('customs_form_format');
        });
    }
};
