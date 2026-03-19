<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only needed for MySQL — the create migration already has individual indexes.
        // SQLite (used in tests) creates indexes with different naming conventions.
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
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
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['auditable_type']);
            $table->dropIndex(['auditable_id']);
            $table->index(['auditable_type', 'auditable_id']);
        });
    }
};
