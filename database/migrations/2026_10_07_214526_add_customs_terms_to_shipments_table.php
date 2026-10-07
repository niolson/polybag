<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The order's own customs terms — ADR-0008 decisions 2, 3 and 10,
     * `international-customs-terms/03`.
     *
     * Each is filled by an import or a manager's edit, and each is null until
     * then. `duties_terms` overrides the client's duties policy; a seller
     * registration replaces the client's; `recipient_tax_id` is a national ID
     * number, purged with the address by `shipments:purge-pii`.
     */
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            // A `DutiesTerms`.
            $table->string('duties_terms', 8)->nullable()->after('deliver_by');

            // A `TaxRegistrationRegime`, and its number.
            $table->string('seller_tax_regime', 16)->nullable()->after('duties_terms');
            $table->string('seller_tax_number', 20)->nullable()->after('seller_tax_regime');

            // A `RecipientTaxIdType`, and the ID.
            $table->string('recipient_tax_id_type', 16)->nullable()->after('seller_tax_number');
            $table->string('recipient_tax_id', 50)->nullable()->after('recipient_tax_id_type');

            // `X` followed by 14 digits, once EEI is filed in AESDirect.
            $table->string('export_itn', 15)->nullable()->after('recipient_tax_id');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropColumn([
                'duties_terms',
                'seller_tax_regime',
                'seller_tax_number',
                'recipient_tax_id_type',
                'recipient_tax_id',
                'export_itn',
            ]);
        });
    }
};
