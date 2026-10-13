<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How far an import has read its source's changes (`order-reconciliation/07`).
     * An Amazon import asks only for orders updated since `import_updated_through`,
     * open and closed alike, so one search both imports and closes; it reads
     * every open order again once `import_swept_at` is a day old, to retry rows
     * that failed to import.
     *
     * `import_reads` holds a search Amazon throttled partway: its query and the
     * pagination token to continue it with, good for 24 hours. The next run
     * carries on from there rather than starting over.
     *
     * `import_account_key` names the seller account and marketplace all three
     * were read from; a connection pointed at another starts over.
     */
    public function up(): void
    {
        Schema::table('data_sources', function (Blueprint $table): void {
            $table->timestamp('import_updated_through')->nullable()->after('schedule_interval');
            $table->timestamp('import_swept_at')->nullable()->after('import_updated_through');
            $table->json('import_reads')->nullable()->after('import_swept_at');
            $table->string('import_account_key', 64)->nullable()->after('import_reads');
        });
    }

    public function down(): void
    {
        Schema::table('data_sources', function (Blueprint $table): void {
            $table->dropColumn(['import_updated_through', 'import_swept_at', 'import_reads', 'import_account_key']);
        });
    }
};
