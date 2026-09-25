<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Carrier names that `CarrierSeeder` has seeded before this migration.
     * Literals, so the migration never depends on a model's constants.
     */
    private const SEEDED_NAMES = ['USPS', 'FedEx', 'UPS', 'Shopify', 'Amazon'];

    /**
     * A carrier's name is its key — `carrier-catalog-reset/05`, ADR-0006
     * decision 1 as amended 2026-09-25.
     *
     * A seeded carrier is marked system, and its name is then fixed so the
     * registry, the seeders, alias matching and ship dates keep finding it.
     * What an operator wants to relabel goes in `display_name`. The name
     * becomes unique, so a duplicate left by the old rename-then-restart bug
     * stops the migration with the names to fix by hand.
     */
    public function up(): void
    {
        $duplicates = DB::table('carriers')
            ->select('name')
            ->groupBy('name')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('name');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'Carrier names must be unique before they become keys. Merge or rename these carriers first: '
                .$duplicates->implode(', '),
            );
        }

        Schema::table('carriers', function (Blueprint $table) {
            $table->string('display_name')->nullable()->after('name');
            $table->boolean('is_system')->default(false)->after('display_name');
            $table->unique('name');
        });

        DB::table('carriers')
            ->whereIn('name', self::SEEDED_NAMES)
            ->update(['is_system' => true]);
    }

    public function down(): void
    {
        Schema::table('carriers', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->dropColumn(['display_name', 'is_system']);
        });
    }
};
