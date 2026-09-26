<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The shipping method's allowance replaces automation approval —
     * `carrier-catalog-reset/13`, ADR-0006 decisions 5, 8 and 12.
     *
     * What automation may buy is now decided by the method's postage-source
     * rows, narrowed by the connection's postage setting and by rules. Nothing
     * carries over: an approval named Amazon's identifiers per client and
     * environment, and the allowance is per method and names neither. There
     * are no production tenants.
     */
    public function up(): void
    {
        Schema::dropIfExists('service_approvals');
    }

    public function down(): void
    {
        //
    }
};
