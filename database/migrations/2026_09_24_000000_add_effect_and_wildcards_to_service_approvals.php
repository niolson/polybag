<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Approvals by carrier or for everything, and exceptions to them —
     * `amazon-buy-shipping/18`.
     *
     * `external_carrier_id` and `external_service_id` now also accept `*`,
     * meaning every carrier or every service of one carrier. The sentinel is a
     * string rather than NULL on purpose: MySQL treats NULLs as distinct in a
     * unique index, so `service_approvals_scope_unique` would stop preventing
     * duplicate wildcard rows.
     *
     * `effect` says whether a row approves or excepts. A service is approved
     * when an `allow` row matches it and no `deny` row does. Every existing row
     * was an approval, so the default makes each one an `allow` without a data
     * migration.
     *
     * `effect` joins the unique index, so one scope can hold both an approval
     * and an exception — `ONTRAC/*` allowed and denied is legal and denied.
     */
    public function up(): void
    {
        Schema::table('service_approvals', function (Blueprint $table) {
            $table->string('effect', 8)->default('allow')->after('external_service_id');
        });

        Schema::table('service_approvals', function (Blueprint $table) {
            $table->dropUnique('service_approvals_scope_unique');

            $table->unique([
                'source',
                'environment',
                'external_carrier_id',
                'external_service_id',
                'effect',
                'client_id',
            ], 'service_approvals_scope_unique');
        });
    }

    public function down(): void
    {
        // Without the column every row reads as an approval, so an exception
        // left in place would turn into permission to spend. Wildcard allows
        // stay: to the old schema `*` is a service no source reports, and
        // matches nothing.
        DB::table('service_approvals')->where('effect', 'deny')->delete();

        Schema::table('service_approvals', function (Blueprint $table) {
            $table->dropUnique('service_approvals_scope_unique');
        });

        Schema::table('service_approvals', function (Blueprint $table) {
            $table->dropColumn('effect');

            $table->unique([
                'source',
                'environment',
                'external_carrier_id',
                'external_service_id',
                'client_id',
            ], 'service_approvals_scope_unique');
        });
    }
};
