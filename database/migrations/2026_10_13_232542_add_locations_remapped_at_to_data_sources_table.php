<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When one of the connection's locations was last mapped, unmapped or
     * ignored (`order-reconciliation/11`). That changes what an import keeps
     * without changing anything at the source, so a run that started no later
     * than this has not read with the current mapping, and the next run reads
     * every open record instead of searching for changes.
     */
    public function up(): void
    {
        Schema::table('data_sources', function (Blueprint $table): void {
            $table->timestamp('locations_remapped_at')->nullable()->after('import_account_key');
        });
    }

    public function down(): void
    {
        Schema::table('data_sources', function (Blueprint $table): void {
            $table->dropColumn('locations_remapped_at');
        });
    }
};
