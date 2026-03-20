<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The create migration was updated to use individual indexes.
        // This migration only applies to databases that were migrated
        // before that change (i.e., still have the composite index).
        $indexes = Schema::getIndexes('audit_logs');
        $hasComposite = collect($indexes)->contains(fn ($idx) => $idx['columns'] === ['auditable_type', 'auditable_id']);

        if (! $hasComposite) {
            return;
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['auditable_type', 'auditable_id']);
            $table->index('auditable_type');
            $table->index('auditable_id');
        });
    }

    public function down(): void
    {
        $indexes = Schema::getIndexes('audit_logs');
        $hasIndividualType = collect($indexes)->contains(fn ($idx) => $idx['columns'] === ['auditable_type']);

        if (! $hasIndividualType) {
            return;
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['auditable_type']);
            $table->dropIndex(['auditable_id']);
            $table->index(['auditable_type', 'auditable_id']);
        });
    }
};
