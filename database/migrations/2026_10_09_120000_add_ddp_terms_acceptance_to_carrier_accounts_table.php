<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An account's acceptance of USPS's prepaid-duties (DDP) terms, ADR-0008
     * decision 4 and `international-customs-terms/08`.
     *
     * Sending `prepayDutiesTaxesFees` is agreement to the provider's terms and
     * bills the account holder, so an Admin records the acceptance on the
     * account. Until then the account gets no USPS DDP rates.
     */
    public function up(): void
    {
        Schema::table('carrier_accounts', function (Blueprint $table): void {
            $table->timestamp('ddp_terms_accepted_at')->nullable();
            $table->foreignId('ddp_terms_accepted_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('carrier_accounts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ddp_terms_accepted_by');
            $table->dropColumn('ddp_terms_accepted_at');
        });
    }
};
