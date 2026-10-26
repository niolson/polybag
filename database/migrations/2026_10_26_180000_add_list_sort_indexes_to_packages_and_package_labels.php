<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Packages list sorts by `created_at` and the Prepaid Duties report by
 * `package_labels.ship_date`, and neither column had an index. At 186,000 packages the
 * Packages list took 11 seconds, sorting every row's `label_data` to show 25
 * (`capacity-audit/07`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->index('created_at');
        });

        Schema::table('package_labels', function (Blueprint $table) {
            $table->index('ship_date');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });

        Schema::table('package_labels', function (Blueprint $table) {
            $table->dropIndex(['ship_date']);
        });
    }
};
