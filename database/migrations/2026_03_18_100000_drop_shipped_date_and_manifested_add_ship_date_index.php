<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->index('ship_date');
        });

        $indexesToDrop = [
            'packages_status_date_cost_index',
            'packages_status_date_carrier_index',
            'packages_status_date_service_index',
            'packages_manifested_index',
        ];

        foreach ($indexesToDrop as $index) {
            try {
                Schema::table('packages', fn (Blueprint $table) => $table->dropIndex($index));
            } catch (\Throwable) {
                // Index may not exist
            }
        }

        if (Schema::hasColumn('packages', 'shipped_date')) {
            Schema::table('packages', function (Blueprint $table) {
                $table->dropColumn('shipped_date');
            });
        }

        if (Schema::hasColumn('packages', 'manifested')) {
            Schema::table('packages', function (Blueprint $table) {
                $table->dropColumn('manifested');
            });
        }
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropIndex(['ship_date']);
            $table->boolean('manifested')->default(false)->after('manifest_id');
        });
    }
};
