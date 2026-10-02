<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remember which manifest a Label was on, after the Label is voided.
     *
     * `packages.manifest_id` describes the active Label, and a void now clears
     * it so the re-shipped Label reaches the next SCAN form
     * (`project-review/07`). The Label row keeps it, the way it keeps the
     * other facts of a dead label (ADR-0004).
     *
     * Active labels are backfilled from their Package; a voided label's
     * manifest was never recorded and stays unknown.
     */
    public function up(): void
    {
        Schema::table('package_labels', function (Blueprint $table) {
            $table->foreignId('manifest_id')->nullable()->after('ship_date')->constrained()->nullOnDelete();
        });

        DB::table('package_labels')
            ->whereNull('voided_at')
            ->update([
                'manifest_id' => DB::table('packages')
                    ->whereColumn('packages.id', 'package_labels.package_id')
                    ->select('manifest_id'),
            ]);
    }

    public function down(): void
    {
        Schema::table('package_labels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manifest_id');
        });
    }
};
