<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let the PII purge null the city, which it has always tried to.
     *
     * `shipments.city` is the only one of `PurgePiiCommand`'s PII fields that
     * landed NOT NULL, and the purge updates them all in one statement — so the
     * command threw an integrity violation on the first eligible shipment and
     * never reached the packages, whose `label_data` it is also supposed to null.
     * A purge that cannot run is not a retention policy, and the customs document
     * stored beside the label makes that worse rather than better: a commercial
     * invoice names both parties, their addresses and their tax IDs.
     *
     * Nullable at the database only. Nothing relaxes about what an import has to
     * supply — a shipment still arrives with a city, and the address validation
     * and carrier requests still require one. This describes a row whose recipient
     * has deliberately been forgotten.
     */
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('city')->nullable()->change();
        });
    }

    /**
     * Reverses the schema, and cannot reverse the purge.
     *
     * By the time anyone rolls this back, the repaired purge has run and the rows
     * it forgot have `city = NULL` — which a NOT NULL column has nowhere to put,
     * so the constraint has to be re-added against a backfill or the rollback
     * fails outright. The empty string is the least-claiming stand-in available:
     * not PII, not a place name, and not mistakable for one.
     *
     * Lossy on purpose. Rolling forward again cannot tell a city that was purged
     * from one that was backfilled here, and that is the honest cost of undoing a
     * migration whose whole point was to let data be destroyed.
     */
    public function down(): void
    {
        DB::table('shipments')->whereNull('city')->update(['city' => '']);

        Schema::table('shipments', function (Blueprint $table) {
            $table->string('city')->nullable(false)->change();
        });
    }
};
