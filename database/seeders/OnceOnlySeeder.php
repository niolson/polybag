<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Reference data written once, then left to the operator — ADR-0006 decision
 * 2, as amended 2026-09-25.
 *
 * The reference-data sync runs on every start, so anything it writes on every
 * run comes back after an Admin removes it. A batch here writes only while its
 * marker is absent from `settings`, and records the marker in the same
 * transaction. Once the marker is there the batch never writes again, even if
 * every row it wrote has since been removed.
 *
 * Not a migration: the entrypoint migrates before it syncs, so on a fresh
 * install a migration would find none of the catalog rows it maps.
 */
abstract class OnceOnlySeeder extends Seeder
{
    /** The prefix every batch's marker is stored under. */
    public const MARKER_PREFIX = 'reference_data.seeded.';

    /**
     * The batch's name, fixed once released: `shopify-mappings-v1`. A new batch
     * gets a new name rather than reusing one.
     */
    abstract protected function batch(): string;

    /**
     * Write the batch. Runs at most once per database.
     */
    abstract protected function seed(): void;

    public function run(): void
    {
        DB::transaction(function (): void {
            $marker = self::markerFor($this->batch());

            if (Setting::query()->whereKey($marker)->lockForUpdate()->exists()) {
                return;
            }

            $this->seed();

            Setting::create([
                'key' => $marker,
                'value' => now()->toIso8601String(),
                'type' => 'string',
                'group' => 'reference_data',
            ]);
        });
    }

    public static function markerFor(string $batch): string
    {
        return self::MARKER_PREFIX.$batch;
    }
}
