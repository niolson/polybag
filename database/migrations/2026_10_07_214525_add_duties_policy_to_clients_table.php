<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A client's duties policy — ADR-0008 decision 1,
     * `international-customs-terms/03`.
     *
     * A map from destination (`EU` or an ISO 3166 alpha-2 code) to `ddp` or
     * `ddu`. Null and empty both mean nothing has been chosen, which is where
     * every client starts: no default would be safe.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->json('duties_policy')->nullable()->after('label_reference_source');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('duties_policy');
        });
    }
};
