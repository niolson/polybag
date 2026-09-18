<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the source was last asked about a spent offer and could not say —
     * `postage-source-split/18`.
     *
     * Now that USPS and UPS purchases can be asked about (reprint by
     * `X-Idempotency-Key`, Label Recovery by reference), a direct-carrier
     * offer left consumed with no reply is no longer settled on the next
     * attempt: it is asked about, and usually resolves — the label is
     * recovered, or the carrier is certain nothing was bought. The offers
     * that remain unresolved are then of two kinds: those nobody has retried
     * yet, which the next Ship attempt will ask about, and those that were
     * asked about and got no usable answer. Only the second is a real
     * unknown, and it is the one an operator should hear about; this stamp
     * is how the purge command tells them apart.
     */
    public function up(): void
    {
        Schema::table('shipping_offers', function (Blueprint $table) {
            $table->timestamp('recovery_unanswered_at')->nullable()->after('purchase_failure_reason');
        });
    }

    public function down(): void
    {
        Schema::table('shipping_offers', function (Blueprint $table) {
            $table->dropColumn('recovery_unanswered_at');
        });
    }
};
