<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The `Shopify` carrier and its 22 catalog rows go — ADR-0006 decision 1,
     * `carrier-catalog-reset/09`. Shopify sells real catalog services through
     * the source mapping table, and `auto` is the method's source policy.
     *
     * Nothing is carried over: there are no production tenants. The method pivot
     * and the special-service scopes cascade. A shipping rule, label or package
     * still naming the carrier or one of its rows restricts the delete, and this
     * fails rather than guess what the rule meant: delete it and run again.
     *
     * Not reversible. The reference-data sync no longer seeds the rows, so there
     * is nothing to put back.
     */
    public function up(): void
    {
        $carrierId = DB::table('carriers')->where('name', 'Shopify')->value('id');

        if ($carrierId === null) {
            return;
        }

        DB::table('carrier_services')->where('carrier_id', $carrierId)->delete();
        DB::table('carriers')->where('id', $carrierId)->delete();
    }

    public function down(): void
    {
        //
    }
};
